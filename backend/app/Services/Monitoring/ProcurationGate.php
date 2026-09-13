<?php

namespace App\Services\Monitoring;

use App\Exceptions\SerproBlockedException;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\MonitoringRun;
use App\Services\AuditService;
use Carbon\CarbonInterface;

/**
 * Trava de procuração da execução (Task 20 / design decision 4).
 *
 * Antes de qualquer credencial, token, fixture ou transporte, confere a
 * outorga do Client para a definição da associação. Com o transporte fechado
 * (dry-run/fixtures) o gate não atua — sem chamada oficial não há evidência
 * de ausência, espelhando o legado.
 *
 * Uma verificação indisponível (certificado/autor/credencial/rede) bloqueia a
 * execução com o código factual, mas NÃO pausa a associação: indisponível não
 * é prova de ausência. Um negativo factual (a verificação respondeu sem
 * outorga) pausa a associação com motivo `outorga pendente` e devolve o código
 * factual para a execução terminar bloqueada. Um positivo retoma as
 * associações pausadas por outorga do Client cujos códigos exigidos já são
 * válidos no registro local — nunca as pausadas por outro motivo, nunca as de
 * outra Account e nunca as encerradas.
 *
 * As transições usam `lockForUpdate`/transação do próprio model
 * ({@see MonitoringEnrollment::pause()} / {@see MonitoringEnrollment::resume()}),
 * que também impedem a ressurreição de associações `ended`.
 */
final class ProcurationGate
{
    public const PAUSE_REASON = 'outorga pendente';

    public function __construct(
        private readonly ProcurationVerifier $verifier,
        private readonly SerproTransportGate $transport,
        private readonly AuditService $audit,
    ) {}

    /**
     * Executor gate: returns a factual block reason, or null when the run may
     * proceed. The definition is always handed to the verifier (fail-closed
     * carry-over from Task 12); without one the run blocks factually.
     */
    public function check(MonitoringEnrollment $enrollment, MonitoringRun $run): ?string
    {
        if ($this->transport->isGated()) {
            return null;
        }

        $definition = $enrollment->definition()->first();

        if ($definition === null) {
            return 'consult_definition_missing';
        }

        // The DB catalog is authoritative (design decision 5): definitions
        // flagged as not requiring procuração do not gate execution. The
        // definition is still resolved first and is always handed to the
        // verifier below when the requirement is on (Task 12 carry-over).
        if (! $definition->requires_procuracao) {
            return null;
        }

        $client = $this->clientFor($enrollment);

        if ($client === null) {
            return 'client_missing';
        }

        $result = $this->verifier->verify(
            $client,
            $definition,
            trim((string) $run->operation_code) !== '' ? (string) $run->operation_code : (string) $definition->getKey(),
        );

        if ($result['verified']) {
            $this->resumeForClient($client);

            return null;
        }

        $this->pauseForDefinition($client, $definition);

        return (string) ($result['reason'] ?? ProcurationVerifier::REASON_PENDING);
    }

    /**
     * On-demand verification used by the API: verifies and, factually,
     * pauses (negative) or resumes (positive) the Client's associations.
     * Fail-closed refusals from the verifier bubble as
     * {@see SerproBlockedException}.
     *
     * @return array{
     *     verified: bool,
     *     reason: string|null,
     *     required_groups: list<list<string>>,
     *     missing_groups: list<list<string>>,
     *     granted: array<string, string>,
     *     from_cache: bool
     * }
     */
    public function verifyForClient(
        Client $client,
        MonitoringDefinition $definition,
        ?string $operation = null,
        ?CarbonInterface $at = null,
    ): array {
        // Nothing to enforce for a definition that does not require
        // procuração: the check is a factual no-op, never a pause.
        if (! $definition->requires_procuracao) {
            return [
                'verified' => true,
                'reason' => null,
                'required_groups' => [],
                'missing_groups' => [],
                'granted' => [],
                'from_cache' => false,
            ];
        }

        $result = $this->verifier->verify($client, $definition, $operation, null, $at);

        if ($result['verified']) {
            $this->resumeForClient($client);
        } else {
            $this->pauseForDefinition($client, $definition);
        }

        return $result;
    }

    /**
     * Pause every active association of the Client for the definition with
     * the factual outorga reason. Idempotent; ended associations are never
     * touched by the model transition.
     */
    public function pauseForDefinition(Client $client, MonitoringDefinition $definition): int
    {
        $enrollments = MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->where('definition_id', $definition->getKey())
            ->where('status', MonitoringEnrollment::STATUS_ACTIVE)
            ->get();

        $paused = 0;

        foreach ($enrollments as $enrollment) {
            if ($enrollment->pause(self::PAUSE_REASON)) {
                $paused++;

                $this->audit($client, $enrollment, 'monitoring.enrollment_paused_by_outorga');
            }
        }

        return $paused;
    }

    /**
     * Resume the Client's paused-by-outorga associations whose required codes
     * are locally valid. Filters by the outorga pause reason so manual pauses
     * are never resumed; `resume()` refuses ended associations.
     */
    public function resumeForClient(Client $client): int
    {
        $enrollments = MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->where('status', MonitoringEnrollment::STATUS_PAUSED)
            ->where('pause_reason', self::PAUSE_REASON)
            ->with('definition')
            ->get();

        $resumed = 0;

        foreach ($enrollments as $enrollment) {
            if (! $this->locallySatisfied($client, $enrollment)) {
                continue;
            }

            if ($enrollment->resume()) {
                $resumed++;

                $this->audit($client, $enrollment, 'monitoring.enrollment_resumed_by_outorga');
            }
        }

        return $resumed;
    }

    /**
     * Fail-closed local check: the association may resume only when its
     * definition's required groups resolve and every group has a valid local
     * code. A requirement that cannot be resolved never resumes.
     */
    private function locallySatisfied(Client $client, MonitoringEnrollment $enrollment): bool
    {
        $definition = $enrollment->definition;

        if ($definition === null) {
            return false;
        }

        $groups = ProcurationVerifier::requiredGroups(
            $definition->procuration_codes,
            (string) $definition->getKey(),
            (string) $enrollment->definition_id,
        );

        if ($groups === []) {
            return ! $definition->requires_procuracao;
        }

        return $this->verifier->missingGroups($client, $groups) === [];
    }

    private function clientFor(MonitoringEnrollment $enrollment): ?Client
    {
        return Client::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $enrollment->account_id)
            ->whereKey($enrollment->client_id)
            ->first();
    }

    private function audit(Client $client, MonitoringEnrollment $enrollment, string $action): void
    {
        $this->audit->record(null, $action, [
            'enrollment_id' => (int) $enrollment->getKey(),
            'client_id' => (int) $client->getKey(),
            'reason' => self::PAUSE_REASON,
        ], $client->account);
    }
}
