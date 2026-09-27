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
 * real tenant switcher (see docs/design/DESIGN_SYSTEM.md) is built. Persistent on Livewire round-trips (see ScopesPanelTenant).
 */
final class ResolveAdminPanelTenant
{
    use \App\Filament\Shared\Middleware\ScopesPanelTenant;

    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $membership = $request->user()?->memberships()->where('status', 'ACTIVE')->first();

        return $this->withPanelTenant($this->context, $membership?->tenant_id, $request, $next);
    }
}
