<?php

namespace App\Console\Commands;

use App\Enums\AuthorStatus;
use App\Integrations\Serpro\ProcurationCatalog;
use App\Models\Client;
use App\Models\MonitoringDefinition;
use App\Models\MonitoringEnrollment;
use App\Models\PowerOfAttorney;
use App\Models\SerproRequestAuthor;
use App\Services\Monitoring\ProcuradorTermService;
use App\Services\Monitoring\ProcurationGate;
use App\Services\Monitoring\ProcurationVerifier;
use App\Services\Monitoring\SerproTransportGate;
use App\Support\Redactor;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rotina diária de renovação (Task 21 / design decision 6).
 *
 * Antes da janela comercial, renova os termos de autorização cujo token do
 * procurador vence em menos de 24h ({@see ProcuradorTermService::shouldRenew()})
 * e reverifica a outorga (`OBTERPROCURACAO41`) dos Clients com associação
 * pausada por outorga ou procuração próxima do vencimento
 * ({@see ProcurationCatalog::EXPIRY_WARNING_DAYS}). A reverificação usa o
 * caminho da Task 20 ({@see ProcurationGate::verifyForClient()}): resultado
 * positivo retoma as associações pausadas por outorga cujos códigos já são
 * válidos localmente; associações `ended` nunca são tocadas.
 *
 * Preview por padrão (`--confirm` para tocar a rede); com o transporte
 * fechado o comando não faz nenhuma chamada externa e reporta `gated` +
 * contagens de pulados. Uma falha de um autor, de uma definição ou de um
 * Client não interrompe a rotina: o erro é contabilizado em `failed`, o
 * trabalho já confirmado segue contabilizado e o próximo item continua. A
 * saída é um JSON de contagens; nenhum token, credencial ou conteúdo é
 * emitido.
 */
class WarmProcuracoesCommand extends Command
{
    protected $signature = 'monitoring:warm-procuracoes
        {--confirm : Renova e reverifica de fato; sem a flag apenas simula}';

    protected $description = 'Renova termos próximos do vencimento e reverifica procurações antes da janela comercial.';

    public function handle(
        ProcuradorTermService $terms,
        ProcurationVerifier $verifier,
        ProcurationGate $gate,
        SerproTransportGate $transport,
    ): int {
        $confirm = (bool) $this->option('confirm');
        $gated = $transport->isGated();
        $offline = ! $confirm || $gated;

        $counts = [
            'renewed' => 0,
            'reverified' => 0,
            'resumed' => 0,
            'skipped' => 0,
            'skipped_cached' => 0,
            'failed' => 0,
        ];

        foreach ($this->authorsDue($terms) as $author) {
            if ($offline) {
                $counts['skipped']++;

                continue;
            }

            try {
                $terms->renewTerm($author);
                $counts['renewed']++;
            } catch (Throwable $exception) {
                $counts['failed']++;
                $this->warnFailure('author', $exception);
            }
        }

        foreach ($this->clientsDue() as $client) {
            if ($offline) {
                $counts['skipped']++;

                continue;
            }

            if ($verifier->recentlyVerified($client)) {
                $counts['skipped_cached']++;

                continue;
            }

            $definitions = $this->definitionsFor($client);

            if ($definitions->isEmpty()) {
                continue;
            }

            $pausedCount = $this->pausedByOutorga($client);
            $verifiedAny = false;

            // Per-definition resilience: a throw on one definition must not
            // hide the resume/verification already committed for this Client
            // nor stop the remaining definitions and Clients.
            foreach ($definitions as $definition) {
                try {
                    $gate->verifyForClient($client, $definition);
                    $verifiedAny = true;
                } catch (Throwable $exception) {
                    $counts['failed']++;
                    $this->warnFailure('client', $exception);
                }

                $current = $this->pausedByOutorga($client);
                $counts['resumed'] += max(0, $pausedCount - $current);
                $pausedCount = $current;
            }

            if ($verifiedAny) {
                $counts['reverified']++;
            }
        }

        $this->line((string) json_encode([
            'dry_run' => ! $confirm,
            'gated' => $gated,
            ...$counts,
        ], JSON_THROW_ON_ERROR));

        Log::info('monitoring_warm_procuracoes_finished', [
            'dry_run' => ! $confirm,
            'gated' => $gated,
            ...$counts,
        ]);

        return self::SUCCESS;
    }

    /**
     * Active authors whose certificate is valid and whose procurador token is
     * missing/within 24h of expiry.
     *
     * @return Collection<int, SerproRequestAuthor>
     */
    private function authorsDue(ProcuradorTermService $terms): Collection
    {
        return SerproRequestAuthor::query()
            ->withoutGlobalScope('account')
            ->where('status', AuthorStatus::Active)
            ->orderBy('id')
            ->get()
            ->filter(fn (SerproRequestAuthor $author): bool => $author->isEligible() && $terms->shouldRenew($author))
            ->values();
    }

    /**
     * Clients with a paused-by-outorga association or an active grant inside
     * the expiry warning window — both local facts, no external call.
     *
     * @return Collection<int, Client>
     */
    private function clientsDue(): Collection
    {
        $paused = MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('status', MonitoringEnrollment::STATUS_PAUSED)
            ->where('pause_reason', ProcurationGate::PAUSE_REASON)
            ->pluck('client_id');

        $nearExpiry = PowerOfAttorney::query()
            ->withoutGlobalScope('account')
            ->where('status', PowerOfAttorney::STATUS_ACTIVE)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now()->addDays(ProcurationCatalog::EXPIRY_WARNING_DAYS))
            ->pluck('client_id');

        $ids = $paused->merge($nearExpiry)->unique()->values();

        return Client::query()
            ->withoutGlobalScope('account')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
    }

    /**
     * Definitions currently associated with the Client (active or paused)
     * that require procuração.
     *
     * @return Collection<int, MonitoringDefinition>
     */
    private function definitionsFor(Client $client): Collection
    {
        return MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->whereIn('status', [MonitoringEnrollment::STATUS_ACTIVE, MonitoringEnrollment::STATUS_PAUSED])
            ->orderBy('id')
            ->with('definition')
            ->get()
            ->pluck('definition')
            ->filter(fn (?MonitoringDefinition $definition): bool => $definition !== null && $definition->requires_procuracao)
            ->unique(fn (MonitoringDefinition $definition): string => (string) $definition->getKey())
            ->values();
    }

    private function pausedByOutorga(Client $client): int
    {
        return MonitoringEnrollment::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $client->account_id)
            ->where('client_id', $client->id)
            ->where('status', MonitoringEnrollment::STATUS_PAUSED)
            ->where('pause_reason', ProcurationGate::PAUSE_REASON)
            ->count();
    }

    private function warnFailure(string $area, Throwable $exception): void
    {
        Log::warning('monitoring_warm_procuracoes_failed', [
            'area' => $area,
            'error' => Redactor::text($exception->getMessage()),
        ]);
    }
}
