<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ScheduleConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_current_routines_are_scheduled_without_legacy_overlap(): void
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();

        $commands = collect($this->app->make(Schedule::class)->events())
            ->map(fn ($event) => (string) $event->command)
            ->values()
            ->all();

        $this->assertCount(2, $commands);

        $monthly = collect($commands)->first(fn ($command): bool => str_contains($command, 'monitoring:run-monthly-cycle'));
        $daily = collect($commands)->first(fn ($command): bool => str_contains($command, 'monitoring:warm-procuracoes'));

        $this->assertNotNull($monthly);
        $this->assertNotNull($daily);
        $this->assertStringContainsString('--confirm', (string) $monthly);
        $this->assertStringContainsString('--confirm', (string) $daily);

        foreach ($commands as $command) {
            $this->assertStringNotContainsString('monitoring:cycle ', $command.' ');
            $this->assertStringNotContainsString('monitoring:renew-terms', $command);
        }
    }
}
