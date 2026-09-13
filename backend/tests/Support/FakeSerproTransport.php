<?php

namespace Tests\Support;

use App\Contracts\SerproTransport;

/**
 * In-memory SerproTransport double: records token/call invocations and
 * serves queued token payloads. Tests assert cache behavior and the exact
 * envelope handed to the wire without any HTTP.
 */
final class FakeSerproTransport implements SerproTransport
{
    /** @var list<array<string, mixed>> */
    public array $tokenResponses;

    /** @var list<array{credentials: array<string, mixed>, environment: string}> */
    public array $tokenCalls = [];

    /** @var list<array{path: string, envelope: array<string, mixed>, access_token: string, options: array<string, mixed>}> */
    public array $callCalls = [];

    /**
     * @param  list<array<string, mixed>>  $tokenResponses
     */
    public function __construct(array $tokenResponses = [])
    {
        $this->tokenResponses = $tokenResponses;
    }

    public function token(array $credentials, string $environment): array
    {
        $this->tokenCalls[] = ['credentials' => $credentials, 'environment' => $environment];

        return array_shift($this->tokenResponses)
            ?? ['access_token' => 'fake-access-token', 'expires_in' => 300];
    }

    public function call(string $path, array $envelope, string $accessToken, array $options = []): array
    {
        $this->callCalls[] = [
            'path' => $path,
            'envelope' => $envelope,
            'access_token' => $accessToken,
            'options' => $options,
        ];

        return ['status' => 200, 'body' => []];
    }
}
