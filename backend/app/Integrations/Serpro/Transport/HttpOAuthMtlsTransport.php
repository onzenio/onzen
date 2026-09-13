<?php

namespace App\Integrations\Serpro\Transport;

use App\Contracts\SerproTransport;
use App\Exceptions\SerproBlockedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTPS transport for the Integra Contador runtime: OAuth
 * `client_credentials` over mTLS and envelope calls over Bearer.
 *
 * The PFX resolved from the vault (base64 material or a readable path) is
 * copied to a 0600 temporary file for a single token exchange and removed in
 * `finally`, on success and on failure. Credentials, PFX bytes, passwords and
 * tokens are never logged; connection failures bubble up untouched so callers
 * can classify transient errors, while HTTP responses keep the contract's
 * `{status, body, headers}` shape for any status code.
 */
final class HttpOAuthMtlsTransport implements SerproTransport
{
    public function token(array $credentials, string $environment): array
    {
        $eCnpj = trim((string) ($credentials['e_cnpj'] ?? ''));
        $consumerSecret = (string) ($credentials['consumer_secret'] ?? '');
        if ($eCnpj === '' || $consumerSecret === '') {
            throw new SerproBlockedException('serpro_credential_invalid');
        }

        [$certificatePath, $certificatePassword] = $this->certificateFile(
            $credentials['certificate'] ?? null,
            $credentials['certificate_password'] ?? null,
        );

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('monitoring.transport.timeout', 15))
                ->connectTimeout((int) config('monitoring.transport.connect_timeout', 5))
                ->withBasicAuth($eCnpj, $consumerSecret)
                ->withHeaders(['Role-Type' => (string) config('monitoring.transport.role_type', 'TERCEIROS')])
                ->withOptions([
                    'verify' => true,
                    'cert' => [$certificatePath, $certificatePassword],
                ])
                ->post((string) config('monitoring.token_url'), [
                    'grant_type' => 'client_credentials',
                    'client_id' => $eCnpj,
                    'client_secret' => $consumerSecret,
                ]);

            return $this->normalize($response);
        } finally {
            @unlink($certificatePath);
        }
    }

    public function call(string $path, array $envelope, string $accessToken, array $options = []): array
    {
        if (trim($accessToken) === '') {
            throw new SerproBlockedException('serpro_access_token_missing');
        }

        $headers = [];

        $requestTag = $options['idempotency_key'] ?? null;
        if (is_string($requestTag) && $requestTag !== '') {
            $headers['X-Request-Tag'] = $requestTag;
        }

        $procuradorToken = $options['procurador_token'] ?? null;
        if (is_string($procuradorToken) && $procuradorToken !== '') {
            $headers['autenticar_procurador_token'] = $procuradorToken;
        }

        $jwtToken = $options['jwt_token'] ?? null;
        if (is_string($jwtToken) && $jwtToken !== '') {
            $headers['jwt_token'] = $jwtToken;
        }

        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->withHeaders($headers)
            ->timeout((int) config('monitoring.transport.timeout', 15))
            ->connectTimeout((int) config('monitoring.transport.connect_timeout', 5))
            ->withOptions(['verify' => true])
            ->post($this->url($path), $envelope);

        return $this->normalize($response);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('monitoring.base_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @return array{status: int, body: array<string, mixed>, headers: array<string, mixed>}
     */
    private function normalize(Response $response): array
    {
        $body = $response->json();

        return [
            'status' => $response->status(),
            'body' => is_array($body) ? $body : [],
            'headers' => $response->headers(),
        ];
    }

    /**
     * Fail-closed certificate resolution: the PFX material must resolve to
     * non-empty bytes and the password must be present before a temp file is
     * touched. Returns the temp file path paired with the password for Guzzle's
     * `[path, password]` TLS option; the caller unlinks the file in `finally`.
     *
     * @return array{0: string, 1: string}
     */
    private function certificateFile(mixed $certificate, mixed $password): array
    {
        if (! is_string($certificate) || trim($certificate) === '') {
            throw new SerproBlockedException('serpro_certificate_missing');
        }

        if (! is_string($password) || $password === '') {
            throw new SerproBlockedException('serpro_certificate_password_missing');
        }

        $bytes = $this->certificateBytes($certificate);
        if ($bytes === '') {
            throw new SerproBlockedException('serpro_certificate_invalid');
        }

        $path = tempnam(sys_get_temp_dir(), 'onefisc-serpro-cert-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            if (is_string($path)) {
                @unlink($path);
            }

            throw new SerproBlockedException('serpro_certificate_unavailable');
        }

        @chmod($path, 0600);

        return [$path, $password];
    }

    private function certificateBytes(string $certificate): string
    {
        if (is_file($certificate)) {
            $contents = @file_get_contents($certificate);

            return $contents === false ? '' : $contents;
        }

        $normalized = preg_replace('/\s+/', '', $certificate);
        if ($normalized === null || $normalized === '') {
            return '';
        }

        $decoded = base64_decode($normalized, true);

        return $decoded === false ? '' : $decoded;
    }
}
