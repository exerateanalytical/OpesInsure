<?php

declare(strict_types=1);

namespace App\Filament\Shared\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the panel tenant for the rest of the request. The panel tenant middleware is registered as Livewire persistent
 * middleware (authMiddleware(..., isPersistent: true)), so it also runs on /livewire/update round-trips: widget
 * polling, header actions, table filters. Livewire replays persistent middleware in a pipeline that ends BEFORE the
 * component call, so on that replay the context must not be cleared on the way out, or the component would run
 * without a tenant. That is safe: TenantContext is bound `scoped`, so the container drops it when the request ends.
 * On a normal page request the context is cleared on the way out, as before.
 */
trait ScopesPanelTenant
{
    protected function withPanelTenant(TenantContext $context, ?string $tenantId, Request $request, Closure $next): Response
    {
        if ($tenantId !== null) {
            $context->set($tenantId);
        }
        if (app(HandleRequests::class)->isLivewireRoute()) {
            return $next($request);
        }

        try {
            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
