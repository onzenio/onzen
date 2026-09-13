<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Support\CurrentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_and_writes_are_scoped_to_current_account(): void
    {
        $a = $this->createAccount();
        $b = $this->createAccount();
        CurrentAccount::set($a->id);
        Client::factory()->create(['account_id' => $b->id]); // escrita direta escapa de propósito? NÃO — via factory com account explícito
        $this->assertCount(0, Client::all()); // scope esconde B
        $c = Client::factory()->make();
        unset($c->account_id);
        $c->save(); // creating preenche A
        $this->assertSame($a->id, $c->account_id);
        CurrentAccount::clear();
    }
}
