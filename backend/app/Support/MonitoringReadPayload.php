<?php

namespace App\Support;

use App\Models\MonitoringAlert;
use App\Models\MonitoringChange;
use App\Models\MonitoringRun;
use App\Models\MonitoringSnapshot;

/**
 * Payloads das leituras de monitoramento (Task 25).
 *
 * Uma única política de exposição para snapshots, mudanças, execuções e
 * alertas: metadados factuais, datas em ISO-8601 e nenhum payload bruto,
 * referência interna de armazenamento ou material sensível. O `data` de um
 * snapshot/mudança só aparece quando o resultado foi normalizado; chaves
 * internas (`*_storage_ref`, credenciais e conteúdo binário pdf/xml/base64)
 * são removidas recursivamente mesmo de resultados normalizados. O filtro é
 * somente-leitura: nunca altera o que está persistido.
 */
final class MonitoringReadPayload
{
    /**
     * Fragmentos de chave que nunca são expostos na leitura.
     *
     * @var list<string>
     */
    private const INTERNAL_KEY_FRAGMENTS = ['storage_ref', 'secret', 'password', 'pfx', 'token'];

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(MonitoringSnapshot $snapshot): array
    {
        $payload = [
            'id' => $snapshot->id,
            'enrollment_id' => $snapshot->enrollment_id,
            'client_id' => $snapshot->client_id,
            'run_id' => $snapshot->run_id,
            'operation_code' => $snapshot->operation_code,
            'family' => $snapshot->family,
            'normalized' => (bool) $snapshot->normalized,
            'fingerprint' => $snapshot->fingerprint,
            'freshness' => $snapshot->freshness,
            'completeness' => $snapshot->completeness,
            'verified_at' => $snapshot->verified_at?->toIso8601String(),
            'created_at' => $snapshot->created_at?->toIso8601String(),
        ];

        if ($snapshot->normalized) {
            $payload['data'] = self::sanitize($snapshot->data);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function change(MonitoringChange $change): array
    {
        $snapshot = $change->relationLoaded('snapshot') ? $change->snapshot : null;
        $normalized = $snapshot?->normalized === true;

        $payload = [
            'id' => $change->id,
            'enrollment_id' => $change->enrollment_id,
            'client_id' => $change->client_id,
            'snapshot_id' => $change->snapshot_id,
            'previous_snapshot_id' => $change->previous_snapshot_id,
            'run_id' => $change->run_id,
            'operation_code' => $change->operation_code,
            'kind' => $change->kind,
            'normalized' => $normalized,
            'created_at' => $change->created_at?->toIso8601String(),
        ];

        if ($normalized) {
            $payload['data'] = self::sanitize($change->data);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(MonitoringRun $run): array
    {
        return [
            'id' => $run->id,
            'enrollment_id' => $run->enrollment_id,
            'definition_key' => $run->definition_key,
            'operation_code' => $run->operation_code,
            'trigger' => $run->trigger,
            'status' => $run->status->value,
            'environment' => $run->environment,
            'dry_run' => (bool) $run->dry_run,
            'protocol' => $run->protocol,
            'eta' => $run->eta?->toIso8601String(),
            'parameters' => $run->parameters === null ? null : self::sanitize($run->parameters),
            'external_code' => $run->external_code,
            'error_code' => $run->error_code,
            'fencing_token' => $run->fencing_token,
            'created_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function alert(MonitoringAlert $alert): array
    {
        return [
            'id' => $alert->id,
            'enrollment_id' => $alert->enrollment_id,
            'client_id' => $alert->client_id,
            'change_id' => $alert->change_id,
            'status' => $alert->status,
            'acknowledged_by_user_id' => $alert->acknowledged_by_user_id,
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'created_at' => $alert->created_at?->toIso8601String(),
        ];
    }

    /**
     * Remove recursivamente chaves internas de um payload normalizado:
     * referências de armazenamento, credenciais e conteúdo binário.
     *
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>
     */
    public static function sanitize(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isInternalKey($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::sanitize($value) : $value;
        }

        return $clean;
    }

    private static function isInternalKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::INTERNAL_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return preg_match('/(^|_)(pdf|xml|base64)$/', $normalized) === 1;
    }
}
