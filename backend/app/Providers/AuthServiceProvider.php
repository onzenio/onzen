<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\Plan;
use App\Policies\AccountPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\ClientPolicy;
use App\Policies\InvitationPolicy;
use App\Policies\PlanPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Account::class => AccountPolicy::class,
        Client::class => ClientPolicy::class,
        Invitation::class => InvitationPolicy::class,
        Plan::class => PlanPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
    ];

    public function boot(): void
    {
        // Sem Gate::before global de propósito: super_admin NÃO bypassa o
        // escopo de dados; só acessa rotas de plataforma via policies explícitas.
    }
}
