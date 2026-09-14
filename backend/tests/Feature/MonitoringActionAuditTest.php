<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\SerproServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringActionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_evento_registrado_com_redaction(): void
    {
        $account = $this->createAccount();
        $actor = $this->createUser($account, ['role' => UserRole::Admin]);
        $service = app(\App\Services\AuditService::class);

        $service->record($actor, $account->id, $account->id, 'serpro_action.requested', [
            'kind' => SerproServiceRequest::KIND_DAS_PGDASD,
            'client_id' => 1,
            'idempotency_key' => 'EMIT-1',
            'status' => 'completed',
        ]);

        $audit = \App\Models\AuditLog::query()->where('action', 'serpro_action.requested')->firstOrFail();
        $json = json_encode($audit->metadata);

        $this->assertStringContainsString('emitir-das-pgdasd', (string) $json);

        foreach (['token', 'pfx', 'secret', 'password', 'consumer'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) $json);
        }
    }

    public function test_escopo_de_carteira_404_fora_da_account(): void
    {
        Config::set('monitoring.dry_run', false);
        Http::fake([
            config('monitoring.token_url').'*' => Http::response(['access_token' => 'TOKEN'], 200),
            '*' => Http::response(['protocolo' => 'P1', 'situacao' => 'concluido'], 200),
        ]);

        $owner = $this->createAccount();
        $operator = $this->createUser($owner, ['role' => UserRole::Operator]);
        $stranger = $this->createUser($this->createAccount(), ['role' => UserRole::Operator]);

        $foreign = Client::factory()->create();

        // Emissão para Client de outra Account: 404, nada persistido.
        $this->actingAs($operator)->postJson('/api/monitoring/actions/emissoes', [
            'client_id' => $foreign->id,
            'kind' => SerproServiceRequest::KIND_DAS_PGDASD,
            'idempotency_key' => 'EMIT-F',
            'confirmed' => true,
        ])->assertNotFound();

        $this->assertSame(0, SerproServiceRequest::query()->withoutGlobalScopes()->count());

        // Poll de pedido de outra Account: 404.
        $mine = Client::factory()->for($owner, 'account')->create();
        $request = SerproServiceRequest::factory()->create([
            'account_id' => $owner->id,
            'client_id' => $mine->id,
            'protocol' => 'P1',
        ]);

        $this->actingAs($stranger)
            ->postJson('/api/monitoring/actions/requests/'.$request->id.'/poll')
            ->assertNotFound();
    }
}
