<?php

namespace App\Support;

/**
 * Redaction of sensitive data shared by audit metadata and operational events.
 *
 * Two complementary strategies:
 *
 * - {@see array()} redacts by key (recursively): any key containing a
 *   sensitive fragment (`password`, `secret`, `pfx`, `token`, ...) has its
 *   value replaced by {@see self::REDACTED}. It never rewrites textual values,
 *   so already-masked identifiers survive untouched.
 * - {@see text()} redacts free-form text (exception messages, error reasons):
 *   PEM blocks, bearer tokens, `key=value` assignments of sensitive keys,
 *   long base64 blobs and XML documents are replaced, leaving only factual
 *   text. When in doubt it over-redacts instead of leaking.
 *
 * Pure and side-effect free so both the Audit bridge and the SERPRO event
 * emitter share one policy instead of duplicating it.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    /**
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = ['password', 'secret', 'pfx', 'token'];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function array(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $redacted[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $redacted[$key] = self::array($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    /**
     * Redact free-form text. Factual codes and opaque identifiers pass through;
     * secrets, credentials, certificates and fiscal documents do not.
     */
    public static function text(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        // Fiscal payloads travel as XML: drop the whole message instead of
        // trying to parse it. `error_code` keeps the factual reason.
        if (str_contains($text, '<?xml') || preg_match('/<\/[A-Za-z0-9_:.-]+>/', $text) === 1) {
            return self::REDACTED;
        }

        $redacted = preg_replace(
            [
                // PEM blocks (certificates and private keys).
                '/-----BEGIN [A-Z0-9 ]+-----.*?-----END [A-Z0-9 ]+-----/s',
                // Bearer credentials.
                '/\bBearer\s+\S+/i',
                // Sensitive `key=value` / `key: value` assignments.
                '/\b([A-Za-z0-9_]*(?:password|senha|secret|segredo|pfx|token|jwt|authorization)[A-Za-z0-9_]*)\b(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i',
                // Long base64 blobs (PFX bodies, token segments).
                '/[A-Za-z0-9+\/]{40,}={0,2}/',
            ],
            [
                self::REDACTED,
                'Bearer '.self::REDACTED,
                '$1$2'.self::REDACTED,
                self::REDACTED,
            ],
            $text,
        );

        return $redacted ?? self::REDACTED;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
