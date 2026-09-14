<?php

namespace Tests\Support;

use App\Contracts\ResultProjector;
use App\Models\MonitoringRun;

/**
 * In-memory ResultProjector double: records every projection so tests can
 * assert that only successful, non-superseded completions project.
 */
final class FakeResultProjector implements ResultProjector
{
    /** @var list<array{run_id: int, result: array<string, mixed>}> */
    public array $projections = [];

    public function project(MonitoringRun $run, array $result): void
    {
        $this->projections[] = ['run_id' => (int) $run->getKey(), 'result' => $result];
    }

    public function calls(): int
    {
        return count($this->projections);
    }
}
