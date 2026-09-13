<?php

namespace App\Providers;

use App\Listeners\AuditAuthListener;
use App\Models\Account;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\Observers\AuditObserver;
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
        //
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
