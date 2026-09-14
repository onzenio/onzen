<?php

namespace Tests\Feature;

use App\Enums\AccountProfile;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\SerproContract;
use App\Models\SerproServiceRequest;
use App\Services\AccountCertificateService;
use App\Services\SerproRequestAuthorService;
use App\Services\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeCase(): array
    {
        $platform = $this->createAccount(['profile' => AccountProfile::A]);
        $vault = app(VaultService::class);

        SerproContract::factory()->create([
            'environment' => 'homologacao',
            'transport_approved' => true,
            'contractor_document' => '12345678000195',
            'consumer_key_ref' => $vault->put($platform, 'consumer-key', 'KEY'),
            'consumer_secret_ref' => $vault->put($platform, 'consumer-secret', 'SEC'),
        ]);

        $account = $this->createAccount(['profile' => AccountProfile::B]);
        $vault->put($account, 'pfx', 'PFX-FAKE');
        $vault->put($account, 'pfx-password', 'PWD');

        app(AccountCertificateService::class)->register($account, [
            'pfx_ref' => $vault->ref($account, 'pfx'),
            'password_ref' => $vault->ref($account, 'pfx-password'),
            'holder_name' => 'E',
            'thumbprint' => str_repeat('a', 40),
            'expires_at' => now()->addYear(),
        ]);
        app(SerproRequestAuthorService::class)->register($account, [
            'document' => '12345678901', 'name' => 'P',
        ]);

        $client = Client::factory()->for($account, 'account')->create();

        Config::set('monitoring.dry_run', false);

        return [$account, $client];
    }

    private function fakeTransport(array $actionPayload): void
    {
        Http::fake([
            config('monitoring.token_url').'*' => Http::response(['access_token' => 'TOKEN'], 200),
            '*' => Http::response($actionPayload, 200),
        ]);
    }

    public function test_emissao_confirmada_com_artefato(): void
    {
        [$account, $client] = $this->makeCase();
        $this->fakeTransport([
            'protocolo' => 'P1', 'situacao' => 'concluido', 'das' => base64_encode('%PDF-DAS'),
        ]);
        $operator = $this->createUser($account, ['role' => UserRole::Operator]);

        $response = $this->actingAs($operator)->postJson('/api/monitoring/actions/emissoes', [
            'client_id' => $client->id,
            'kind' => SerproServiceRequest::KIND_DAS_PGDASD,
            'idempotency_key' => 'EMIT-1',
            'confirmed' => true,
        ]);

        $response->assertStatus(202)->assertJsonPath('status', 'completed');
        $this->assertNotNull($response->json('artifact_ref'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'serpro_action.requested']);

        $sent = collect(Http::recorded())->filter(fn ($r) => ! str_contains($r[0]->url(), 'oauth'))->count();
        $this->assertSame(1, $sent);
    }

    public function test_repeticao_idempotente_sem_novo_trafego(): void
    {
        [$account, $client] = $this->makeCase();
        $this->fakeTransport(['protocolo' => 'P1', 'situacao' => 'concluido']);
        $operator = $this->createUser($account, ['role' => UserRole::Operator]);

        $payload = [
            'client_id' => $client->id,
            'kind' => SerproServiceRequest::KIND_DAS_PGDASD,
            'idempotency_key' => 'EMIT-1',
            'confirmed' => true,
        ];

        $first = $this->actingAs($operator)->postJson('/api/monitoring/actions/emissoes', $payload)->assertStatus(202);
        $second = $this->actingAs($operator)->postJson('/api/monitoring/actions/emissoes', $payload)->assertStatus(202);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, SerproServiceRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_recusa_sem_transporte_e_sem_confirmacao(): void
    {
        [$account, $client] = $this->makeCase();
        $this->fakeTransport(['protocolo' => 'P1', 'situacao' => 'concluido']);
        $operator = $this->createUser($account, ['role' => UserRole::Operator]);

        SerproContract::query()->firstOrFail()->forceFill(['transport_approved' => false])->save();

        $this->actingAs($operator)->postJson('/api/monitoring/actions/emissoes', [
            'client_id' => $client->id,
            'kind' => SerproServiceRequest::KIND_DAS_PGDASD,
            'idempotency_key' => 'EMIT-X',
            'confirmed' => true,
        ])->assertStatus(422);

        $this->actingAs($operator)->postJson('/api/monitoring/actions/emissoes', [
            'client_id' => $client->id,
            'kind' => SerproServiceRequest::KIND_DAS_PGDASD,
            'idempotency_key' => 'EMIT-Y',
            'confirmed' => false,
        ])->assertStatus(422);

        $this->assertSame(0, SerproServiceRequest::query()->withoutGlobalScopes()->count());
    }

    public function test_role_user_negado_e_poll_de_protocolo(): void
    {
        [$account, $client] = $this->makeCase();
        $actionCalls = 0;
        Http::fake(function ($request) use (&$actionCalls) {
            if (str_contains($request->url(), 'oauth/token')) {
                return Http::response(['access_token' => 'TOKEN'], 200);
            }
            $actionCalls++;

            return $actionCalls === 1
                ? Http::response(['protocolo' => 'P9', 'situacao' => 'pendente'], 200)
                : Http::response(['protocolo' => 'P9', 'situacao' => 'concluido', 'das' => base64_encode('%PDF')], 200);
        });

        $user = $this->createUser($account, ['role' => UserRole::User]);
        $this->actingAs($user)->postJson('/api/monitoring/actions/emissoes', [
            'client_id' => $client->id,
            'kind' => SerproServiceRequest::KIND_DAS_PGDASD,
            'idempotency_key' => 'EMIT-U',
            'confirmed' => true,
        ])->assertForbidden();

        $operator = $this->createUser($account, ['role' => UserRole::Operator]);
        $created = $this->actingAs($operator)->postJson('/api/monitoring/actions/emissoes', [
            'client_id' => $client->id,
            'kind' => SerproServiceRequest::KIND_DAS_PGDASD,
            'idempotency_key' => 'EMIT-P',
            'confirmed' => true,
        ])->assertStatus(202)->assertJsonPath('status', 'awaiting_protocol');

        $this->actingAs($operator)
            ->postJson('/api/monitoring/actions/requests/'.$created->json('id').'/poll')
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }
}
