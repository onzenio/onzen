<?php

namespace App\Services\Monitoring;

use App\Contracts\VaultResolver;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproRequestAuthor;
use App\Models\User;
use App\Services\AuditService;
use App\Support\CurrentAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestão do Certificado Digital (A1) da Account.
 *
 * O certificado é um singleton por Account efetiva: o material (PFX + senha)
 * vive no cofre sob ref determinística e a linha guarda apenas titular,
 * thumbprint SHA-256 do DER e validade. Upload inválido é recusado com erro
 * de validação sem tocar o certificado vigente; a troca reinscreve os autores
 * e a remoção os torna inelegíveis, tudo auditado.
 */
final class AccountCertificateService
{
    public function __construct(
        private readonly VaultResolver $vault,
        private readonly AuditService $audit,
    ) {}

    public function current(User $actor): ?AccountCertificate
    {
        return AccountCertificate::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->effectiveAccountId($actor))
            ->first();
    }

    /**
     * Store or replace the Account certificate.
     *
     * @return array{0: AccountCertificate, 1: bool} certificate and whether it was created.
     */
    public function store(User $actor, string $pfxBytes, ?string $password): array
    {
        $password = (string) $password;
        $accountId = $this->effectiveAccountId($actor);
        $parsed = $this->inspect($pfxBytes, $password);

        $ref = self::vaultRef($accountId);
        $this->vault->put($ref, [
            'pfx_base64' => base64_encode($pfxBytes),
            'certificate_password' => $password,
        ]);

        $existing = $this->current($actor);

        if ($existing !== null) {
            $existing->replace([
                'vault_ref' => $ref,
                'holder_name' => $parsed['holder'],
                'thumbprint' => $parsed['thumbprint'],
                'expires_at' => $parsed['expires_at'],
                'uploaded_by_user_id' => $actor->getKey(),
            ], $actor);

            return [$existing->refresh(), false];
        }

        $certificate = AccountCertificate::query()->create([
            'account_id' => $accountId,
            'vault_ref' => $ref,
            'holder_name' => $parsed['holder'],
            'thumbprint' => $parsed['thumbprint'],
            'expires_at' => $parsed['expires_at'],
            'uploaded_by_user_id' => $actor->getKey(),
        ]);

        $this->audit->record($actor, 'account.certificate_stored', [
            'account_id' => $accountId,
            'thumbprint' => $parsed['thumbprint'],
            'expires_at' => $parsed['expires_at']->toIso8601String(),
        ], $certificate->account);

        return [$certificate->refresh(), true];
    }

    /**
     * Remove the Account certificate. A factual no-op (without audit) when
     * none exists. Linked authors lose their certificate linkage and become
     * ineligible in the same transaction.
     */
    public function destroy(User $actor): void
    {
        $certificate = $this->current($actor);

        if ($certificate === null) {
            return;
        }

        DB::transaction(function () use ($actor, $certificate): void {
            $account = $certificate->account;
            $metadata = [
                'account_id' => (int) $certificate->account_id,
                'previous_thumbprint' => $certificate->thumbprint,
                'previous_expires_at' => $certificate->expires_at?->toIso8601String(),
            ];

            $this->vault->forget($certificate->vault_ref);

            SerproRequestAuthor::query()
                ->withoutGlobalScope('account')
                ->where('account_id', $certificate->account_id)
                ->get()
                ->each(fn (SerproRequestAuthor $author) => $author->releaseCertificate());

            $certificate->delete();

            $this->audit->record($actor, 'account.certificate_removed', $metadata, $account);
        });
    }

    public static function vaultRef(int $accountId): string
    {
        return "secret:account-{$accountId}-certificate";
    }

    /**
     * A PFX file is a DER-encoded PFX PDU: a top-level SEQUENCE whose declared
     * length matches the file. Anything else cannot be a password problem.
     */
    private static function looksLikePfx(string $bytes): bool
    {
        $length = strlen($bytes);

        if ($length < 2 || $bytes[0] !== "\x30") {
            return false;
        }

        $first = ord($bytes[1]);

        if ($first < 0x80) {
            return 2 + $first === $length;
        }

        $octets = $first & 0x7F;

        if ($octets === 0 || $octets > 4 || 2 + $octets > $length) {
            return false;
        }

        $declared = 0;

        for ($i = 0; $i < $octets; $i++) {
            $declared = ($declared << 8) + ord($bytes[2 + $i]);
        }

        return 2 + $octets + $declared === $length;
    }

    /**
     * Inspect the PFX without persisting anything.
     *
     * A file that parses as a PFX container but does not open with the given
     * password is a wrong-password refusal; anything else unreadable is an
     * unreadable file. An already-expired certificate is stored with its
     * factual state — downstream execution stays fail-closed on `expired`.
     *
     * @return array{holder: string, thumbprint: string, expires_at: Carbon}
     */
    private function inspect(string $pfxBytes, string $password): array
    {
        $certs = [];

        if (! openssl_pkcs12_read($pfxBytes, $certs, $password)) {
            if ($password !== '' && self::looksLikePfx($pfxBytes)) {
                throw ValidationException::withMessages([
                    'certificate_password' => ['A senha não confere com o arquivo do certificado.'],
                ]);
            }

            throw ValidationException::withMessages([
                'certificate' => ['Não foi possível ler o arquivo do certificado. Envie um PFX/P12 válido.'],
            ]);
        }

        $pem = (string) ($certs['cert'] ?? '');

        if ($pem === '') {
            throw ValidationException::withMessages([
                'certificate' => ['Não foi possível ler o arquivo do certificado. Envie um PFX/P12 válido.'],
            ]);
        }

        $parsed = openssl_x509_parse($pem);
        $validTo = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

        if (! is_int($validTo)) {
            throw ValidationException::withMessages([
                'certificate' => ['Não foi possível ler a validade do certificado. Envie um PFX/P12 válido.'],
            ]);
        }

        $der = base64_decode((string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s/', '', $pem), true);

        if (! is_string($der) || $der === '') {
            throw ValidationException::withMessages([
                'certificate' => ['Não foi possível ler o arquivo do certificado. Envie um PFX/P12 válido.'],
            ]);
        }

        $subject = is_array($parsed) && isset($parsed['subject']) && is_array($parsed['subject'])
            ? $parsed['subject']
            : [];

        return [
            'holder' => (string) ($subject['CN'] ?? ''),
            'thumbprint' => hash('sha256', $der),
            'expires_at' => Carbon::createFromTimestamp($validTo),
        ];
    }

    private function effectiveAccountId(User $actor): int
    {
        return (int) (CurrentAccount::get() ?? $actor->account_id);
    }
}
