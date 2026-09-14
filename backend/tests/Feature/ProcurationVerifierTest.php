<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PowerOfAttorney;
use App\Services\ProcurationChecker;
use App\Services\ProcurationVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurationVerifierTest extends TestCase
{
    use RefreshDatabase;

    private function verifierWith(bool $granted, ?object &$spy = null): ProcurationVerifier
    {
        $spy = new class($granted) implements ProcurationChecker
        {
            public int $calls = 0;

            public function __construct(private readonly bool $granted) {}

            public function granted(Client $client, string $serviceCode): bool
            {
                $this->calls++;

                return $this->granted;
            }
        };

        $this->app->instance(ProcurationChecker::class, $spy);

        return app(ProcurationVerifier::class);
    }

    public function test_verificacao_confirmada_habilita(): void
    {
        $client = Client::factory()->create();
        $verifier = $this->verifierWith(true);

        $this->assertSame(PowerOfAttorney::VALID, $verifier->verify($client, 'SITFIS-CONS'));
        $this->assertDatabaseHas('powers_of_attorney', [
            'client_id' => $client->id,
            'service_code' => 'SITFIS-CONS',
            'status' => PowerOfAttorney::VALID,
        ]);
    }

    public function test_ausencia_de_outorga_registra_missing(): void
    {
        $client = Client::factory()->create();
        $verifier = $this->verifierWith(false);

        $this->assertSame(PowerOfAttorney::MISSING, $verifier->verify($client, 'SITFIS-CONS'));
    }

    public function test_cache_evita_chamadas_repetidas(): void
    {
        $client = Client::factory()->create();
        $verifier = $this->verifierWith(true, $spy);

        $verifier->verify($client, 'SITFIS-CONS');
        $verifier->verify($client, 'SITFIS-CONS');

        $this->assertSame(1, $spy->calls);
    }

    public function test_codigo_fora_da_allowlist_sem_chamada_externa(): void
    {
        $client = Client::factory()->create();
        $verifier = $this->verifierWith(true, $spy);

        try {
            $verifier->verify($client, 'SERVICO-INEXISTENTE');
            $this->fail('Deveria recusar código fora da allowlist');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('allowlist', $e->getMessage());
        }

        $this->assertSame(0, $spy->calls);
        $this->assertDatabaseCount('powers_of_attorney', 0);
    }
}
