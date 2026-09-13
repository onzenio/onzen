<?php

namespace App\Services\Monitoring;

use App\Integrations\Serpro\ConsultOperationResolver;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Enrollment lifecycle: fail-closed eligibility, the single active
 * association per (Account, Client, definition) and version fencing.
 *
 * No execution happens here — scheduling runs is a later concern.
 */
final class MonitoringEnrollmentService
{
    public function __construct(private readonly ConsultOperationResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, MonitoringEnrollment>
     */
    public function paginate(User $actor, array $filters = []): LengthAwarePaginator
    {
        $query = MonitoringEnrollment::query()
            ->with(['client', 'definition'])
            ->where('account_id', $this->effectiveAccountId($actor));

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $digits = (string) preg_replace('/\D+/', '', $search);
            $query->whereHas('client', function (Builder $client) use ($like, $digits): void {
                $client->where(function (Builder $inner) use ($like, $digits): void {
                    $inner->whereRaw('LOWER(razao_social) LIKE ?', [$like]);
                    if ($digits !== '') {
                        $inner->orWhere('cnpj', 'like', '%'.$digits.'%');
                    }
                });
            });
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '') {
            $query->where('status', $status);
        }

        $definitionId = (string) ($filters['definition_id'] ?? '');
        if ($definitionId !== '') {
            $query->where('definition_id', $definitionId);
        }

        return $query->latest('id')->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): MonitoringEnrollment
    {
        $accountId = $this->effectiveAccountId($actor);
        $client = $this->resolveClient($accountId, (int) $attributes['client_id']);
        $this->assertMonitoringEnabled($client);

        $definition = $this->resolveDefinition((string) $attributes['definition_id']);
        $this->assertMetadataEligible($client, $definition);

        $existing = MonitoringEnrollment::query()
            ->where('account_id', $accountId)
            ->where('client_id', $client->id)
            ->where('definition_id', $definition->id)
            ->first();

        if ($existing !== null && $existing->status !== MonitoringEnrollment::STATUS_ENDED) {
            throw ValidationException::withMessages([
                'definition_id' => ['Já existe associação de monitoramento para este Client e definição.'],
            ]);
        }

        $configuration = $attributes['configuration'] ?? null;

        if ($existing !== null) {
            // Reactivation reuses the ended row so runs/snapshots keep their
            // history; the version restarts at 1, fencing every stale run.
            $existing->forceFill([
                'status' => MonitoringEnrollment::STATUS_ACTIVE,
                'pause_reason' => null,
                'version' => 1,
                'configuration' => $configuration,
                'last_change_at' => now(),
            ])->save();

            return $existing->load(['client', 'definition']);
        }

        $enrollment = $this->createEnrollment($accountId, $client, $definition, $configuration);

        return $enrollment->load(['client', 'definition']);
    }

    /**
     * @param  array<string, mixed>|null  $configuration
     */
    private function createEnrollment(
        int $accountId,
        Client $client,
        MonitoringDefinition $definition,
        ?array $configuration,
    ): MonitoringEnrollment {
        try {
            return MonitoringEnrollment::query()->create([
                'account_id' => $accountId,
                'client_id' => $client->id,
                'definition_id' => $definition->id,
                'status' => MonitoringEnrollment::STATUS_ACTIVE,
                'pause_reason' => null,
                'version' => 1,
                'configuration' => $configuration,
                'last_change_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two concurrent creates: the unique triple wins over the
            // pre-check, and the loser must see the same validation error.
            throw ValidationException::withMessages([
                'definition_id' => ['Já existe associação de monitoramento para este Client e definição.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $configuration
     */
    public function updateConfiguration(MonitoringEnrollment $enrollment, ?array $configuration): MonitoringEnrollment
    {
        $enrollment->forceFill([
            'configuration' => $configuration,
            'version' => $enrollment->version + 1,
            'last_change_at' => now(),
        ])->save();

        return $enrollment->refresh()->load(['client', 'definition']);
    }

    public function end(MonitoringEnrollment $enrollment): MonitoringEnrollment
    {
        $enrollment->end();

        return $enrollment->refresh()->load(['client', 'definition']);
    }

    private function effectiveAccountId(User $actor): int
    {
        return (int) (CurrentAccount::get() ?? $actor->account_id);
    }

    private function resolveClient(int $accountId, int $clientId): Client
    {
        return Client::query()
            ->where('account_id', $accountId)
            ->whereKey($clientId)
            ->firstOrFail();
    }

    private function assertMonitoringEnabled(Client $client): void
    {
        if (! $client->monitoring_enabled) {
            throw ValidationException::withMessages([
                'client_id' => ['Client com Monitoring Status inativo.'],
            ]);
        }
    }

    private function resolveDefinition(string $definitionId): MonitoringDefinition
    {
        $definition = MonitoringDefinition::query()->find($definitionId);

        if ($definition === null || ! $definition->isAvailable()) {
            throw ValidationException::withMessages([
                'definition_id' => ['Definição indisponível no catálogo vigente.'],
            ]);
        }

        try {
            $this->resolver->resolve($definition);
        } catch (\DomainException|\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'definition_id' => ['Definição sem operação de consulta executável.'],
            ]);
        }

        return $definition;
    }

    private function assertMetadataEligible(Client $client, MonitoringDefinition $definition): void
    {
        $personTypes = $definition->person_types;
        if (is_array($personTypes) && $personTypes !== []) {
            $allowed = array_map(fn ($type): string => strtoupper(trim((string) $type)), $personTypes);

            // A Client is a CNPJ, so the only compatible person type is PJ.
            if (! in_array('PJ', $allowed, true)) {
                throw ValidationException::withMessages([
                    'definition_id' => ['Definição não se aplica ao tipo de pessoa do Client.'],
                ]);
            }
        }

        $regimes = $definition->regimes;
        if (is_array($regimes) && $regimes !== [] && ! $this->regimeIsEligible((string) $client->regime, $regimes)) {
            throw ValidationException::withMessages([
                'definition_id' => ['Definição não se aplica ao regime do Client.'],
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $definitionRegimes
     */
    private function regimeIsEligible(string $clientRegime, array $definitionRegimes): bool
    {
        $client = $this->normalizeRegime($clientRegime);

        foreach ($definitionRegimes as $regime) {
            if ($this->normalizeRegime((string) $regime) === $client) {
                return true;
            }
        }

        return false;
    }

    private function normalizeRegime(string $value): string
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($value))), '_');

        return match ($normalized) {
            'simples', 'simples_nacional' => 'simples',
            default => $normalized,
        };
    }
}
