<?php
namespace App\Policies;
use App\Domain\Tenancy\TenantContext;
use App\Models\{MarketplacePublication,User};
final class MarketplacePublicationPolicy
{
    public function view(User $user, MarketplacePublication $item): bool { return $user->hasPermission('marketplace.publications.view') && $item->tenant_id === app(TenantContext::class)->id(); }
    public function create(User $user): bool { return $user->hasPermission('marketplace.publications.create'); }
    public function approve(User $user, MarketplacePublication $item): bool { return $user->hasPermission('marketplace.publications.approve') && $item->tenant_id === app(TenantContext::class)->id() && $item->created_by !== $user->getKey(); }
}
