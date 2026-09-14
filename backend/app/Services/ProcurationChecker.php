<?php

namespace App\Services;

use App\Models\Client;

interface ProcurationChecker
{
    public function granted(Client $client, string $serviceCode): bool;
}
