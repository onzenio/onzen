<?php

namespace App\Contracts;

/**
 * Wire seam of the Integra Contador runtime.
 *
 * Later tasks (executor, term service, procuration verification) depend only
 * on this contract; the HTTP/OAuth/mTLS implementation lands in Task 8. Tests
 * use an in-memory fake. Implementations never serialize or log credentials,
 * tokens or raw fiscal payloads.
 */
interface SerproTransport
{
    /**
     * Exchange the Contratante credentials for an OAuth access token.
     *
     * The array carries the resolved Contratante material only:
     * `e_cnpj` (OAuth Basic user / `client_id`), `consumer_secret`
     * (OAuth Basic password / `client_secret`) and, when the call needs
     * mTLS, the `certificate` (PFX bytes) and `certificate_password`.
     *
     * @param  array{
     *     e_cnpj: string,
     *     consumer_secret: string,
     *     certificate?: string|null,
     *     certificate_password?: string|null
     * }  $credentials
     * @return array{access_token?: string, expires_in?: int, jwt_token?: string, ...}
     */
    public function token(array $credentials, string $environment): array;

    /**
     * POST the official envelope to the catalog path for the environment.
     *
     * The access token goes as Bearer; `idempotency_key` maps to
     * `X-Request-Tag`; `procurador_token` to `autenticar_procurador_token`;
     * `jwt_token` to `jwt_token`. Implementations never log envelope
     * contents, tokens or fiscal payloads.
     *
     * @param  array<string, mixed>  $envelope  Three-party envelope built by SerproEnvelope.
     * @param  array{
     *     idempotency_key?: string,
     *     procurador_token?: string|null,
     *     jwt_token?: string|null,
     *     environment?: string
     * }  $options
     * @return array{status: int, body: array<string, mixed>, headers?: array<string, mixed>}
     */
    public function call(string $path, array $envelope, string $accessToken, array $options = []): array;
}
