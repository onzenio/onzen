<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreAccountCertificateRequest;
use App\Models\AccountCertificate;
use App\Models\User;
use App\Services\Monitoring\AccountCertificateService;
use App\Services\Monitoring\SerproAdminService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Certificado Digital (A1) da Account efetiva (singleton).
 *
 * Somente `admin`/`super_admin` gerenciam; `operator`/`user` recebem 403. O
 * payload é fino e mascarado: o thumbprint nunca aparece por inteiro e nenhum
 * PFX, senha ou ref de cofre é serializado. Sem certificado, a leitura retorna
 * o estado factual `absent` em vez de 404.
 */
class MonitoringCertificateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AccountCertificateService $service) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AccountCertificate::class);

        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload($this->service->current($actor))]);
    }

    public function store(StoreAccountCertificateRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $file = $request->file('certificate');
        $pfxBytes = $file !== null && $file->isValid() ? (string) file_get_contents($file->getRealPath()) : '';

        [$certificate, $created] = $this->service->store(
            $actor,
            $pfxBytes,
            $request->input('certificate_password'),
        );

        return response()->json(['data' => $this->payload($certificate)], $created ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->authorize('delete', AccountCertificate::class);

        /** @var User $actor */
        $actor = $request->user();

        $this->service->destroy($actor);

        return response()->json(['data' => ['state' => 'absent']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?AccountCertificate $certificate): array
    {
        if ($certificate === null) {
            return ['state' => 'absent'];
        }

        return [
            'state' => 'present',
            'holder_name' => (string) $certificate->holder_name,
            'thumbprint' => SerproAdminService::maskIdentifier((string) $certificate->thumbprint),
            'expires_at' => $certificate->expires_at?->toIso8601String(),
            'expired' => $certificate->isExpired(),
            'uploaded_at' => $certificate->created_at?->toIso8601String(),
        ];
    }
}
