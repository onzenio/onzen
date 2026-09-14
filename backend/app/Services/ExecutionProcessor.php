<?php

namespace App\Services;

use App\Integrations\Serpro\SerproNormalizer;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringRun;
use Illuminate\Support\Facades\Log;

class ExecutionProcessor
{
    public function __construct(
        private readonly SnapshotService $snapshots,
        private readonly ArtifactStore $artifacts,
        private readonly MonitoringEventService $events,
    ) {}

    /**
     * Processa a resposta de uma execução concluída: normaliza, versiona
     * snapshot e decodifica conteúdos em artefatos. Falha de artefato é
     * registrada sem quebrar a execução.
     *
     * @param  array<string, mixed>  $payload  resposta (fixture ou transporte)
     */
    public function processCompletedRun(MonitoringRun $run, array $payload): MonitoringRun
    {
        $family = MonitoringDefinition::query()->where('code', $run->definition_code)->value('family')
            ?? SerproNormalizer::familyForOperation($run->definition_code);

        $normalized = SerproNormalizer::normalize((string) $family, $payload);
        $normalized['normalized'] = true;

        if ($run->client_id !== null) {
            $client = $run->client;
            $this->snapshots->record($run->account, $client, (string) $family, $normalized);
        }

        $this->decodeContents($run, $payload);

        if ($run->status === MonitoringRun::RUNNING || $run->status === MonitoringRun::PENDING) {
            $run->transitionTo(MonitoringRun::COMPLETED);
        }

        $run->forceFill(['result_summary' => ['family' => $family]])->save();

        $this->events->finished($run->refresh(), 'success');

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function decodeContents(MonitoringRun $run, array $payload): void
    {
        $contents = $payload['conteudos'] ?? [];

        if (! is_array($contents) || $contents === []) {
            return;
        }

        foreach ($contents as $content) {
            $decoded = base64_decode((string) ($content['base64'] ?? ''), true);

            if ($decoded === false) {
                $run->forceFill([
                    'artifact_error' => 'Falha ao decodificar artefato: conteúdo inválido.',
                ])->save();

                Log::warning('monitoring.artifact_failed', ['run_id' => $run->id]);

                return;
            }

            $stored = $this->artifacts->put(
                $run->account_id,
                $decoded,
                (string) ($content['mime'] ?? 'application/pdf'),
            );

            $run->forceFill(['artifact_ref' => $stored['ref']])->save();

            return;
        }
    }
}
