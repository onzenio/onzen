<?php

namespace App\Integrations\Serpro;

use App\Contracts\ArtifactStore;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\Log;

/**
 * Port of the legacy consult artifact store.
 *
 * Walks a decoded SERPRO consult payload, finds base64 PDF/XML fields
 * (`*pdf`, `*xml`, `*base64`), decodes them and stores the bytes through the
 * ArtifactStore, replacing each content field with an opaque ref plus hash.
 * A field that cannot be decoded records an artifact failure (marker plus
 * `failures` entry) without throwing, so the surrounding execution keeps its
 * remaining result and never gets an invented file.
 */
final class ConsultArtifactStore
{
    public function __construct(private readonly ArtifactStore $store) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{account_id?: int|null, client_id?: int|null, enrollment_id?: int|null, source?: string|null}  $context
     * @return array{
     *     payload: array<string, mixed>,
     *     artifacts: list<array{ref: string, hash_sha256: string, filename: string, field: string, kind: string}>,
     *     failures: list<array{field: string, reason: string}>
     * }
     */
    public function persist(array $payload, ?string $operation = null, array $context = []): array
    {
        $artifacts = [];
        $failures = [];

        $sanitized = $this->walk($payload, $artifacts, $failures, $operation, $context);

        return ['payload' => $sanitized, 'artifacts' => $artifacts, 'failures' => $failures];
    }

    /**
     * @param  array<string|int, mixed>  $node
     * @param  list<array{ref: string, hash_sha256: string, filename: string, field: string, kind: string}>  $artifacts
     * @param  list<array{field: string, reason: string}>  $failures
     * @param  array{account_id?: int|null, client_id?: int|null, enrollment_id?: int|null, source?: string|null}  $context
     * @return array<string|int, mixed>
     */
    private function walk(array $node, array &$artifacts, array &$failures, ?string $operation, array $context): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->walk($value, $artifacts, $failures, $operation, $context);

                continue;
            }

            if (! is_string($value) || ! $this->isBinaryField((string) $key, $value)) {
                $out[$key] = $value;

                continue;
            }

            $decoded = $this->decode($value);

            if ($decoded === null) {
                $out[$key] = null;
                $out[$key.'_artifact_error'] = 'decode_failed';
                $failures[] = ['field' => (string) $key, 'reason' => 'decode_failed'];
                Log::warning('monitoring.artifact_decode_failed', [
                    'field' => (string) $key,
                    'source' => $context['source'] ?? $operation,
                ]);

                continue;
            }

            $filename = $this->filenameFor((string) $key, $node, $operation);

            $stored = $this->store->put($decoded, [
                'account_id' => $context['account_id'] ?? CurrentAccount::get(),
                'client_id' => $context['client_id'] ?? null,
                'enrollment_id' => $context['enrollment_id'] ?? null,
                'kind' => $this->kindFor($filename),
                'source' => $context['source'] ?? $operation,
                'original_name' => $filename,
            ]);

            $artifacts[] = [
                'ref' => $stored['ref'],
                'hash_sha256' => $stored['hash_sha256'],
                'filename' => $filename,
                'field' => (string) $key,
                'kind' => $this->kindFor($filename),
            ];

            $out[$key] = null;
            $out[$key.'_storage_ref'] = $stored['ref'];
            $out[$key.'_hash_sha256'] = $stored['hash_sha256'];
        }

        return $out;
    }

    private function isBinaryField(string $key, string $value): bool
    {
        if (strlen($value) < 8) {
            return false;
        }

        if (preg_match('/(?:pdf|xml|base64)$/i', $key) !== 1) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9+\/=]+$/', preg_replace('/\s+/', '', $value) ?? '');
    }

    private function decode(string $value): ?string
    {
        $normalized = preg_replace('/\s+/', '', $value) ?? '';
        $decoded = base64_decode($normalized, true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /**
     * @param  array<string|int, mixed>  $node
     */
    private function filenameFor(string $key, array $node, ?string $operation = null): string
    {
        // Explicit GERARDAS12 emission returns a bare `pdf` with no official
        // sibling filename; name it PGDASD-DAS-<periodo>.pdf instead of the
        // generic fallback. Default behavior is unchanged.
        if ($operation === 'GERARDAS12' && strtolower($key) === 'pdf' && ! $this->hasOfficialSibling($node)) {
            $digits = substr((string) preg_replace('/\D/', '', (string) $this->stringValue($node, 'periodoApuracao', 'periodo_apuracao')), 0, 6);

            return $digits !== '' ? 'PGDASD-DAS-'.$digits.'.pdf' : 'PGDASD-DAS.pdf';
        }

        $official = match (true) {
            str_contains(strtolower($key), 'recibo') => $this->stringValue($node, 'nomeArquivoRecibo'),
            str_contains(strtolower($key), 'declaracao') && str_contains(strtolower($key), 'xml') => $this->stringValue($node, 'nomeArquivoXml'),
            str_contains(strtolower($key), 'declaracao') => $this->stringValue($node, 'nomeArquivoDeclaracao'),
            str_contains(strtolower($key), 'extrato') => $this->stringValue($node, 'nomeArquivoExtrato'),
            str_contains(strtolower($key), 'maed') => $this->stringValue($node, 'nomeArquivoMaed'),
            str_contains(strtolower($key), 'relatorio') => $this->stringValue($node, 'nomeArquivoRelatorio'),
            default => null,
        };

        $name = $official ?: (preg_replace('/[^A-Za-z0-9._-]/', '', $key) ?: 'artifact').'.bin';
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '-', basename(str_replace('\\', '/', $name))) ?? '';
        $name = substr(trim($name, '.-'), 0, 160);

        return $name !== '' ? $name : 'artifact.bin';
    }

    /**
     * @param  array<string|int, mixed>  $node
     */
    private function hasOfficialSibling(array $node): bool
    {
        foreach ($node as $sibling => $value) {
            if (is_string($value) && str_starts_with(strtolower((string) $sibling), 'nomearquivo')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string|int, mixed>  $node
     */
    private function stringValue(array $node, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $node[$key] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function kindFor(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => 'pdf',
            'xml' => 'xml',
            default => 'other',
        };
    }
}
