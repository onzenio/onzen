<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\MonitoringArtifact;
use App\Models\MonitoringEnrollment;
use App\Models\Plan;
use App\Models\SerproRequestAuthor;
use App\Policies\AccountPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\ClientPolicy;
use App\Policies\InvitationPolicy;
use App\Policies\MonitoringArtifactPolicy;
use App\Policies\MonitoringEnrollmentPolicy;
use App\Policies\PlanPolicy;
use App\Policies\SerproAdminPolicy;
use App\Policies\SerproRequestAuthorPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

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
        MonitoringArtifact::class => MonitoringArtifactPolicy::class,
        MonitoringEnrollment::class => MonitoringEnrollmentPolicy::class,
        SerproRequestAuthor::class => SerproRequestAuthorPolicy::class,
    ];

    public function boot(): void
    {
        // Sem Gate::before global de propósito: super_admin NÃO bypassa o
        // escopo de dados; só acessa rotas de plataforma via policies explícitas.
        Gate::define('manage-serpro', [SerproAdminPolicy::class, 'manage']);
    }
}
