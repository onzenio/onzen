<?php

namespace App\Events\Monitoring;

/**
 * `serpro_run_started` operational event.
 *
 * Carries only opaque identifiers and factual execution metadata. Payload
 * bodies, credentials, certificates and fiscal content are never part of the
 * context; free-form reasons are redacted before construction.
 */
final class SerproRunStarted
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(public readonly array $context) {}
}
