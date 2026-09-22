<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary tenant resolution for the Filament admin panel: picks the
 * signed-in user's first active tenant membership. Stands in until the
 * real tenant switcher (see docs/design/DESIGN_SYSTEM.md) is built.
 */
final class ResolveAdminPanelTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $membership = $request->user()?->memberships()->where('status', 'ACTIVE')->first();

        if ($membership) {
            $this->context->set($membership->tenant_id);
        }

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
