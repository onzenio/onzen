<?php

namespace Tests\Feature;

use App\Models\SerproContract;
use App\Support\CurrentAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SerproContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_serpro_contracts_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('serpro_contracts', [
            'id', 'environment', 'credential_ref', 'updated_by_user_id',
            'created_at', 'updated_at',
        ]));
    }

    public function test_contract_persists_with_nullable_credential_ref_and_updater(): void
    {
        $updater = $this->createUser();

        $contract = SerproContract::factory()->create([
            'environment' => 'homologacao',
            'updated_by_user_id' => $updater->id,
        ]);

        $this->assertDatabaseHas('serpro_contracts', [
            'id' => $contract->id,
            'environment' => 'homologacao',
            'credential_ref' => null,
        ]);
        $this->assertNull($contract->refresh()->credential_ref);
        $this->assertTrue($contract->updatedBy->is($updater));
    }

    public function test_environment_is_unique_across_platform_contracts(): void
    {
        SerproContract::factory()->create(['environment' => 'producao']);

        $this->expectException(QueryException::class);

        SerproContract::factory()->create(['environment' => 'producao']);
    }

    public function test_contracts_are_platform_level_and_ignore_current_account_scope(): void
    {
        SerproContract::factory()->create(['environment' => 'homologacao']);

        CurrentAccount::set($this->createAccount()->id);

        $this->assertCount(1, SerproContract::all());
        $this->assertSame('homologacao', SerproContract::first()->environment);
    }

    public function test_credential_ref_is_masked_and_hidden_from_serialization(): void
    {
        $contract = SerproContract::factory()->create([
            'credential_ref' => 'secret:contratante-consumer-abcdef-1234',
        ]);

        $masked = $contract->maskedCredentialRef();

        $this->assertIsString($masked);
        $this->assertStringStartsWith('secret:', $masked);
        $this->assertStringEndsWith('1234', $masked);
        $this->assertStringNotContainsString('contratante', $masked);
        $this->assertStringNotContainsString('abcdef', $masked);

        $serialized = $contract->toArray();
        $this->assertArrayNotHasKey('credential_ref', $serialized);
        $this->assertStringNotContainsString(
            'contratante-consumer-abcdef-1234',
            (string) json_encode($serialized)
        );
    }

    public function test_null_credential_ref_masks_to_null(): void
    {
        $contract = SerproContract::factory()->create(['credential_ref' => null]);

        $this->assertNull($contract->maskedCredentialRef());
    }
}
