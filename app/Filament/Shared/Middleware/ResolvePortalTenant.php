<?php

declare(strict_types=1);

namespace App\Filament\Shared\Middleware;

use App\Application\WebExperiences\PortalAccess;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant scoping for the insurer / broker portals: the tenant is the one of
 * the membership that granted entry to THIS panel (not simply the first
 * membership, as the admin panel's ResolveAdminPanelTenant does), so a user
 * who is both a broker and an insurer employee sees the right book in each.
 */
final class ResolvePortalTenant
{
    public function __construct(private readonly TenantContext $context, private readonly PortalAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $membership = $this->access->membershipFor($request->user(), Filament::getCurrentOrDefaultPanel()->getId());
        abort_if($membership === null, 403);

        $this->context->set($membership->tenant_id);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
