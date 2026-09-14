<?php

namespace Tests\Unit;

use App\Integrations\Serpro\ResponseClassifier;
use App\Integrations\Serpro\SerproClassification;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResponseClassifierTest extends TestCase
{
    private ResponseClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new ResponseClassifier;
    }

    public function test_a_successful_response_is_classified_as_success(): void
    {
        $classification = $this->classifier->classify(200, ['status' => 'Sucesso-PGDASD']);

        $this->assertSame(SerproClassification::SUCCESS, $classification->status);
        $this->assertSame('ok', $classification->code);
        $this->assertFalse($classification->retryable);
        $this->assertNull($classification->retryAfter);
    }

    public function test_any_2xx_or_3xx_without_a_protocol_is_success(): void
    {
        foreach ([200, 201, 204, 302] as $status) {
            $classification = $this->classifier->classify($status, []);

            $this->assertSame(SerproClassification::SUCCESS, $classification->status, (string) $status);
        }
    }

    public function test_a_protocol_without_obtained_is_awaiting_protocol(): void
    {
        $classification = $this->classifier->classify(202, [
            'protocol' => ['protocol_id' => 'PROTO-1', 'obtained' => false],
        ]);

        $this->assertSame(SerproClassification::AWAITING_PROTOCOL, $classification->status);
        $this->assertSame('protocol_pending', $classification->code);
        $this->assertTrue($classification->retryable);
    }

    public function test_a_one_shot_protocol_with_obtained_true_is_success(): void
    {
        $classification = $this->classifier->classify(200, [
            'obtained' => true,
            'protocol' => ['protocol_id' => 'PROTO-1', 'obtained' => true],
        ]);

        $this->assertSame(SerproClassification::SUCCESS, $classification->status);
    }

    public function test_awaiting_protocol_takes_priority_over_http_429(): void
    {
        $classification = $this->classifier->classify(429, [
            'protocolo' => 'PROTO-1',
        ], ['Retry-After' => ['30']]);

        $this->assertSame(SerproClassification::AWAITING_PROTOCOL, $classification->status);
        $this->assertSame(30, $classification->retryAfter);
    }

    public function test_an_expired_body_is_classified_as_expired(): void
    {
        $classification = $this->classifier->classify(200, ['expired' => true]);

        $this->assertSame(SerproClassification::EXPIRED, $classification->status);
        $this->assertSame('protocol_expired', $classification->code);
        $this->assertFalse($classification->retryable);
    }

    public function test_an_expired_status_word_is_classified_as_expired(): void
    {
        $this->assertSame(
            SerproClassification::EXPIRED,
            $this->classifier->classify(200, ['status' => 'expirado'])->status,
        );
        $this->assertSame(
            SerproClassification::EXPIRED,
            $this->classifier->classify(200, ['status' => 'EXPIRED'])->status,
        );
    }

    public function test_rate_limit_is_retryable_and_extracts_retry_after(): void
    {
        $classification = $this->classifier->classify(429, [], ['Retry-After' => ['30']]);

        $this->assertSame(SerproClassification::RATE_LIMITED, $classification->status);
        $this->assertSame('rate_limited', $classification->code);
        $this->assertTrue($classification->retryable);
        $this->assertSame(30, $classification->retryAfter);
    }

    public function test_retry_after_is_read_from_scalar_headers_and_the_body(): void
    {
        $this->assertSame(15, $this->classifier->classify(429, [], ['Retry-After' => '15'])->retryAfter);
        $this->assertSame(20, $this->classifier->classify(429, [], ['retry-after' => ['20']])->retryAfter);
        $this->assertSame(45, $this->classifier->classify(429, ['retry_after' => 45])->retryAfter);
    }

    public function test_retry_after_never_goes_below_one_second(): void
    {
        $this->assertSame(1, $this->classifier->classify(429, [], ['Retry-After' => ['0']])->retryAfter);
        $this->assertNull($this->classifier->classify(429, [], ['Retry-After' => ['soon']])->retryAfter);
    }

    public function test_retry_after_is_clamped_to_fifteen_minutes(): void
    {
        $this->assertSame(900, $this->classifier->classify(429, [], ['Retry-After' => ['3600']])->retryAfter);
        $this->assertSame(900, $this->classifier->classify(429, ['retry_after' => 99999])->retryAfter);
        $this->assertSame(900, $this->classifier->classify(503, [], ['Retry-After' => '86400'])->retryAfter);
    }

    public function test_timeouts_are_transient(): void
    {
        foreach ([408, 504] as $status) {
            $classification = $this->classifier->classify($status, [], ['Retry-After' => ['12']]);

            $this->assertSame(SerproClassification::TRANSIENT, $classification->status, (string) $status);
            $this->assertSame('timeout', $classification->code);
            $this->assertTrue($classification->retryable);
            $this->assertSame(12, $classification->retryAfter);
        }
    }

    public function test_server_errors_are_transient(): void
    {
        foreach ([500, 502, 503] as $status) {
            $classification = $this->classifier->classify($status, []);

            $this->assertSame(SerproClassification::TRANSIENT, $classification->status, (string) $status);
            $this->assertSame('transient_error', $classification->code);
            $this->assertTrue($classification->retryable);
        }
    }

    public function test_a_missing_http_status_is_a_transport_error(): void
    {
        $classification = $this->classifier->classify(0, []);

        $this->assertSame(SerproClassification::TRANSIENT, $classification->status);
        $this->assertSame('transport_error', $classification->code);
        $this->assertTrue($classification->retryable);
    }

    public function test_definitive_client_errors_are_rejected_without_retry(): void
    {
        foreach ([400, 401, 403, 404, 422] as $status) {
            $classification = $this->classifier->classify($status, ['message' => 'secret fiscal content']);

            $this->assertSame(SerproClassification::REJECTED, $classification->status, (string) $status);
            $this->assertSame('definitive_rejection', $classification->code);
            $this->assertFalse($classification->retryable);
            $this->assertNull($classification->retryAfter);
        }
    }

    public function test_connection_exceptions_are_transient_timeouts(): void
    {
        $classification = $this->classifier->classifyException(new ConnectionException('timed out'));

        $this->assertSame(SerproClassification::TRANSIENT, $classification->status);
        $this->assertSame('timeout', $classification->code);
        $this->assertTrue($classification->retryable);
    }

    public function test_other_exceptions_are_transient_transport_errors(): void
    {
        $classification = $this->classifier->classifyException(new RuntimeException('boom'));

        $this->assertSame(SerproClassification::TRANSIENT, $classification->status);
        $this->assertSame('transport_error', $classification->code);
        $this->assertTrue($classification->retryable);
    }
}
