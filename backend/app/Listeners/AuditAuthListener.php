<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditService;
use App\Support\CurrentAccount;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;

class AuditAuthListener
{
    public function __construct(protected AuditService $audit) {}

    public function handle(Login|Logout $event): void
    {
        try {
            $user = $event->user instanceof User ? $event->user : null;

            if ($user === null) {
                return;
            }

            $action = $event instanceof Login ? 'auth.login' : 'auth.logout';
            $origin = CurrentAccount::get() ?? $user->account_id;

            $this->audit->record($user, $origin, null, $action);
        } catch (\Throwable $e) {
            Log::warning('audit.auth_listener_failed', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
