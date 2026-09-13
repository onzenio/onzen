<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\InvitationMail;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_lists_accounts_with_plan(): void
    {
        $this->seed(PlanSeeder::class);
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);
        $admin->account->update(['plan_id' => Plan::default()->id]);

        $this->actingAs($admin)->getJson('/api/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.profile', $admin->account->profile->value)
            ->assertJsonPath('data.0.plan.name', 'Básico');
    }

    public function test_super_admin_creates_account_b_with_admin_invite(): void
    {
        Mail::fake();
        $this->seed(PlanSeeder::class);
        $admin = $this->createUser(attributes: ['role' => UserRole::SuperAdmin]);

        $response = $this->actingAs($admin)->postJson('/api/accounts', [
            'name' => 'Escritório Novo',
            'admin_name' => 'Admin Novo',
            'admin_email' => 'admin-novo@example.com',
        ]);

        $response->assertCreated()
            ->assertJsonPath('account.profile', 'B')
            ->assertJsonPath('account.name', 'Escritório Novo');

        $this->assertDatabaseHas('accounts', ['name' => 'Escritório Novo', 'profile' => 'B']);
        $this->assertDatabaseHas('invitations', ['email' => 'admin-novo@example.com', 'role' => 'admin']);

        Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('admin-novo@example.com'));

        $this->assertArrayNotHasKey('token_hash', $response->json('invitation'));
    }

    public function test_non_super_admin_cannot_list_or_create_accounts(): void
    {
        $admin = $this->createUser(attributes: ['role' => UserRole::Admin]);

        $this->actingAs($admin)->getJson('/api/accounts')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/accounts', [
            'name' => 'X',
            'admin_email' => 'x@example.com',
        ])->assertForbidden();
    }

    public function test_guest_cannot_list_or_create_accounts(): void
    {
        $this->getJson('/api/accounts')->assertUnauthorized();
        $this->postJson('/api/accounts', [])->assertUnauthorized();
    }
}
