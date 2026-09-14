<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SerproBlockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreSerproAuthorRequest;
use App\Models\SerproRequestAuthor;
use App\Models\User;
use App\Services\Monitoring\ProcuradorTermService;
use App\Services\Monitoring\SerproAdminService;
use App\Services\Monitoring\SerproRequestAuthorService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autores do Pedido de Dados da carteira (Task 20).
 *
 * Somente `admin`/`super_admin` gerenciam; `operator`/`user` recebem 403. O
 * payload é fino e mascarado: documento e thumbprint nunca aparecem por
 * inteiro e nenhum segredo, token ou material de cofre é serializado. A
 * assinatura/submissão do termo (Task 11) só roda sob demanda explícita,
 * nunca no cadastro.
 */
class MonitoringAuthorController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SerproRequestAuthorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SerproRequestAuthor::class);

        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $this->service->list($actor)
                ->map(fn (SerproRequestAuthor $author): array => $this->payload($author))
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreSerproAuthorRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = $request->validated();

        $author = $this->service->create($actor, (string) $data['document'], (string) $data['name']);

        return response()->json(['data' => $this->payload($author)], 201);
    }

    public function submitTerm(
        Request $request,
        SerproRequestAuthor $author,
        ProcuradorTermService $terms,
    ): JsonResponse {
        $this->authorize('update', $author);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $result = $terms->renewTerm($author, $actor);
        } catch (SerproBlockedException $exception) {
            return response()->json([
                'message' => 'Envio do termo recusado.',
                'error' => $exception->getMessage(),
            ], 409);
        }

        // The procurador token stays in the vault: the response carries only
        // its opaque author id and the factual expiry.
        return response()->json(['data' => [
            'id' => (int) $author->getKey(),
            'expires_at' => $result['expires_at']->toIso8601String(),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SerproRequestAuthor $author): array
    {
        return [
            'id' => (int) $author->getKey(),
            'name' => (string) $author->name,
            'document' => SerproAdminService::maskIdentifier((string) $author->document),
            'document_type' => $author->document_type->value,
            'status' => $author->status->value,
            'certificate_thumbprint' => SerproAdminService::maskIdentifier($author->certificate_thumbprint),
            'certificate_expires_at' => $author->certificate_expires_at?->toIso8601String(),
            'eligible' => $author->isEligible(),
            'created_at' => $author->created_at?->toIso8601String(),
            'updated_at' => $author->updated_at?->toIso8601String(),
        ];
    }
}
