<?php

namespace App\Providers;

use App\Contracts\ArtifactStore;
use App\Contracts\ResultProjector;
use App\Contracts\SerproEvents;
use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Integrations\Serpro\Transport\HttpOAuthMtlsTransport;
use App\Services\Artifacts\LocalArtifactStore;
use App\Services\Monitoring\SerproEventEmitter;
use App\Services\Monitoring\SnapshotProjector;
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
        $this->app->bind(ArtifactStore::class, LocalArtifactStore::class);
        $this->app->bind(SerproTransport::class, HttpOAuthMtlsTransport::class);
        $this->app->bind(SerproEvents::class, SerproEventEmitter::class);
        $this->app->bind(ResultProjector::class, SnapshotProjector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
