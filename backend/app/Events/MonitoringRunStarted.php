<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class MonitoringRunStarted
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $payload) {}
}
