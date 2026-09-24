<?php

use App\Interfaces\Http\Errors\ProblemDetails;
use App\Interfaces\Http\Middleware\AssignCorrelationId;
use App\Interfaces\Http\Middleware\ResolveTenant;
use App\Interfaces\Http\Middleware\RequirePermission;
use App\Interfaces\Http\Middleware\SecurityHeaders;
use App\Interfaces\Http\Middleware\EnforceJsonApi;
use App\Interfaces\Http\Middleware\AuthenticateIntegrationClient;
use App\Interfaces\Http\Middleware\IdempotencyGuard;
use App\Interfaces\Http\Middleware\OptimisticConcurrency;
use App\Interfaces\Http\Middleware\RequireStepUpGrant;
use App\Interfaces\Http\Middleware\EnforceMinimumAppVersion;
use App\Interfaces\Http\Middleware\StandardApiEnvelope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['tenant' => ResolveTenant::class, 'permission' => RequirePermission::class, 'json.api' => EnforceJsonApi::class, 'integration.client' => AuthenticateIntegrationClient::class, 'idempotency' => IdempotencyGuard::class, 'step-up' => RequireStepUpGrant::class, 'if-match' => OptimisticConcurrency::class]);
        // Correlation id first so every later layer (logs, audit, jobs,
        // error bodies) sees it; StandardApiEnvelope wraps everything below
        // it so handler- and middleware-returned errors get the standard
        // envelope too.
        //
        // EnforceMinimumAppVersion is a no-op unless the caller sends
        // X-App-Version, so prepending it globally is safe for the staff
        // Filament panel, partner integration calls and webhooks today —
        // see that middleware's docblock for why it's global rather than
        // per-route.
        $middleware->api(prepend: [AssignCorrelationId::class, StandardApiEnvelope::class, \App\Interfaces\Http\Middleware\ForceJsonResponse::class, SecurityHeaders::class, EnforceMinimumAppVersion::class]);
        // Appended = after route-model binding. Both are opt-in by header:
        // no Idempotency-Key / If-Match -> request untouched.
        $middleware->api(append: [OptimisticConcurrency::class, IdempotencyGuard::class.':auto,optional']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
        // Errors raised before the api middleware group runs (unknown route,
        // 405) still get the standard envelope.
        $exceptions->respond(fn ($response, $e, $request) => $request->is('api/*') ? ProblemDetails::decorate($response) : $response);
    })->create();
