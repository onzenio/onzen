<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_sanctum_is_installed(): void
    {
        $this->assertTrue(class_exists(\Laravel\Sanctum\Sanctum::class), 'Sanctum is not installed.');
    }

    public function test_fortify_is_installed(): void
    {
        $this->assertTrue(class_exists(\Laravel\Fortify\Fortify::class), 'Fortify is not installed.');
    }
}
