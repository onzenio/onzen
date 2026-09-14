<?php

namespace App\Services;

use App\Integrations\Serpro\ProcuradorTermSender;
use App\Integrations\Serpro\SerproTermSigner;
use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproRequestAuthor;
use RuntimeException;

class ProcuradorTermService
{
    public const RENEW_WITHIN_DAYS = 7;

    public function __construct(
        private readonly VaultService $vault,
        private readonly SerproTermSigner $signer,
        private readonly ProcuradorTermSender $sender,
        private readonly AuditService $audit,
    ) {}

    /**
     * Garante token vigente para o autor: reutiliza o do cofre ou assina
     * e envia novo termo. O token nunca é exibido: retorna void.
     */
    public function ensureToken(Account $account, SerproRequestAuthor $author): void
    {
        if ($author->token_ref !== null && $author->token_expires_at !== null) {
            $current = $this->vault->get($author->token_ref);

            if ($current !== null && $author->token_expires_at->gt(now()->addDays(self::RENEW_WITHIN_DAYS))) {
                return;
            }
        }

        $certificate = AccountCertificate::query()
            ->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->first();

        if ($certificate === null || $certificate->isExpired()) {
            throw new RuntimeException('Autor inelegível: Certificado Digital ausente ou expirado.');
        }

        $pfx = $this->vault->get($certificate->pfx_ref);
        $password = $this->vault->get($certificate->password_ref);

        if ($pfx === null || $password === null) {
            throw new RuntimeException('Certificado Digital indisponível no cofre.');
        }

        $term = [
            'autor' => $author->document,
            'contratante' => (string) $account->id,
            'thumbprint' => $certificate->thumbprint,
            'validade' => now()->addYear()->toDateString(),
        ];

        $signed = [...$term, 'assinatura' => $this->signer->sign($this->signer->canonical($term), $pfx, $password)];

        $result = $this->sender->send($signed);

        $ref = $this->vault->put($account, "procurador-token-{$author->id}", $result['token']);

        $author->forceFill([
            'token_ref' => $ref,
            'token_expires_at' => $result['expires_at'],
        ])->save();

        $this->audit->record(null, $account->id, $account->id, 'serpro_author.token_renewed', [
            'author_id' => $author->id,
            'document' => $author->document,
        ]);
    }
}
