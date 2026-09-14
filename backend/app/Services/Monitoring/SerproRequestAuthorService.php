<?php

namespace App\Services\Monitoring;

use App\Enums\AuthorDocumentType;
use App\Enums\AuthorStatus;
use App\Models\AccountCertificate;
use App\Models\SerproRequestAuthor;
use App\Models\User;
use App\Services\AuditService;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Gestão dos Autores do Pedido de Dados da Account (Task 20).
 *
 * O cadastro é fail-closed: sem Certificado Digital ativo da Account nada é
 * criado. O autor nasce vinculado ao certificado vigente (thumbprint e
 * validade herdados) e elegível. Nenhum termo é assinado ou enviado aqui —
 * a assinatura da Task 11 roda apenas sob demanda.
 */
final class SerproRequestAuthorService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return Collection<int, SerproRequestAuthor>
     */
    public function list(User $actor): Collection
    {
        return SerproRequestAuthor::query()
            ->where('account_id', $this->effectiveAccountId($actor))
            ->orderByDesc('id')
            ->get();
    }

    public function create(User $actor, string $document, string $name): SerproRequestAuthor
    {
        $accountId = $this->effectiveAccountId($actor);
        $certificate = $this->activeCertificate($accountId);
        $digits = (string) preg_replace('/\D/', '', $document);

        $author = SerproRequestAuthor::query()->create([
            'account_id' => $accountId,
            'document' => $digits,
            'document_type' => strlen($digits) > 11 ? AuthorDocumentType::Pj : AuthorDocumentType::Pf,
            'name' => trim($name),
            'status' => AuthorStatus::Active,
        ]);

        $author->useCertificate($certificate);

        $this->audit->record($actor, $certificate->account?->id, null, 'monitoring.author_created', [
            'author_id' => (int) $author->getKey(),
            'document' => SerproAdminService::maskIdentifier($digits),
        ]);

        return $author->refresh();
    }

    private function activeCertificate(int $accountId): AccountCertificate
    {
        $certificate = AccountCertificate::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->first();

        if ($certificate === null || $certificate->isExpired()) {
            throw ValidationException::withMessages([
                'certificate' => ['É necessário um Certificado Digital ativo para cadastrar um Autor do Pedido de Dados.'],
            ]);
        }

        return $certificate;
    }

    private function effectiveAccountId(User $actor): int
    {
        return (int) (CurrentAccount::get() ?? $actor->account_id);
    }
}
