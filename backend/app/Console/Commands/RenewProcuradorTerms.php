<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\SerproRequestAuthor;
use App\Services\OutorgaSyncService;
use App\Services\ProcuradorTermService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewProcuradorTerms extends Command
{
    protected $signature = 'monitoring:renew-terms';

    protected $description = 'Renova termos próximos do vencimento e reverifica procurações (rotina diária).';

    public function handle(ProcuradorTermService $terms, OutorgaSyncService $outorga): int
    {
        $renewed = 0;

        $authors = SerproRequestAuthor::query()->withoutGlobalScopes()
            ->where('status', SerproRequestAuthor::STATUS_ACTIVE)
            ->where(function ($q): void {
                $q->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '<=', now()->addDays(ProcuradorTermService::RENEW_WITHIN_DAYS));
            })
            ->with('account')
            ->get();

        foreach ($authors as $author) {
            try {
                $terms->ensureToken($author->account, $author);
                $renewed++;
            } catch (\Throwable $e) {
                Log::warning('monitoring.renew_term_failed', ['author_id' => $author->id]);
            }
        }

        $resumed = 0;
        $clients = Client::query()->withoutGlobalScopes()->where('monitoring_enabled', true)->get();

        foreach ($clients as $client) {
            try {
                $outorga->syncClient($client);
                $resumed++;
            } catch (\Throwable $e) {
                Log::warning('monitoring.reverify_failed', ['client_id' => $client->id]);
            }
        }

        $this->info("Termos renovados: {$renewed}. Clientes reverificados: {$resumed}.");

        return self::SUCCESS;
    }
}
