<?php

namespace Tests\Unit;

use App\Integrations\Serpro\ConsultMessageClassifier;
use App\Integrations\Serpro\SerproClassification;
use PHPUnit\Framework\TestCase;

final class ConsultMessageClassifierTest extends TestCase
{
    private ConsultMessageClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new ConsultMessageClassifier;
    }

    public function test_sucesso_pgdasd_confirms_success(): void
    {
        $classification = $this->classifier->classify(
            ['codigo' => 'Sucesso-PGDASD'],
            new SerproClassification(SerproClassification::SUCCESS, 'ok'),
        );

        $this->assertSame(SerproClassification::SUCCESS, $classification->status);
        $this->assertSame('ok', $classification->code);
        $this->assertFalse($classification->retryable);
    }

    public function test_sucesso_pgdasd_upgrades_a_non_success_http_outcome(): void
    {
        $classification = $this->classifier->classify(
            ['codigo' => 'Sucesso-PGDASD-complemento'],
            new SerproClassification(SerproClassification::TRANSIENT, 'transient_error', true),
        );

        $this->assertSame(SerproClassification::SUCCESS, $classification->status);
        $this->assertFalse($classification->retryable);
    }

    public function test_invalid_input_message_codes_are_definitive_rejections(): void
    {
        foreach (['003', '005', '006', '007', '027', '028'] as $isn) {
            $classification = $this->classifier->classify(
                ['codigo' => 'MSG_ISN_'.$isn],
                new SerproClassification(SerproClassification::SUCCESS, 'ok'),
            );

            $this->assertSame(SerproClassification::REJECTED, $classification->status, $isn);
            $this->assertSame('MSG_ISN_'.$isn, $classification->code);
            $this->assertFalse($classification->retryable);
            $this->assertNull($classification->retryAfter);
        }
    }

    public function test_entrada_incorreta_pgdasd_is_a_definitive_rejection(): void
    {
        $classification = $this->classifier->classify(
            ['codigo' => 'MSG_ISN_027-EntradaIncorreta-PGDASD'],
            new SerproClassification(SerproClassification::SUCCESS, 'ok'),
        );

        $this->assertSame(SerproClassification::REJECTED, $classification->status);
    }

    public function test_transient_message_codes_are_retryable(): void
    {
        foreach (['001', '012'] as $isn) {
            $classification = $this->classifier->classify(
                ['codigo' => 'MSG_ISN_'.$isn],
                new SerproClassification(SerproClassification::SUCCESS, 'ok'),
            );

            $this->assertSame(SerproClassification::TRANSIENT, $classification->status, $isn);
            $this->assertSame('MSG_ISN_'.$isn, $classification->code);
            $this->assertTrue($classification->retryable);
            $this->assertSame(60, $classification->retryAfter);
        }
    }

    public function test_transient_message_code_keeps_the_http_retry_after(): void
    {
        $classification = $this->classifier->classify(
            ['codigo' => 'MSG_ISN_012'],
            new SerproClassification(SerproClassification::TRANSIENT, 'transient_error', true, 25),
        );

        $this->assertSame(SerproClassification::TRANSIENT, $classification->status);
        $this->assertSame(25, $classification->retryAfter);
    }

    public function test_erro_pgdasd_is_retryable(): void
    {
        $classification = $this->classifier->classify(
            ['codigo' => 'MSG_ISN_012-Erro-PGDASD'],
            new SerproClassification(SerproClassification::SUCCESS, 'ok'),
        );

        $this->assertSame(SerproClassification::TRANSIENT, $classification->status);
        $this->assertTrue($classification->retryable);
    }

    public function test_the_code_falls_back_to_code_and_status_keys(): void
    {
        $http = new SerproClassification(SerproClassification::SUCCESS, 'ok');

        $this->assertSame(
            SerproClassification::REJECTED,
            $this->classifier->classify(['code' => 'MSG_ISN_005'], $http)->status,
        );
        $this->assertSame(
            SerproClassification::REJECTED,
            $this->classifier->classify(['status' => 'MSG_ISN_028'], $http)->status,
        );
    }

    public function test_unknown_codes_keep_the_http_classification(): void
    {
        $http = new SerproClassification(SerproClassification::REJECTED, 'definitive_rejection');

        $classification = $this->classifier->classify(['codigo' => 'business_rejection'], $http);

        $this->assertSame($http, $classification);
    }

    public function test_rate_limited_and_expired_http_outcomes_are_never_overridden(): void
    {
        $limited = new SerproClassification(SerproClassification::RATE_LIMITED, 'rate_limited', true, 30);
        $expired = new SerproClassification(SerproClassification::EXPIRED, 'protocol_expired');

        $this->assertSame($limited, $this->classifier->classify(['codigo' => 'MSG_ISN_027'], $limited));
        $this->assertSame($expired, $this->classifier->classify(['codigo' => 'MSG_ISN_027'], $expired));
    }

    public function test_an_empty_code_keeps_the_http_classification(): void
    {
        $http = new SerproClassification(SerproClassification::SUCCESS, 'ok');

        $this->assertSame($http, $this->classifier->classify([], $http));
    }
}
