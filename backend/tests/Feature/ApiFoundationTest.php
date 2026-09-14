<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\FortifyServiceProvider;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_sanctum_is_installed(): void
    {
        $this->assertTrue(class_exists(Sanctum::class), 'Sanctum is not installed.');
    }

    public function test_fortify_is_installed(): void
    {
        $this->assertTrue(class_exists(Fortify::class), 'Fortify is not installed.');
    }

    public function test_sanctum_and_fortify_configs_are_loaded(): void
    {
        $this->assertNotNull(config('sanctum'), 'Sanctum config is not loaded.');
        $this->assertNotNull(config('fortify'), 'Fortify config is not loaded.');
    }

    public function test_fortify_service_provider_is_registered(): void
    {
        $this->assertArrayHasKey(
            FortifyServiceProvider::class,
            $this->app->getLoadedProviders(),
            'FortifyServiceProvider is not registered.'
        );
    }

    public function test_api_user_route_exists(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());

        $this->assertContains('api/user', $uris, 'The [api/user] route is not registered.');
    }

    public function test_user_uses_has_api_tokens(): void
    {
        $this->assertContains(
            HasApiTokens::class,
            class_uses_recursive(User::class),
            'User does not use the HasApiTokens trait.'
        );
    }
}
