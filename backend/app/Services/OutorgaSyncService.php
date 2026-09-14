<?php

namespace App\Services;

use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\PowerOfAttorney;

class OutorgaSyncService
{
    public function __construct(private readonly ProcurationVerifier $verifier) {}

    /**
     * Sincroniza as associações do Client com a outorga verificada:
     * pausa por falta de outorga, retoma quando verificada.
     */
    public function syncClient(Client $client): void
    {
        $enrollments = MonitoringEnrollment::query()->withoutGlobalScopes()
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->whereIn('status', [MonitoringEnrollment::ACTIVE, MonitoringEnrollment::PAUSED])
            ->get();

        foreach ($enrollments as $enrollment) {
            $definition = MonitoringDefinition::query()->where('code', $enrollment->definition_code)->first();

            if ($definition === null) {
                continue;
            }

            $missing = false;

            foreach ($definition->required_services ?? [] as $serviceCode) {
                if ($this->verifier->verify($client, $serviceCode) !== PowerOfAttorney::VALID) {
                    $missing = true;
                }
            }

            if ($missing && $enrollment->status === MonitoringEnrollment::ACTIVE) {
                $enrollment->pause('outorga pendente');
            } elseif (! $missing && $enrollment->status === MonitoringEnrollment::PAUSED
                && $enrollment->pause_reason === 'outorga pendente') {
                $enrollment->resume();
            }
        }
    }

    /**
     * @return list<array{client_id: int, razao_social: string, cnpj: string, service_code: string, status: string}>
     */
    public function divergences(int $accountId): array
    {
        return PowerOfAttorney::query()->withoutGlobalScopes()
            ->with('client')
            ->where('account_id', $accountId)
            ->whereIn('status', [PowerOfAttorney::MISSING, PowerOfAttorney::EXPIRED, PowerOfAttorney::DIVERGENT])
            ->get()
            ->map(fn (PowerOfAttorney $p) => [
                'client_id' => $p->client_id,
                'razao_social' => $p->client->razao_social,
                'cnpj' => $p->client->cnpj,
                'service_code' => $p->service_code,
                'status' => $p->status,
            ])
            ->all();
    }
}
