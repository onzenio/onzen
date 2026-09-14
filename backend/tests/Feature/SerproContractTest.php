<?php

namespace Tests\Feature;

use App\Models\SerproContract;
use App\Services\SerproContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SerproContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('serpro_contracts', [
            'id', 'environment', 'consumer_key_ref', 'consumer_secret_ref', 'transport_approved',
        ]));
    }

    public function test_defaults_to_homologacao_with_transport_off(): void
    {
        $service = app(SerproContractService::class);
        $contract = $service->getOrCreate();

        $this->assertSame('homologacao', $contract->environment);
        $this->assertFalse($contract->transport_approved);
        $this->assertDatabaseHas('serpro_contracts', ['environment' => 'homologacao']);
    }

    public function test_masked_array_never_exposes_secret(): void
    {
        $contract = SerproContract::factory()->create([
            'consumer_key_ref' => 'secret:KEY123456',
            'consumer_secret_ref' => 'secret:SEC789012',
        ]);

        $masked = $contract->toMaskedArray();
        $json = json_encode($masked);

        $this->assertArrayNotHasKey('consumer_key_ref', $masked);
        $this->assertArrayNotHasKey('consumer_secret_ref', $masked);
        $this->assertStringNotContainsString('KEY123456', (string) $json);
        $this->assertStringNotContainsString('SEC789012', (string) $json);
        $this->assertSame('secret:***3456', $masked['consumer_key_masked']);
        $this->assertSame('secret:***9012', $masked['consumer_secret_masked']);
    }

    public function test_rotate_replaces_old_value_and_audits(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account);
        $service = app(SerproContractService::class);
        $contract = $service->getOrCreate();

        $service->rotateCredentials($contract, 'secret:NEWKEY1111', 'secret:NEWSEC2222', $actor);

        $this->assertDatabaseHas('serpro_contracts', [
            'id' => $contract->id,
            'consumer_key_ref' => 'secret:NEWKEY1111',
        ]);
        $this->assertDatabaseMissing('serpro_contracts', [
            'id' => $contract->id,
            'consumer_key_ref' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $actor->id,
            'action' => 'serpro_contract.credentials_rotated',
        ]);

        $audit = \App\Models\AuditLog::query()
            ->where('action', 'serpro_contract.credentials_rotated')
            ->firstOrFail();
        $meta = json_encode($audit->metadata);
        $this->assertStringNotContainsString('NEWKEY1111', $meta);
        $this->assertStringNotContainsString('NEWSEC2222', $meta);
    }
}
