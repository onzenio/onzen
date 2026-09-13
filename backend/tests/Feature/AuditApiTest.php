<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://localhost:3000';

    /**
     * @return array{a: Account, b: Account, c: Account, super: User, adminB: User, operatorB: User, userB: User, other: User, r1: AuditLog, r2: AuditLog, r3: AuditLog, r4: AuditLog}
     */
    private function seedProbes(): array
    {
        $data = Model::withoutEvents(function (): array {
            $a = $this->createAccount();
            $b = $this->createAccount();
            $c = $this->createAccount();
            $super = $this->createUser($a, ['role' => UserRole::SuperAdmin]);
            $adminB = $this->createUser($b, ['role' => UserRole::Admin]);
            $operatorB = $this->createUser($b, ['role' => UserRole::Operator]);
            $userB = $this->createUser($b, ['role' => UserRole::User]);
            $other = $this->createUser($c);

            return compact('a', 'b', 'c', 'super', 'adminB', 'operatorB', 'userB', 'other');
        });

        $probes = [
            'r1' => ['origin' => $data['a']->id, 'target' => null, 'actor' => $data['super']->id, 'days' => 10],
            'r2' => ['origin' => $data['a']->id, 'target' => $data['b']->id, 'actor' => $data['super']->id, 'days' => 5],
            'r3' => ['origin' => $data['c']->id, 'target' => $data['b']->id, 'actor' => $data['other']->id, 'days' => 2],
            'r4' => ['origin' => $data['b']->id, 'target' => null, 'actor' => $data['adminB']->id, 'days' => 0],
        ];

        foreach ($probes as $key => $probe) {
            $row = AuditLog::factory()->create([
                'actor_user_id' => $probe['actor'],
                'origin_account_id' => $probe['origin'],
                'target_account_id' => $probe['target'],
                'action' => "probe.{$key}",
            ]);
            $row->created_at = now()->subDays($probe['days']);
            $row->save();
            $data[$key] = $row->refresh();
        }

        return $data;
    }

    public function test_super_admin_lists_all_events_with_pagination(): void
    {
        $data = $this->seedProbes();

        $response = $this->actingAs($data['super'])->getJson('/api/audit?per_page=2');

        $response->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.per_page', 2);
        $this->assertCount(2, $response->json('data'));

        $all = $this->actingAs($data['super'])->getJson('/api/audit')->assertOk();
        $this->assertCount(4, $all->json('data'));
    }

    public function test_filter_by_account_matches_origin_or_target(): void
    {
        $data = $this->seedProbes();

        $response = $this->actingAs($data['super'])->getJson("/api/audit?account_id={$data['b']->id}")->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $expected = collect([$data['r2']->id, $data['r3']->id, $data['r4']->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);
    }

    public function test_filter_by_actor_and_period(): void
    {
        $data = $this->seedProbes();

        $byActor = $this->actingAs($data['super'])->getJson("/api/audit?actor_user_id={$data['super']->id}")->assertOk();
        $ids = collect($byActor->json('data'))->pluck('id')->sort()->values()->all();
        $expected = collect([$data['r1']->id, $data['r2']->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);

        $from = now()->subDays(6)->toDateString();
        $since = $this->actingAs($data['super'])->getJson("/api/audit?from={$from}")->assertOk();
        $ids = collect($since->json('data'))->pluck('id')->sort()->values()->all();
        $expected = collect([$data['r2']->id, $data['r3']->id, $data['r4']->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);

        $to = now()->subDays(3)->toDateString();
        $until = $this->actingAs($data['super'])->getJson("/api/audit?to={$to}")->assertOk();
        $ids = collect($until->json('data'))->pluck('id')->sort()->values()->all();
        $expected = collect([$data['r1']->id, $data['r2']->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);

        $combined = $this->actingAs($data['super'])
            ->getJson("/api/audit?actor_user_id={$data['super']->id}&from={$from}")
            ->assertOk();
        $this->assertSame([$data['r2']->id], collect($combined->json('data'))->pluck('id')->all());
    }

    public function test_admin_sees_only_own_account_events(): void
    {
        $data = $this->seedProbes();

        $response = $this->actingAs($data['adminB'])->getJson('/api/audit')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->sort()->values()->all();
        $expected = collect([$data['r2']->id, $data['r3']->id, $data['r4']->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);
        $this->assertNotContains($data['r1']->id, $ids);
    }

    public function test_operator_and_user_are_denied(): void
    {
        $data = $this->seedProbes();

        $this->actingAs($data['operatorB'])->getJson('/api/audit')->assertForbidden();
        $this->actingAs($data['userB'])->getJson('/api/audit')->assertForbidden();
    }

    public function test_guest_cannot_list_audit(): void
    {
        // Método separado de propósito: actingAs persiste no teste inteiro.
        $this->seedProbes();

        $this->getJson('/api/audit')->assertUnauthorized();
    }

    public function test_audit_has_no_write_routes(): void
    {
        $data = $this->seedProbes();

        // POST no path existente com outro método → 405; nada bate /{id} → 404.
        $this->actingAs($data['super'])->postJson('/api/audit', [])->assertStatus(405);
        $this->actingAs($data['super'])->putJson("/api/audit/{$data['r1']->id}", [])->assertNotFound();
        $this->actingAs($data['super'])->patchJson("/api/audit/{$data['r1']->id}", [])->assertNotFound();
        $this->actingAs($data['super'])->deleteJson("/api/audit/{$data['r1']->id}")->assertNotFound();
    }
}
