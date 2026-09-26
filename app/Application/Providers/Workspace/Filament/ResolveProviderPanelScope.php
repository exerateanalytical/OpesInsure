<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament;

use App\Application\Providers\Portal\ProviderScope;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provider panel scope: tenant = the user's first ACTIVE membership; active provider = ?provider_id= (remembered in
 * the session — Facility Selector / multi-provider users) when it is one of theirs, else their first provider.
 */
final class ResolveProviderPanelScope
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $membership = ProviderPanelAccess::membership($user);
        abort_if($membership === null, 403);
        $ids = ProviderScope::providerIdsFor($user);
        $wanted = $request->query('provider_id') ?: ($request->hasSession() ? $request->session()->get('provider_panel.provider_id') : null);
        $active = in_array($wanted, $ids, true) ? $wanted : $ids[0];
        if ($request->hasSession()) {
            $request->session()->put('provider_panel.provider_id', $active);
        }
        $p = DB::table('provider_profiles')->where('id', $active)->first(['party_id', 'partner_id']);
        $scope = new ProviderScope($active, $ids, $p->party_id, $p->partner_id);
        $request->attributes->set(ProviderScope::ATTRIBUTE, $scope);
        app()->instance(ProviderScope::class.'@panel', $scope);
        $this->context->set($membership->tenant_id);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
