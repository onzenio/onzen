<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Client;
use App\Models\MonitoringArtifact;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\User;
use App\Services\Monitoring\MonitoringEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MonitoringEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrollments_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('monitoring_enrollments', [
            'id', 'account_id', 'client_id', 'definition_id', 'status', 'pause_reason',
            'version', 'configuration', 'last_change_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/monitoring/enrollments')->assertUnauthorized();
        $this->postJson('/api/monitoring/enrollments', [])->assertUnauthorized();
    }

    public function test_admin_and_operator_associate_an_eligible_client(): void
    {
        foreach ([UserRole::Admin, UserRole::Operator] as $role) {
            $account = $this->createAccount();
            $actor = $this->actor($account, $role);
            $client = $this->entitledClient($account);
            $definition = $this->eligibleDefinition();

            $response = $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
                'client_id' => $client->id,
                'definition_id' => $definition->id,
            ]);

            $response->assertCreated()
                ->assertJsonPath('data.status', 'active')
                ->assertJsonPath('data.version', 1)
                ->assertJsonPath('data.pause_reason', null)
                ->assertJsonPath('data.client.id', $client->id)
                ->assertJsonPath('data.client.cnpj', $client->cnpj)
                ->assertJsonPath('data.definition.id', $definition->id)
                ->assertJsonPath('data.definition.name', $definition->name);

            $this->assertDatabaseHas('monitoring_enrollments', [
                'account_id' => $account->id,
                'client_id' => $client->id,
                'definition_id' => $definition->id,
                'status' => 'active',
                'version' => 1,
            ]);
        }
    }

    public function test_user_can_read_but_cannot_write(): void
    {
        $account = $this->createAccount();
        $user = $this->actor($account, UserRole::User);
        $client = $this->entitledClient($account);
        $definition = $this->eligibleDefinition();
        $enrollment = $this->enrollment($account, $client, $definition);

        $this->actingAs($user)->getJson('/api/monitoring/enrollments')->assertOk();
        $this->actingAs($user)->getJson("/api/monitoring/enrollments/{$enrollment->id}")->assertOk();

        $this->actingAs($user)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ])->assertForbidden();

        $this->actingAs($user)->patchJson("/api/monitoring/enrollments/{$enrollment->id}", [
            'configuration' => ['periodo' => '202601'],
        ])->assertForbidden();

        $this->actingAs($user)->deleteJson("/api/monitoring/enrollments/{$enrollment->id}")->assertForbidden();

        $this->assertDatabaseHas('monitoring_enrollments', [
            'id' => $enrollment->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
        ]);
    }

    public function test_create_refuses_client_with_monitoring_status_off(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = Client::factory()->for($account, 'account')->create(['monitoring_enabled' => false]);
        $definition = $this->eligibleDefinition();

        $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('monitoring_enrollments', 0);
    }

    public function test_create_refuses_client_from_another_account_with_an_indistinguishable_404(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $foreign = $this->entitledClient($this->createAccount());
        $definition = $this->eligibleDefinition();

        $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $foreign->id,
            'definition_id' => $definition->id,
        ])->assertNotFound();

        $this->assertDatabaseCount('monitoring_enrollments', 0);
    }

    public function test_create_refuses_an_unavailable_definition(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $definition = MonitoringDefinition::factory()->unavailable()->create();

        $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('monitoring_enrollments', 0);
    }

    public function test_create_refuses_a_prospecting_definition(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $definition = MonitoringDefinition::factory()->prospeccao()->create(['id' => 'parc-paex']);

        $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('monitoring_enrollments', 0);
    }

    public function test_create_refuses_a_definition_without_a_resolvable_consult_operation(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $definition = $this->eligibleDefinition(['operations' => null]);

        $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('monitoring_enrollments', 0);
    }

    public function test_create_refuses_an_unknown_definition(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);

        $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => 'definition-that-does-not-exist',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('monitoring_enrollments', 0);
    }

    public function test_create_refuses_a_definition_incompatible_with_the_person_type(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $definition = $this->eligibleDefinition(['person_types' => ['PF']]);

        $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('monitoring_enrollments', 0);
    }

    public function test_create_refuses_a_client_regime_outside_the_definition_metadata(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account, ['regime' => 'simples']);
        $definition = $this->eligibleDefinition(['regimes' => ['mei']]);

        $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('monitoring_enrollments', 0);
    }

    public function test_create_accepts_a_client_regime_listed_in_the_definition_metadata(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account, ['regime' => 'simples']);
        $definition = $this->eligibleDefinition(['regimes' => ['simples_nacional', 'mei']]);

        $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ])->assertCreated();

        $this->assertDatabaseCount('monitoring_enrollments', 1);
    }

    public function test_create_refuses_a_duplicate_active_association(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $definition = $this->eligibleDefinition();
        $existing = $this->enrollment($account, $client, $definition);

        $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('monitoring_enrollments', 1);
        $this->assertDatabaseHas('monitoring_enrollments', [
            'id' => $existing->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
        ]);
    }

    public function test_create_refuses_a_duplicate_paused_association(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $definition = $this->eligibleDefinition();
        $existing = $this->enrollment($account, $client, $definition, [
            'status' => MonitoringEnrollment::STATUS_PAUSED,
            'pause_reason' => 'outorga pendente',
        ]);

        $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => $definition->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('monitoring_enrollments', 1);
        $this->assertDatabaseHas('monitoring_enrollments', [
            'id' => $existing->id,
            'status' => MonitoringEnrollment::STATUS_PAUSED,
        ]);
    }

    public function test_create_reactivates_an_ended_association_resetting_status_and_version(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $definition = $this->eligibleDefinition();
        $enrollment = $this->enrollment($account, $client, $definition, [
            'status' => MonitoringEnrollment::STATUS_PAUSED,
            'pause_reason' => 'outorga pendente',
            'version' => 4,
        ]);

        $this->actingAs($actor)->deleteJson("/api/monitoring/enrollments/{$enrollment->id}")->assertOk();
        $this->assertDatabaseHas('monitoring_enrollments', [
            'id' => $enrollment->id,
            'status' => MonitoringEnrollment::STATUS_ENDED,
            'version' => 5,
        ]);

        $response = $this->actingAs($actor)->postJson('/api/monitoring/enrollments', [
            'client_id' => $client->id,
            'definition_id' => $definition->id,
            'configuration' => ['periodo' => '202601'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.id', $enrollment->id)
            ->assertJsonPath('data.status', MonitoringEnrollment::STATUS_ACTIVE)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.pause_reason', null)
            ->assertJsonPath('data.configuration.periodo', '202601');

        $this->assertDatabaseCount('monitoring_enrollments', 1);
    }

    public function test_the_same_triple_may_exist_in_another_account(): void
    {
        $accountA = $this->createAccount();
        $accountB = $this->createAccount();
        $definition = $this->eligibleDefinition();

        $clientA = $this->entitledClient($accountA);
        $clientB = $this->entitledClient($accountB);

        $this->enrollment($accountA, $clientA, $definition);
        $this->enrollment($accountB, $clientB, $definition);

        $this->assertDatabaseCount('monitoring_enrollments', 2);
    }

    public function test_index_is_paginated_and_scoped_to_the_effective_account(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $definition = $this->eligibleDefinition();

        $one = $this->enrollment($account, $this->entitledClient($account), $definition);
        $two = $this->enrollment($account, $this->entitledClient($account), $definition);
        $three = $this->enrollment($account, $this->entitledClient($account), $definition);

        $foreignAccount = $this->createAccount();
        $foreign = $this->enrollment(
            $foreignAccount,
            $this->entitledClient($foreignAccount),
            $definition,
        );

        $response = $this->actingAs($actor)
            ->getJson('/api/monitoring/enrollments?per_page=2')
            ->assertOk();

        $response->assertJsonPath('total', 3)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('current_page', 1)
            ->assertJsonCount(2, 'data');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertNotContains($foreign->id, $ids);

        $second = $this->actingAs($actor)
            ->getJson('/api/monitoring/enrollments?per_page=2&page=2')
            ->assertOk();

        $second->assertJsonCount(1, 'data');

        $allIds = collect([...$ids, ...collect($second->json('data'))->pluck('id')->all()]);
        $this->assertEqualsCanonicalizing([$one->id, $two->id, $three->id], $allIds->all());
    }

    public function test_super_admin_switch_scopes_to_the_target_account(): void
    {
        $accountA = $this->createAccount();
        $accountB = $this->createAccount();
        $definition = $this->eligibleDefinition();

        $enrollmentB = $this->enrollment($accountB, $this->entitledClient($accountB), $definition);
        $superAdminA = $this->actor($accountA, UserRole::SuperAdmin);

        $response = $this->actingAs($superAdminA)
            ->withSession(['switch_account_id' => $accountB->id])
            ->getJson('/api/monitoring/enrollments')
            ->assertOk();

        $response->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $enrollmentB->id);
    }

    public function test_index_searches_by_client_name_and_cnpj_digits(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $definition = $this->eligibleDefinition();

        $alfa = $this->enrollment($account, $this->entitledClient($account, [
            'razao_social' => 'Alfa Contabilidade Ltda',
            'cnpj' => '11222333000181',
        ]), $definition);

        $beta = $this->enrollment($account, $this->entitledClient($account, [
            'razao_social' => 'Beta Consultoria ME',
            'cnpj' => '99888777000166',
        ]), $definition);

        $byName = $this->actingAs($actor)
            ->getJson('/api/monitoring/enrollments?search=alfa')
            ->assertOk();
        $byName->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $alfa->id)
            ->assertJsonPath('data.0.client.razao_social', 'Alfa Contabilidade Ltda');

        $byFormattedCnpj = $this->actingAs($actor)
            ->getJson('/api/monitoring/enrollments?search=11.222.333/0001-81')
            ->assertOk();
        $byFormattedCnpj->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $alfa->id);

        $byPartialCnpj = $this->actingAs($actor)
            ->getJson('/api/monitoring/enrollments?search=8877')
            ->assertOk();
        $byPartialCnpj->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $beta->id);
    }

    public function test_index_filters_by_status_and_definition(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $definitionA = $this->eligibleDefinition();
        $definitionB = $this->eligibleDefinition();

        $active = $this->enrollment($account, $this->entitledClient($account), $definitionA);
        $paused = $this->enrollment($account, $this->entitledClient($account), $definitionB, [
            'status' => MonitoringEnrollment::STATUS_PAUSED,
            'pause_reason' => 'outorga pendente',
        ]);

        $byStatus = $this->actingAs($actor)
            ->getJson('/api/monitoring/enrollments?status=paused')
            ->assertOk();
        $byStatus->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $paused->id)
            ->assertJsonPath('data.0.pause_reason', 'outorga pendente');

        $byDefinition = $this->actingAs($actor)
            ->getJson('/api/monitoring/enrollments?definition_id='.$definitionA->id)
            ->assertOk();
        $byDefinition->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $active->id);
    }

    public function test_associations_remain_listed_when_monitoring_status_is_turned_off(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $definition = $this->eligibleDefinition();
        $enrollment = $this->enrollment($account, $client, $definition);

        $client->update(['monitoring_enabled' => false]);

        $response = $this->actingAs($actor)
            ->getJson('/api/monitoring/enrollments')
            ->assertOk();

        $response->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $enrollment->id)
            ->assertJsonPath('data.0.client.monitoring_enabled', false);
    }

    public function test_show_returns_client_definition_and_state(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $definition = $this->eligibleDefinition();
        $enrollment = $this->enrollment($account, $client, $definition, [
            'status' => MonitoringEnrollment::STATUS_PAUSED,
            'pause_reason' => 'outorga pendente',
            'version' => 3,
            'last_change_at' => now(),
        ]);

        $this->actingAs($actor)
            ->getJson("/api/monitoring/enrollments/{$enrollment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $enrollment->id)
            ->assertJsonPath('data.status', MonitoringEnrollment::STATUS_PAUSED)
            ->assertJsonPath('data.pause_reason', 'outorga pendente')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.definition.id', $definition->id);
    }

    public function test_show_patch_and_delete_of_another_account_return_404(): void
    {
        $foreignAccount = $this->createAccount();
        $foreign = $this->enrollment(
            $foreignAccount,
            $this->entitledClient($foreignAccount),
            $this->eligibleDefinition(),
            ['status' => MonitoringEnrollment::STATUS_ACTIVE, 'version' => 1],
        );

        $actor = $this->actor($this->createAccount());

        $this->actingAs($actor)
            ->getJson("/api/monitoring/enrollments/{$foreign->id}")
            ->assertNotFound();

        $this->actingAs($actor)
            ->patchJson("/api/monitoring/enrollments/{$foreign->id}", [
                'configuration' => ['periodo' => '202601'],
            ])
            ->assertNotFound();

        $this->actingAs($actor)
            ->deleteJson("/api/monitoring/enrollments/{$foreign->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('monitoring_enrollments', [
            'id' => $foreign->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
        ]);
    }

    public function test_patch_updates_configuration_only_and_increments_version(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $enrollment = $this->enrollment($account, $this->entitledClient($account), $this->eligibleDefinition());

        $response = $this->actingAs($actor)
            ->patchJson("/api/monitoring/enrollments/{$enrollment->id}", [
                'configuration' => ['periodo' => '202601'],
                'status' => MonitoringEnrollment::STATUS_ENDED,
            ])
            ->assertOk();

        $response->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.status', MonitoringEnrollment::STATUS_ACTIVE)
            ->assertJsonPath('data.configuration.periodo', '202601');

        $this->actingAs($actor)
            ->patchJson("/api/monitoring/enrollments/{$enrollment->id}", [
                'configuration' => ['periodo' => '202602'],
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.configuration.periodo', '202602');
    }

    public function test_patch_on_an_ended_association_is_refused(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $enrollment = $this->enrollment($account, $this->entitledClient($account), $this->eligibleDefinition(), [
            'status' => MonitoringEnrollment::STATUS_ENDED,
            'version' => 2,
        ]);

        $this->actingAs($actor)
            ->patchJson("/api/monitoring/enrollments/{$enrollment->id}", [
                'configuration' => ['periodo' => '202601'],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('monitoring_enrollments', [
            'id' => $enrollment->id,
            'status' => MonitoringEnrollment::STATUS_ENDED,
            'version' => 2,
        ]);
    }

    public function test_delete_ends_the_association_and_preserves_history(): void
    {
        $account = $this->createAccount();
        $actor = $this->actor($account);
        $client = $this->entitledClient($account);
        $definition = $this->eligibleDefinition();
        $enrollment = $this->enrollment($account, $client, $definition, ['version' => 2]);

        $artifact = MonitoringArtifact::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client->id,
            'enrollment_id' => $enrollment->id,
        ]);

        $this->actingAs($actor)
            ->deleteJson("/api/monitoring/enrollments/{$enrollment->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $enrollment->id)
            ->assertJsonPath('data.status', MonitoringEnrollment::STATUS_ENDED)
            ->assertJsonPath('data.version', 3);

        $this->assertDatabaseHas('monitoring_enrollments', [
            'id' => $enrollment->id,
            'status' => MonitoringEnrollment::STATUS_ENDED,
        ]);
        $this->assertDatabaseHas('monitoring_artifacts', ['id' => $artifact->id]);
    }

    public function test_model_lifecycle_pause_resume_and_end_increment_the_version(): void
    {
        $enrollment = $this->enrollment(
            $this->createAccount(),
            null,
            null,
        );

        $this->assertTrue($enrollment->pause('outorga pendente'));
        $enrollment->refresh();

        $this->assertSame(MonitoringEnrollment::STATUS_PAUSED, $enrollment->status);
        $this->assertSame('outorga pendente', $enrollment->pause_reason);
        $this->assertSame(2, $enrollment->version);
        $this->assertNotNull($enrollment->last_change_at);

        $this->assertFalse($enrollment->pause('outorga pendente'));
        $enrollment->refresh();
        $this->assertSame(2, $enrollment->version, 'Repeating the same pause must not fence again.');

        $this->assertTrue($enrollment->resume());
        $enrollment->refresh();

        $this->assertSame(MonitoringEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertNull($enrollment->pause_reason);
        $this->assertSame(3, $enrollment->version);

        $this->assertTrue($enrollment->end());
        $enrollment->refresh();

        $this->assertSame(MonitoringEnrollment::STATUS_ENDED, $enrollment->status);
        $this->assertNull($enrollment->pause_reason);
        $this->assertSame(4, $enrollment->version);
    }

    public function test_pause_and_resume_are_refused_on_an_ended_association(): void
    {
        $enrollment = $this->enrollment(
            $this->createAccount(),
            null,
            null,
        );

        $this->assertTrue($enrollment->end());
        $this->assertSame(2, $enrollment->version);

        $this->assertFalse($enrollment->pause('outorga pendente'));
        $this->assertFalse($enrollment->resume());
        $this->assertFalse($enrollment->end());

        $enrollment->refresh();

        $this->assertSame(MonitoringEnrollment::STATUS_ENDED, $enrollment->status);
        $this->assertNull($enrollment->pause_reason);
        $this->assertSame(2, $enrollment->version, 'An ended association must never be revived or fenced again.');
    }

    public function test_model_transitions_from_stale_instances_are_monotonic(): void
    {
        $enrollment = $this->enrollment(
            $this->createAccount(),
            null,
            null,
        );
        $stale = MonitoringEnrollment::query()->whereKey($enrollment->id)->firstOrFail();
        $this->assertSame(1, $stale->version);

        $this->assertTrue($enrollment->pause('outorga pendente'));
        $this->assertSame(2, $enrollment->version);
        $this->assertSame(1, $stale->version);

        // A read-then-write would mint version 2 again from the stale
        // instance; the locked fresh read must mint 3.
        $this->assertTrue($stale->pause('outorga vencida'));
        $this->assertSame(3, $stale->version);
        $this->assertSame('outorga vencida', $stale->pause_reason);
        $this->assertSame(3, $enrollment->refresh()->version);
    }

    public function test_service_configuration_updates_from_stale_instances_are_monotonic(): void
    {
        $enrollment = $this->enrollment(
            $this->createAccount(),
            null,
            null,
        );
        $stale = MonitoringEnrollment::query()->whereKey($enrollment->id)->firstOrFail();
        $service = app(MonitoringEnrollmentService::class);

        $first = $service->updateConfiguration($enrollment, ['periodo' => '202601']);
        $this->assertSame(2, $first->version);

        $second = $service->updateConfiguration($stale, ['periodo' => '202602']);
        $this->assertSame(3, $second->version);
        $this->assertSame(3, $enrollment->refresh()->version);
    }

    private function actor(Account $account, UserRole $role = UserRole::Admin): User
    {
        return $this->createUser($account, ['role' => $role]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entitledClient(Account $account, array $attributes = []): Client
    {
        return Client::factory()->for($account, 'account')->create([
            'monitoring_enabled' => true,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function eligibleDefinition(array $attributes = []): MonitoringDefinition
    {
        return MonitoringDefinition::factory()->create([
            'operations' => ['CONSDECLARACAO13'],
            'person_types' => ['PF', 'PJ'],
            'regimes' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function enrollment(
        Account $account,
        ?Client $client,
        ?MonitoringDefinition $definition,
        array $attributes = [],
    ): MonitoringEnrollment {
        return MonitoringEnrollment::factory()->create([
            'account_id' => $account->id,
            'client_id' => $client?->id ?? Client::factory()->for($account, 'account')->create()->id,
            'definition_id' => $definition?->id ?? MonitoringDefinition::factory()->create(['operations' => ['CONSDECLARACAO13']])->id,
            'status' => MonitoringEnrollment::STATUS_ACTIVE,
            'version' => 1,
            'last_change_at' => now(),
            ...$attributes,
        ]);
    }
}
