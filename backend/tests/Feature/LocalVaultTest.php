<?php

namespace Tests\Feature;

use App\Contracts\VaultResolver;
use App\Models\AccountCertificate;
use App\Models\SerproContract;
use App\Services\Vault\LocalVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class LocalVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_binding_resolves_the_local_vault(): void
    {
        $this->assertInstanceOf(LocalVault::class, app(VaultResolver::class));
    }

    public function test_string_values_roundtrip(): void
    {
        $vault = app(VaultResolver::class);

        $vault->put('secret:consumer-secret', 'super-secret-value');

        $this->assertSame('super-secret-value', $vault->get('secret:consumer-secret'));
    }

    public function test_array_values_roundtrip(): void
    {
        $vault = app(VaultResolver::class);
        $creds = [
            'client_id' => '12345678000199',
            'consumer_secret' => 'segredo-do-contratante',
            'contratante_doc' => '12345678000199',
            'nested' => ['a' => 1, 'b' => null],
        ];

        $vault->put('secret:contratante-credentials', $creds);

        $this->assertSame($creds, $vault->get('secret:contratante-credentials'));
    }

    public function test_binary_string_values_roundtrip(): void
    {
        $vault = app(VaultResolver::class);
        $binary = random_bytes(64);

        $vault->put('secret:certificate-pfx', $binary);

        $this->assertSame($binary, $vault->get('secret:certificate-pfx'));
    }

    public function test_put_rejects_ref_without_the_secret_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(VaultResolver::class)->put('plain-ref', 'value');
    }

    public function test_get_rejects_ref_without_the_secret_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(VaultResolver::class)->get('plain-ref');
    }

    public function test_forget_rejects_ref_without_the_secret_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(VaultResolver::class)->forget('plain-ref');
    }

    public function test_ref_with_empty_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(VaultResolver::class)->put('secret:', 'value');
    }

    public function test_get_returns_null_for_an_unknown_ref(): void
    {
        $this->assertNull(app(VaultResolver::class)->get('secret:does-not-exist'));
    }

    public function test_stored_value_is_encrypted_and_contains_no_plaintext(): void
    {
        $vault = app(VaultResolver::class);
        $vault->put('secret:consumer-secret', 'plaintext-canary-consumer');
        $vault->put('secret:contratante-credentials', ['consumer_secret' => 'plaintext-canary-json']);

        $stringRow = DB::table('vault_entries')->where('ref', 'secret:consumer-secret')->sole();
        $arrayRow = DB::table('vault_entries')->where('ref', 'secret:contratante-credentials')->sole();

        $this->assertNotSame('plaintext-canary-consumer', $stringRow->value);
        $this->assertStringNotContainsString('plaintext-canary-consumer', $stringRow->value);
        $this->assertStringNotContainsString('plaintext-canary-json', $arrayRow->value);
        $this->assertSame('plaintext-canary-consumer', Crypt::decryptString($stringRow->value));
        $this->assertSame(['consumer_secret' => 'plaintext-canary-json'], json_decode(Crypt::decryptString($arrayRow->value), true));
    }

    public function test_put_overwrites_the_existing_value_and_keeps_a_single_row(): void
    {
        $vault = app(VaultResolver::class);
        $vault->put('secret:rotating-token', 'first-value');
        $vault->put('secret:rotating-token', 'second-value');

        $this->assertSame('second-value', $vault->get('secret:rotating-token'));
        $this->assertSame(1, DB::table('vault_entries')->where('ref', 'secret:rotating-token')->count());
    }

    public function test_forget_removes_the_value(): void
    {
        $vault = app(VaultResolver::class);
        $vault->put('secret:temporary-token', 'temporary-value');

        $vault->forget('secret:temporary-token');

        $this->assertNull($vault->get('secret:temporary-token'));
        $this->assertDatabaseMissing('vault_entries', ['ref' => 'secret:temporary-token']);
    }

    public function test_forget_is_idempotent_for_unknown_refs(): void
    {
        app(VaultResolver::class)->forget('secret:never-stored');

        $this->assertNull(app(VaultResolver::class)->get('secret:never-stored'));
    }

    public function test_vault_operations_do_not_log_secret_material(): void
    {
        Log::spy();

        $vault = app(VaultResolver::class);
        $vault->put('secret:log-canary', 'log-canary-value');
        $vault->get('secret:log-canary');
        $vault->forget('secret:log-canary');

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
        Log::shouldNotHaveReceived('emergency');
    }

    public function test_vault_refs_are_hidden_from_model_serialization(): void
    {
        $vault = app(VaultResolver::class);
        $vault->put('secret:cred-ref-abcdef-1234', 'contratante-secret-value');
        $vault->put('secret:cert-ref-abcdef-5678', 'pfx-secret-value');

        $contract = SerproContract::factory()->create([
            'credential_ref' => 'secret:cred-ref-abcdef-1234',
        ]);
        $certificate = AccountCertificate::factory()->create([
            'vault_ref' => 'secret:cert-ref-abcdef-5678',
        ]);

        $contractJson = (string) json_encode($contract);
        $certificateJson = (string) json_encode($certificate);

        $this->assertStringNotContainsString('secret:cred-ref-abcdef-1234', $contractJson);
        $this->assertStringNotContainsString('contratante-secret-value', $contractJson);
        $this->assertStringNotContainsString('secret:cert-ref-abcdef-5678', $certificateJson);
        $this->assertStringNotContainsString('pfx-secret-value', $certificateJson);
    }

    public function test_certificate_replacement_forgets_the_previous_vault_entry(): void
    {
        $account = $this->createAccount();
        $vault = app(VaultResolver::class);
        $vault->put('secret:old-cert-ref', 'old-pfx-secret');
        $vault->put('secret:new-cert-ref', 'new-pfx-secret');

        $certificate = AccountCertificate::factory()->for($account, 'account')->create([
            'vault_ref' => 'secret:old-cert-ref',
            'thumbprint' => 'old-thumbprint',
        ]);

        $certificate->replace([
            'vault_ref' => 'secret:new-cert-ref',
            'thumbprint' => 'new-thumbprint',
        ]);

        $this->assertNull($vault->get('secret:old-cert-ref'));
        $this->assertSame('new-pfx-secret', $vault->get('secret:new-cert-ref'));
        $this->assertSame('secret:new-cert-ref', $certificate->refresh()->vault_ref);
    }
}
