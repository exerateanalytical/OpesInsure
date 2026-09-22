<?php

use App\Interfaces\Http\Middleware\ResolveTenant;
use App\Interfaces\Http\Middleware\RequirePermission;
use App\Interfaces\Http\Middleware\SecurityHeaders;
use App\Interfaces\Http\Middleware\EnforceJsonApi;
use App\Interfaces\Http\Middleware\AuthenticateIntegrationClient;
use App\Interfaces\Http\Middleware\IdempotencyGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['tenant' => ResolveTenant::class, 'permission' => RequirePermission::class, 'json.api' => EnforceJsonApi::class, 'integration.client' => AuthenticateIntegrationClient::class, 'idempotency' => IdempotencyGuard::class]);
        $middleware->api(prepend: [SecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
