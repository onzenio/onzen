<?php

namespace Tests\Feature;

use App\Events\MonitoringRunFinished;
use App\Events\MonitoringRunStarted;
use App\Models\MonitoringRun;
use App\Services\MonitoringEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MonitoringEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_eventos_contem_apenas_identificadores_opacos(): void
    {
        Event::fake([MonitoringRunStarted::class, MonitoringRunFinished::class]);

        $run = MonitoringRun::factory()->create();
        $service = app(MonitoringEventService::class);

        $service->started($run);
        $service->finished($run, 'success');

        Event::assertDispatched(MonitoringRunStarted::class, function ($event) use ($run) {
            $json = json_encode($event->payload);
            $this->assertSame($run->id, $event->payload['run_id']);

            foreach (['secret', 'token', 'pfx', 'password', 'xml', 'conteudo_fiscal', 'consumer'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) $json);
            }

            return true;
        });

        Event::assertDispatched(MonitoringRunFinished::class, function ($event) {
            $this->assertSame('success', $event->payload['outcome']);
            $this->assertArrayNotHasKey('envelope', $event->payload);

            return true;
        });
    }
}
