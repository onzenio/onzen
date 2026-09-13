<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_escapes_malicious_names(): void
    {
        $invitation = Invitation::factory()->create([
            'name' => '<img src=x onerror=alert(1)>',
        ]);
        $invitation->account->update(['name' => '<img src=x onerror=alert(2)>']);

        $html = (new InvitationMail($invitation, $invitation->token))->render();

        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }
}
