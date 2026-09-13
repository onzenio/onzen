<?php

namespace Tests\Feature;

use App\Models\Invitation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitations_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('invitations', [
            'id', 'account_id', 'name', 'email', 'role', 'token_hash',
            'expires_at', 'accepted_at', 'invited_by_user_id',
        ]));
    }

    public function test_invitation_persists_with_expiry_and_casts(): void
    {
        $inviter = $this->createUser();

        $invitation = Invitation::factory()->create([
            'account_id' => $inviter->account_id,
            'invited_by_user_id' => $inviter->id,
            'accepted_at' => null,
        ]);

        $this->assertDatabaseHas('invitations', ['id' => $invitation->id, 'accepted_at' => null]);
        $this->assertNotNull($invitation->refresh()->expires_at);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertNull($invitation->accepted_at);
    }

    public function test_token_hash_is_unique(): void
    {
        $existing = Invitation::factory()->create();

        $this->expectException(QueryException::class);

        Invitation::factory()->create(['token_hash' => $existing->token_hash]);
    }
}
