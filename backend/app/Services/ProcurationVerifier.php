<?php

namespace App\Services;

use App\Models\Client;
use App\Models\PowerOfAttorney;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class ProcurationVerifier
{
    public const CACHE_MINUTES = 15;

    public function __construct(
        private readonly ProcurationChecker $checker,
        private readonly MonitoringCatalogService $catalog,
    ) {}

    public static function cacheKey(Client $client, string $serviceCode): string
    {
        return "procuration:{$client->account_id}:{$client->id}:{$serviceCode}";
    }

    /**
     * Verifica a outorga com cache curto. Código fora da allowlist é
     * recusado sem nenhuma chamada externa.
     */
    public function verify(Client $client, string $serviceCode): string
    {
        if (! in_array($serviceCode, $this->catalog->procurationAllowlist(), true)) {
            throw new InvalidArgumentException("Código de serviço fora da allowlist: {$serviceCode}.");
        }

        $granted = Cache::remember(
            self::cacheKey($client, $serviceCode),
            now()->addMinutes(self::CACHE_MINUTES),
            fn () => $this->checker->granted($client, $serviceCode),
        );

        $status = $granted ? PowerOfAttorney::VALID : PowerOfAttorney::MISSING;

        PowerOfAttorney::query()->withoutGlobalScopes()->updateOrCreate(
            [
                'account_id' => $client->account_id,
                'client_id' => $client->id,
                'service_code' => $serviceCode,
            ],
            ['status' => $status, 'verified_at' => now()],
        );

        return $status;
    }
}
