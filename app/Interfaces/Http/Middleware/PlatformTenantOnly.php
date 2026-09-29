<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Application\Identity\Rbac\PlatformAuthority;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security 2026-09-29: platform-wide records without a tenant column (integration / API clients, webhook
 * subscriptions, developer keys) may only be read or changed from the PLATFORM tenant. Holding
 * integrations.manage in an insurer or broker tenant is not enough.
 */
final class PlatformTenantOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);
        abort_unless(app(PlatformAuthority::class)->isPlatformTenant($tenant), 403, __('security.platform_only'));

        return $next($request);
    }
}
