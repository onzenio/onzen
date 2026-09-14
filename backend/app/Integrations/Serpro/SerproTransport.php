<?php

namespace App\Integrations\Serpro;

use App\Models\Account;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SerproTransport
{
    public const TEMP_PREFIX = 'serpro-cert-';

    public function __construct(
        private readonly SerproCredentialResolver $credentials,
        private readonly SerproFixtureProvider $fixtures,
    ) {}

    /**
     * Executa uma chamada ao Integra Contador.
     *
     * Fail-closed: com dry-run ativo ou transporte não aprovado, resolve
     * via fixture sem nenhum tráfego HTTP.
     *
     * @param array<string, mixed> $envelope
     * @param array{idempotency_key?: string, procurador_token?: string, pfx_contents?: string, pfx_password?: string, environment?: string, platform_account?: Account} $options
     * @return array<string, mixed>
     */
    public function request(string $operation, array $envelope, array $options = []): array
    {
        $environment = $options['environment'] ?? (string) config('monitoring.environment', 'homologacao');
        $approved = (bool) config('monitoring.transport.approved', false);
        $dryRun = (bool) config('monitoring.dry_run', true);

        if ($dryRun || ! $approved) {
            $fixture = $this->fixtures->load($operation);

            return [...$fixture, 'transport' => 'dry-run'];
        }

        $platformAccount = $options['platform_account'] ?? null;

        if (! $platformAccount instanceof Account) {
            throw new SerproTransportException('Conta da plataforma não informada para chamada real.');
        }

        $token = $this->credentials->token($platformAccount, $environment);

        if ($token === null) {
            throw new SerproTransportException('Credencial do Contratante indisponível: chamada bloqueada.');
        }

        $pfxContents = $options['pfx_contents'] ?? null;

        if (! is_string($pfxContents) || $pfxContents === '') {
            throw new SerproTransportException('Certificado Digital indisponível: chamada bloqueada.');
        }

        $certPath = $this->writeTempCertificate($pfxContents);

        try {
            $headers = array_filter([
                'Role-Type' => (string) config('monitoring.transport.role_type', 'terceiro'),
                'X-Request-Tag' => $options['idempotency_key'] ?? null,
                'autenticar_procurador_token' => $options['procurador_token'] ?? null,
            ]);

            $response = Http::withToken($token)
                ->withHeaders($headers)
                ->withOptions([
                    'cert' => [$certPath, $options['pfx_password'] ?? ''],
                    'verify' => true,
                    'connect_timeout' => (int) config('monitoring.transport.connect_timeout', 5),
                    'timeout' => (int) config('monitoring.transport.request_timeout', 30),
                ])
                ->post(SerproOperationRouter::urlFor($operation, $environment), $envelope);

            if (in_array($response->status(), [401, 403], true)) {
                $this->credentials->clearToken($environment);

                throw new SerproTransportException("Autorização recusada ({$response->status()}): token descartado.");
            }

            if (! $response->successful()) {
                throw new SerproTransportException("Falha no transporte ({$response->status()}).");
            }

            $decoded = $response->json();

            if (! is_array($decoded)) {
                throw new SerproTransportException('Resposta inválida do transporte.');
            }

            return [...$decoded, 'transport' => 'live'];
        } catch (SerproTransportException $e) {
            Log::warning('serpro.transport_failed', ['operation' => $operation]);

            throw $e;
        } catch (\Throwable $e) {
            Log::warning('serpro.transport_error', ['operation' => $operation]);

            throw new SerproTransportException('Erro no transporte.', previous: $e);
        } finally {
            $this->removeTempCertificate($certPath);
        }
    }

    private function writeTempCertificate(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), self::TEMP_PREFIX);

        if ($path === false) {
            throw new SerproTransportException('Não foi possível preparar o certificado.');
        }

        chmod($path, 0600);
        file_put_contents($path, $contents);

        return $path;
    }

    private function removeTempCertificate(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
