<?php

namespace App\Providers;

use App\Contracts\ArtifactStore as MonitoringArtifactStore;
use App\Contracts\ResultProjector;
use App\Contracts\SerproEvents;
use App\Contracts\SerproTransport;
use App\Contracts\VaultResolver;
use App\Integrations\Serpro\HttpProcuradorTermSender;
use App\Integrations\Serpro\ProcuradorTermSender;
use App\Integrations\Serpro\Transport\HttpOAuthMtlsTransport;
use App\Listeners\AuditAuthListener;
use App\Models\Account;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Artifacts\LocalArtifactStore;
use App\Services\ArtifactStore;
use App\Services\DiskArtifactStore;
use App\Services\DryRunProcurationChecker;
use App\Services\Monitoring\SerproEventEmitter;
use App\Services\Monitoring\SnapshotProjector;
use App\Services\ProcurationChecker;
use App\Services\Vault\LocalVault;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ProcuradorTermSender::class, HttpProcuradorTermSender::class);
        $this->app->bind(ProcurationChecker::class, DryRunProcurationChecker::class);
        $this->app->bind(ArtifactStore::class, DiskArtifactStore::class);
        $this->app->bind(VaultResolver::class, LocalVault::class);
        $this->app->bind(MonitoringArtifactStore::class, LocalArtifactStore::class);
        $this->app->bind(SerproTransport::class, HttpOAuthMtlsTransport::class);
        $this->app->bind(SerproEvents::class, SerproEventEmitter::class);
        $this->app->bind(ResultProjector::class, SnapshotProjector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Account::observe(AuditObserver::class);
        User::observe(AuditObserver::class);
        Invitation::observe(AuditObserver::class);
        Plan::observe(AuditObserver::class);
        Client::observe(AuditObserver::class);

        Event::listen(Login::class, AuditAuthListener::class);
        Event::listen(Logout::class, AuditAuthListener::class);
    }
}
