<?php

use App\Http\Middleware\ResolveAccount;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Audit de auth tem registro EXPLÍCITO em AppServiceProvider::boot; a
    // descoberta automática varreria app/Listeners e registraria
    // AuditAuthListener@handle uma segunda vez (2 linhas por login/logout).
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // Sessão stateful ANTES do ResolveAccount (que lê switch_account_id
        // da sessão). O Ensure só abre sessão/CSRF para origens stateful,
        // então não há duplo StartSession/EncryptCookies no grupo api.
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ], append: [
            ResolveAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
