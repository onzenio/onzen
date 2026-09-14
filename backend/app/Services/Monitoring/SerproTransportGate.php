<?php

namespace App\Services\Monitoring;

use App\Exceptions\SerproBlockedException;
use App\Integrations\Serpro\SerproCredentialResolver;
use App\Models\SerproContract;
use App\Models\SerproSettings;

/**
 * Single decision point for the effective SERPRO Integra Contador gate.
 *
 * It reconciles the panel (`serpro_settings`, managed by a super_admin) with
 * the `.env`-backed `config('monitoring.*')` and the vault, so health and
 * execution observe the same state. Precedence, fail-closed:
 *
 * 1. Once the panel holds a decision (`serpro_settings` row exists), it is
 *    authoritative: `transport_approved = true` opens the transport even with
 *    `.env` dry-run/approved off (the legacy inconsistency where the panel was
 *    on and health still reported `gated` is fixed here), and
 *    `transport_approved = false` closes it immediately, ignoring `.env`.
 * 2. Before any panel decision exists, the pre-panel `.env` fallback may open
 *    the transport only when `MONITORING_SERPRO_DRY_RUN=false` and
 *    `MONITORING_SERPRO_TRANSPORT_APPROVED=true` (legacy `isTransportOpen()`).
 * 3. Anything else is closed (`isGated()`), which is the default of a fresh
 *    install.
 *
 * The transport state derives from the effective gate plus the Contratante
 * credential in the vault, never from network calls:
 *
 * - `gated`: no real transport may happen (closed gate).
 * - `unavailable`: gate open, but the Contratante credential is missing or
 *   does not resolve.
 * - `degraded`: gate open and the Contratante credential resolves, but the
 *   gate was opened only through the `.env` fallback, with no panel
 *   decision recorded — real traffic is possible but the platform has not
 *   attested the approval, so the capability is only partially governed.
 * - `configured`: gate open by an explicit panel approval and the
 *   Contratante credential resolves.
 */
final class SerproTransportGate
{
    public const ENVIRONMENTS = ['homologacao', 'producao'];

    public const DEFAULT_ENVIRONMENT = 'homologacao';

    public const STATE_GATED = 'gated';

    public const STATE_CONFIGURED = 'configured';

    public const STATE_UNAVAILABLE = 'unavailable';

    public const STATE_DEGRADED = 'degraded';

    public function __construct(private readonly SerproCredentialResolver $resolver) {}

    public function environment(): string
    {
        $panel = $this->panel()?->environment();

        if (is_string($panel) && in_array($panel, self::ENVIRONMENTS, true)) {
            return $panel;
        }

        $configured = (string) config('monitoring.environment', self::DEFAULT_ENVIRONMENT);

        return in_array($configured, self::ENVIRONMENTS, true) ? $configured : self::DEFAULT_ENVIRONMENT;
    }

    public function isDryRun(): bool
    {
        return (bool) config('monitoring.dry_run', true);
    }

    public function isTransportOpen(): bool
    {
        $panel = $this->panel();

        if ($panel !== null) {
            return $panel->transportApproved();
        }

        if ($this->isDryRun()) {
            return false;
        }

        return (bool) config('monitoring.transport.approved', false);
    }

    public function isGated(): bool
    {
        return ! $this->isTransportOpen();
    }

    public function effectiveCredentialRef(): ?string
    {
        $contract = SerproContract::query()
            ->where('environment', $this->environment())
            ->first();

        if ($contract === null || ! $contract->hasCredentials()) {
            return null;
        }

        return $contract->credential_ref;
    }

    public function transportState(): string
    {
        if ($this->isGated()) {
            return self::STATE_GATED;
        }

        $credentialRef = $this->effectiveCredentialRef();

        if ($credentialRef === null) {
            return self::STATE_UNAVAILABLE;
        }

        try {
            $this->resolver->resolveRef($credentialRef);
        } catch (SerproBlockedException) {
            return self::STATE_UNAVAILABLE;
        }

        // Only the pre-panel `.env` fallback (a panel decision is
        // authoritative and was already checked by `isTransportOpen()`).
        if ($this->panel() === null) {
            return self::STATE_DEGRADED;
        }

        return self::STATE_CONFIGURED;
    }

    /**
     * The panel decision, or null when the platform has not decided yet.
     *
     * Read-only on purpose: a GET (health) must not create the singleton row
     * as a side effect, otherwise the `.env` fallback would silently die.
     */
    private function panel(): ?SerproSettings
    {
        return SerproSettings::query()->first();
    }
}
