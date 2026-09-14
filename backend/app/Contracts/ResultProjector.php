<?php

namespace App\Contracts;

use App\Models\MonitoringRun;

/**
 * Projects the result of a successfully completed Monitoring run.
 *
 * The executor calls this at most once per run, only on a `completed`,
 * non-superseded execution, and never for fixtures that fail closed. The real
 * normalizers/snapshot projector bind to this contract in Task 23; tests use
 * an in-memory fake.
 *
 * `$result` carries the raw execution outcome, already fenced and classified:
 *
 * - `source`: `serpro` for a real call, `fixture` for dry-run;
 * - `operation_code`: the catalog operation that was executed;
 * - `http_status`: the transport/fixture HTTP status;
 * - `protocol`: the protocol when the call returned one, otherwise null;
 * - `eta`: ISO-8601 instant for the protocol poll, when informed;
 * - `body`: the provider response body (fixture envelope or transport body).
 *
 * Implementations MUST NOT persist raw fiscal contents outside their own
 * storage and MUST NOT log tokens or credentials.
 */
interface ResultProjector
{
    /**
     * @param  array{
     *     source: 'serpro'|'fixture',
     *     operation_code: string,
     *     http_status: int,
     *     protocol: string|null,
     *     eta: string|null,
     *     body: array<string, mixed>
     * }  $result
     */
    public function project(MonitoringRun $run, array $result): void;
}
