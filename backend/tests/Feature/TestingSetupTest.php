<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestingSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_testing_database_is_sqlite_memory(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_testing_mailer_is_array(): void
    {
        $this->assertSame('array', config('mail.default'));
    }

    public function test_helper_methods_exist(): void
    {
        $this->assertTrue(method_exists($this, 'createAccount'), 'Missing TestCase::createAccount helper.');
        $this->assertTrue(method_exists($this, 'createUser'), 'Missing TestCase::createUser helper.');
    }
}
