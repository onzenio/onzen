<?php

namespace App\Integrations\Serpro;

use App\Models\Account;
use App\Models\SerproContract;
use App\Services\VaultService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SerproCredentialResolver
{
    public function __construct(private readonly VaultService $vault) {}

    public static function cacheKey(string $environment): string
    {
        return "serpro:token:{$environment}";
    }

    /**
     * Resolve o consumer do Contratante no escopo da Account da plataforma.
     *
     * @return array{key: string, secret: string}|null
     */
    public function resolveConsumer(Account $platformAccount, ?string $environment = null): ?array
    {
        $environment ??= (string) config('monitoring.environment', 'homologacao');

        $contract = SerproContract::query()->where('environment', $environment)->first();

        if ($contract === null || $contract->consumer_key_ref === null || $contract->consumer_secret_ref === null) {
            return null;
        }

        // Escopo: refs do cofre pertencem à Account da plataforma.
        $key = $this->vault->get($this->scopedRef($platformAccount, $contract->consumer_key_ref));
        $secret = $this->vault->get($this->scopedRef($platformAccount, $contract->consumer_secret_ref));

        if ($key === null || $secret === null) {
            return null;
        }

        return ['key' => $key, 'secret' => $secret];
    }

    public function token(Account $platformAccount, ?string $environment = null): ?string
    {
        $environment ??= (string) config('monitoring.environment', 'homologacao');

        $cached = Cache::get(self::cacheKey($environment));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $consumer = $this->resolveConsumer($platformAccount, $environment);

        if ($consumer === null) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($consumer['key'], $consumer['secret'])
                ->post((string) config('monitoring.token_url'), ['grant_type' => 'client_credentials']);

            if (! $response->successful()) {
                Log::warning('serpro.token_failed', ['environment' => $environment, 'status' => $response->status()]);

                return null;
            }

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                return null;
            }

            Cache::put(self::cacheKey($environment), $token, now()->addMinutes(50));

            return $token;
        } catch (\Throwable $e) {
            Log::warning('serpro.token_error', ['environment' => $environment]);

            return null;
        }
    }

    public function clearToken(?string $environment = null): void
    {
        $environment ??= (string) config('monitoring.environment', 'homologacao');

        Cache::forget(self::cacheKey($environment));
    }

    /**
     * Refs legadas `secret:valor` (sem conta) são interpretadas no escopo
     * da Account da plataforma; refs `secret:{id}:{nome}` valem como estão.
     */
    private function scopedRef(Account $platformAccount, string $ref): string
    {
        if ($this->vault->parse($ref) !== null) {
            return $ref;
        }

        return $this->vault->ref($platformAccount, $ref);
    }
}
