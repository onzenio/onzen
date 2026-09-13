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

    /** @var list<array{status: int, body: array<string, mixed>, headers?: array<string, mixed>}> */
    public array $callResponses;

    /**
     * Optional hook invoked instead of shifting `$callResponses`, so tests can
     * mutate state in the middle of the wire call (e.g. fencing races).
     *
     * @var (callable(string, array<string, mixed>, string, array<string, mixed>): array{status: int, body: array<string, mixed>, headers?: array<string, mixed>})|null
     */
    public $onCall = null;

    /**
     * @param  list<array<string, mixed>>  $tokenResponses
     * @param  list<array{status: int, body: array<string, mixed>, headers?: array<string, mixed>}>  $callResponses
     */
    public function __construct(array $tokenResponses = [], array $callResponses = [])
    {
        $this->tokenResponses = $tokenResponses;
        $this->callResponses = $callResponses;
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

        if ($this->onCall !== null) {
            return ($this->onCall)($path, $envelope, $accessToken, $options);
        }

        return array_shift($this->callResponses) ?? ['status' => 200, 'body' => []];
    }
}
