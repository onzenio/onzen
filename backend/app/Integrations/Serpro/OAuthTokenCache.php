<?php

namespace App\Integrations\Serpro;

use App\Contracts\SerproTransport;
use App\Exceptions\SerproBlockedException;
use Illuminate\Support\Facades\Cache;

/**
 * Caches the OAuth access token (and the optional `jwt_token`) per
 * environment and credential ref, with TTL `expires_in - 30` seconds.
 *
 * A 401/403 from a downstream call means the cached token is no longer
 * accepted: callers clear it through {@see forgetIfUnauthorized()} before
 * retrying. Tokens live only in the cache and in the return of {@see get()};
 * they are never logged.
 */
final class OAuthTokenCache
{
    private const EXPIRY_SAFETY_MARGIN_SECONDS = 30;

    private const MINIMUM_TTL_SECONDS = 1;

    /** @var list<int> */
    private const UNAUTHORIZED_STATUSES = [401, 403];

    public function __construct(private readonly SerproTransport $transport) {}

    /**
     * @return array{access_token: string, jwt_token: string|null}
     */
    public function get(SerproCredentials $credentials, string $environment, string $credentialRef): array
    {
        $key = $this->cacheKey($environment, $credentialRef);
        $accessToken = Cache::get($key);

        if (is_string($accessToken) && $accessToken !== '') {
            return [
                'access_token' => $accessToken,
                'jwt_token' => $this->cachedJwt($key),
            ];
        }

        $payload = $this->transport->token($credentials->toTransportCredentials(), $this->normalizeEnvironment($environment));
        $accessToken = (string) ($payload['access_token'] ?? '');
        if ($accessToken === '') {
            throw new SerproBlockedException('serpro_oauth_failed');
        }

        $ttl = max(
            self::MINIMUM_TTL_SECONDS,
            (int) ($payload['expires_in'] ?? 300) - self::EXPIRY_SAFETY_MARGIN_SECONDS,
        );
        $expiresAt = now()->addSeconds($ttl);

        Cache::put($key, $accessToken, $expiresAt);

        $jwt = $payload['jwt_token'] ?? null;
        if (is_string($jwt) && $jwt !== '') {
            Cache::put($key.':jwt', $jwt, $expiresAt);
        } else {
            Cache::forget($key.':jwt');
            $jwt = null;
        }

        return ['access_token' => $accessToken, 'jwt_token' => is_string($jwt) ? $jwt : null];
    }

    public function forget(string $environment, string $credentialRef): void
    {
        $key = $this->cacheKey($environment, $credentialRef);

        Cache::forget($key);
        Cache::forget($key.':jwt');
    }

    /**
     * Clear the cached tokens when the transport reported 401/403.
     *
     * @return bool whether the cache was cleared.
     */
    public function forgetIfUnauthorized(int $status, string $environment, string $credentialRef): bool
    {
        if (! in_array($status, self::UNAUTHORIZED_STATUSES, true)) {
            return false;
        }

        $this->forget($environment, $credentialRef);

        return true;
    }

    private function cachedJwt(string $key): ?string
    {
        $jwt = Cache::get($key.':jwt');

        return is_string($jwt) && $jwt !== '' ? $jwt : null;
    }

    private function cacheKey(string $environment, string $credentialRef): string
    {
        $version = (string) config('monitoring.credential_version', '1');

        return 'serpro:oauth:'.hash('sha256', $this->normalizeEnvironment($environment).'|'.$credentialRef.'|'.$version);
    }

    private function normalizeEnvironment(string $environment): string
    {
        return strtolower(trim($environment));
    }
}
