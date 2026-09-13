<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('clients', [
            'id', 'account_id', 'cnpj', 'razao_social', 'regime',
            'contador_responsavel', 'monitoring_enabled',
        ]));
    }

    public function test_client_persists_with_monitoring_disabled_by_default(): void
    {
        $client = Client::factory()->create();

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'cnpj' => $client->cnpj,
            'monitoring_enabled' => false,
        ]);
        $this->assertFalse($client->refresh()->monitoring_enabled);
    }

    public function test_cnpj_is_unique_per_account(): void
    {
        $account = $this->createAccount();
        Client::factory()->for($account, 'account')->create(['cnpj' => '12345678000195']);

        $this->expectException(QueryException::class);

        Client::factory()->for($account, 'account')->create(['cnpj' => '12345678000195']);
    }

    public function test_same_cnpj_may_repeat_across_accounts(): void
    {
        $first = $this->createAccount();
        $second = $this->createAccount();

        $one = Client::factory()->for($first, 'account')->create(['cnpj' => '12345678000195']);
        $other = Client::factory()->for($second, 'account')->create(['cnpj' => '12345678000195']);

        $this->assertFalse($one->is($other));
        $this->assertTrue($one->account->is($first));
        $this->assertTrue($other->account->is($second));
    }
}
