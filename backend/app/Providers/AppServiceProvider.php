<?php

namespace App\Providers;

use App\Contracts\VaultResolver;
use App\Services\Vault\LocalVault;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VaultResolver::class, LocalVault::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
