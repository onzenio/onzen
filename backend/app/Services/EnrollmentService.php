<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class EnrollmentService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(Account $account, int $clientId, string $definitionCode, ?User $actor = null): MonitoringEnrollment
    {
        $client = Client::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->whereKey($clientId)
            ->first();

        if ($client === null) {
            throw (new ModelNotFoundException)->setModel(Client::class, $clientId);
        }

        if (! $client->monitoring_enabled) {
            throw ValidationException::withMessages([
                'client' => 'Client com Monitoring Status inativo não pode ser associado.',
            ]);
        }

        $definition = MonitoringDefinition::query()->where('code', $definitionCode)->first();

        if ($definition === null || ! $definition->isAvailable()) {
            throw ValidationException::withMessages([
                'definition' => 'Definição indisponível para novas associações.',
            ]);
        }

        if (! empty($definition->regimes) && ! in_array($client->regime, $definition->regimes, true)) {
            throw ValidationException::withMessages([
                'client' => 'Regime do Client inelegível para esta definição.',
            ]);
        }

        $exists = MonitoringEnrollment::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('client_id', $client->id)
            ->where('definition_code', $definitionCode)
            ->whereIn('status', [MonitoringEnrollment::ACTIVE, MonitoringEnrollment::PAUSED])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'definition' => 'Já existe associação vigente para este Client e definição.',
            ]);
        }

        $this->assertPlanLimit($account, $client);

        $enrollment = MonitoringEnrollment::query()->withoutGlobalScopes()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'definition_code' => $definitionCode,
            'status' => MonitoringEnrollment::ACTIVE,
            'version' => 1,
        ]);

        $this->audit->record($actor, $account->id, $account->id, 'monitoring_enrollment.created', [
            'client_id' => $client->id,
            'definition' => $definitionCode,
        ]);

        return $enrollment;
    }

    public function search(Account $account, ?string $query = null, int $perPage = 15): LengthAwarePaginator
    {
        $q = MonitoringEnrollment::query()->withoutGlobalScopes()
            ->with('client')
            ->where('account_id', $account->id)
            ->latest();

        if ($query !== null && $query !== '') {
            $digits = preg_replace('/\D/', '', $query);
            $q->whereHas('client', function ($c) use ($query, $digits): void {
                $c->where('razao_social', 'like', "%{$query}%");

                if ($digits !== '') {
                    $c->orWhere('cnpj', 'like', "%{$digits}%");
                }
            });
        }

        return $q->paginate($perPage);
    }

    private function assertPlanLimit(Account $account, Client $client): void
    {
        $limit = (int) ($account->plan?->max_clients ?? 0);

        $covered = MonitoringEnrollment::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('status', MonitoringEnrollment::ACTIVE)
            ->distinct()
            ->count('client_id');

        $alreadyCovered = MonitoringEnrollment::query()->withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('client_id', $client->id)
            ->where('status', MonitoringEnrollment::ACTIVE)
            ->exists();

        if (! $alreadyCovered && $covered >= $limit) {
            throw ValidationException::withMessages([
                'plan' => 'Limite de Clients monitorados do Plan atingido. Troque de Plan para ampliar.',
            ]);
        }
    }
}
