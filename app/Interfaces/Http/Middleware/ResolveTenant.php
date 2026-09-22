<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('up', 'api/v1/public/*', 'api/v1/webhooks/*')) {
            return $next($request);
        }
        $user = $request->user();
        $requested = $request->header('X-Tenant-Id');
        if (! $user || ! $requested || ! $user->memberships()->where('tenant_id', $requested)->where('status', 'ACTIVE')->exists()) {
            abort(403, 'No active membership for the requested tenant.');
        }
        $this->context->set($requested);
        try { return $next($request); } finally { $this->context->clear(); }
    }
}
