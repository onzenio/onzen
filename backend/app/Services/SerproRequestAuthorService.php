<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\SerproRequestAuthor;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SerproRequestAuthorService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param array{document: string, name: string} $data
     */
    public function register(Account $account, array $data, ?User $actor = null): SerproRequestAuthor
    {
        $certificate = AccountCertificate::query()
            ->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->first();

        if ($certificate === null || $certificate->isExpired()) {
            throw ValidationException::withMessages([
                'certificate' => 'Conta sem Certificado Digital válido: autor inelegível.',
            ]);
        }

        $author = SerproRequestAuthor::query()->withoutGlobalScopes()->create([
            'account_id' => $account->id,
            'account_certificate_id' => $certificate->id,
            'document' => $data['document'],
            'name' => $data['name'],
            'status' => SerproRequestAuthor::STATUS_ACTIVE,
        ]);

        $this->audit->record($actor, $account->id, $account->id, 'serpro_author.registered', [
            'document' => $data['document'],
            'certificate_thumbprint' => $certificate->thumbprint,
        ]);

        return $author;
    }

    public function syncEligibility(Account $account): int
    {
        $certificate = AccountCertificate::query()
            ->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->first();

        $valid = $certificate !== null && ! $certificate->isExpired();

        if ($valid) {
            return 0;
        }

        return SerproRequestAuthor::query()
            ->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('status', SerproRequestAuthor::STATUS_ACTIVE)
            ->update(['status' => SerproRequestAuthor::STATUS_INELIGIBLE]);
    }
}
