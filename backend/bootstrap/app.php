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
        // Headless (sem rotas de view do Fortify): guests nunca são
        // redirecionados para uma inexistente página de login; a API
        // responde 401 e o handler abaixo serializa em JSON para api/*.
        $middleware->redirectGuestsTo(null);
        // Sessão stateful ANTES do ResolveAccount (que lê switch_account_id
        // da sessão). O Ensure só abre sessão/CSRF para origens stateful,
        // então não há duplo StartSession/EncryptCookies no grupo api.
        // ResolveAccount ANTES do SubstituteBindings para que o model binding
        // honre o escopo da Account efetiva (bindings cross-account dão 404).
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
            ResolveAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
