<?php

namespace Tests\Unit;

use App\Support\Redactor;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_array_redacts_fiscal_identifiers_by_key(): void
    {
        $redacted = Redactor::array([
            'cnpj' => '11222333000181',
            'cpf' => '12345678909',
            'contratante_document' => '11222333000181',
            'certificate_password' => 'segredo',
            'razao_social' => 'Empresa Exemplo LTDA',
        ]);

        $this->assertSame(Redactor::REDACTED, $redacted['cnpj']);
        $this->assertSame(Redactor::REDACTED, $redacted['cpf']);
        $this->assertSame(Redactor::REDACTED, $redacted['contratante_document']);
        $this->assertSame(Redactor::REDACTED, $redacted['certificate_password']);
        $this->assertSame('Empresa Exemplo LTDA', $redacted['razao_social']);
    }

    public function test_text_masks_bare_cnpj_and_cpf_keeping_only_the_last_digits(): void
    {
        $masked = Redactor::text('contribuinte 11222333000181 com cpf 12345678909');

        $this->assertStringNotContainsString('11222333000181', (string) $masked);
        $this->assertStringNotContainsString('12345678909', (string) $masked);
        $this->assertStringContainsString('0181', (string) $masked);
        $this->assertStringContainsString('8909', (string) $masked);
    }

    public function test_text_masks_formatted_identifiers(): void
    {
        $masked = Redactor::text('CNPJ 11.222.333/0001-81 e CPF 123.456.789-09');

        $this->assertStringNotContainsString('11.222.333/0001-81', (string) $masked);
        $this->assertStringNotContainsString('123.456.789-09', (string) $masked);
    }

    public function test_text_keeps_short_factual_codes(): void
    {
        $this->assertSame('ok', Redactor::text('ok'));
        $this->assertSame('rate_limited', Redactor::text('rate_limited'));
    }
}
