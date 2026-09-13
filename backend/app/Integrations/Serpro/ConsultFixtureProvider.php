<?php

namespace App\Integrations\Serpro;

use JsonException;
use RuntimeException;

/**
 * Fixtures oficiais do Integra Contador usadas no dry-run.
 *
 * Com o transporte desligado a execução é atendida pela fixture da operação;
 * quando não existe fixture, load() retorna null e o bloqueio acontece a
 * montante — o dry-run nunca inventa resultado.
 */
final class ConsultFixtureProvider
{
    /**
     * Envelope da fixture (operation, source, http_status, dry_run, body).
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException quando a fixture existe mas não é um objeto JSON válido.
     */
    public function load(string $operationCode, bool $poll = false): ?array
    {
        $operation = strtoupper(trim($operationCode));

        if (ConsultCatalog::isForbiddenPolling($operation) || $this->looksLikeDeclarationOperation($operation)) {
            return null;
        }

        $key = $poll && $operation === 'SOLICITARPROTOCOLO91'
            ? 'RELATORIOSITFIS92'
            : ConsultCatalog::fixtureKey($operation);

        if (! $this->isSafeFixtureKey($key)) {
            return null;
        }

        $path = $this->pathFor($key);
        if ($path === null) {
            return null;
        }

        $fixture = $this->decode($path);

        if ($this->isDeclarationPayload($fixture)) {
            return null;
        }

        return $fixture;
    }

    public function directory(): ?string
    {
        $configured = trim((string) config('monitoring.fixtures_path', ''));
        if ($configured === '') {
            return null;
        }

        $path = $this->isAbsolutePath($configured) ? $configured : base_path($configured);

        return is_dir($path) ? $path : null;
    }

    private function pathFor(string $key): ?string
    {
        $directory = $this->directory();
        if ($directory === null) {
            return null;
        }

        $path = $directory.DIRECTORY_SEPARATOR.$key.'.json';

        return is_file($path) ? $path : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read consult fixture: {$path}");
        }

        try {
            $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Malformed consult fixture JSON: {$path}", 0, $exception);
        }

        if (! is_array($fixture)) {
            throw new RuntimeException("Malformed consult fixture JSON: {$path}");
        }

        return $fixture;
    }

    private function looksLikeDeclarationOperation(string $operation): bool
    {
        return str_contains($operation, 'ENTREGAR') || str_starts_with($operation, 'TRANSDECLARACAO');
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function isDeclarationPayload(array $fixture): bool
    {
        $encoded = json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        return str_contains($encoded, 'retorno_entregar_declaracao')
            || str_contains($encoded, 'TRANSDECLARACAO11')
            || str_contains($encoded, 'entregar_declaracao');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    private function isSafeFixtureKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Z0-9_-]+$/', $key);
    }
}
