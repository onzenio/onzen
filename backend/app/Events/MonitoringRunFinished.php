<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class MonitoringRunFinished
{
    use Dispatchable;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public readonly array $payload) {}
}
