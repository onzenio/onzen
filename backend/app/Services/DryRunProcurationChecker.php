<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Log;

class DryRunProcurationChecker implements ProcurationChecker
{
    public function granted(Client $client, string $serviceCode): bool
    {
        if ((bool) config('monitoring.dry_run', true)) {
            return true;
        }

        Log::warning('procuration.checker_unavailable', ['client_id' => $client->id]);

        return false;
    }
}
