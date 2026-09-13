<?php

namespace Tests\Feature;

use App\Models\SerproSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SerproSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_serpro_settings_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('serpro_settings', [
            'id', 'environment', 'transport_approved', 'transport_approved_at',
            'transport_approved_by_user_id', 'created_at', 'updated_at',
        ]));
    }

    public function test_current_creates_a_singleton_with_fail_closed_defaults(): void
    {
        $settings = SerproSettings::current();

        $this->assertSame('homologacao', $settings->environment());
        $this->assertFalse($settings->transportApproved());
        $this->assertNull($settings->transport_approved_at);
        $this->assertNull($settings->transport_approved_by_user_id);
        $this->assertDatabaseHas('serpro_settings', [
            'id' => $settings->id,
            'environment' => 'homologacao',
            'transport_approved' => false,
        ]);
    }

    public function test_current_returns_the_existing_singleton(): void
    {
        $first = SerproSettings::current();
        $second = SerproSettings::current();

        $this->assertTrue($first->is($second));
        $this->assertSame(1, SerproSettings::query()->count());
    }

    public function test_current_does_not_reset_an_existing_environment(): void
    {
        SerproSettings::factory()->create(['environment' => 'producao', 'transport_approved' => true]);

        $settings = SerproSettings::current();

        $this->assertSame('producao', $settings->environment());
        $this->assertTrue($settings->transportApproved());
        $this->assertSame(1, SerproSettings::query()->count());
    }

    public function test_helpers_reflect_stored_state_and_relations(): void
    {
        $approver = $this->createUser();
        $settings = SerproSettings::factory()->create([
            'environment' => 'producao',
            'transport_approved' => true,
            'transport_approved_at' => now(),
            'transport_approved_by_user_id' => $approver->id,
        ]);

        $this->assertSame('producao', $settings->environment());
        $this->assertTrue($settings->transportApproved());
        $this->assertInstanceOf(Carbon::class, $settings->transport_approved_at);
        $this->assertTrue($settings->transportApprovedBy->is($approver));
    }
}
