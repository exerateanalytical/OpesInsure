<?php

use App\Interfaces\Http\Middleware\ResolveTenant;
use App\Interfaces\Http\Middleware\RequirePermission;
use App\Interfaces\Http\Middleware\SecurityHeaders;
use App\Interfaces\Http\Middleware\EnforceJsonApi;
use App\Interfaces\Http\Middleware\AuthenticateIntegrationClient;
use App\Interfaces\Http\Middleware\IdempotencyGuard;
use App\Interfaces\Http\Middleware\RequireStepUpGrant;
use App\Interfaces\Http\Middleware\EnforceMinimumAppVersion;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['tenant' => ResolveTenant::class, 'permission' => RequirePermission::class, 'json.api' => EnforceJsonApi::class, 'integration.client' => AuthenticateIntegrationClient::class, 'idempotency' => IdempotencyGuard::class, 'step-up' => RequireStepUpGrant::class]);
        // EnforceMinimumAppVersion is a no-op unless the caller sends
        // X-App-Version, so prepending it globally is safe for the staff
        // Filament panel, partner integration calls and webhooks today —
        // see that middleware's docblock for why it's global rather than
        // per-route.
        $middleware->api(prepend: [\App\Interfaces\Http\Middleware\ForceJsonResponse::class, SecurityHeaders::class, EnforceMinimumAppVersion::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
