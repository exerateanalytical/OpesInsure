<?php

namespace App\Policies;

use App\Models\FulfilmentOrder;
use App\Models\User;

/**
 * Fulfilment orders: members of the order's tenant may view it; changing it needs fulfilments.manage
 * (config/permissions.php), the permission the fulfilment-order API routes use. Previously called a
 * non-existent User::tenantMemberships() and a non-existent `role` column, so the page crashed.
 */
final class FulfilmentOrderPolicy
{
    public function view(User $u, FulfilmentOrder $o): bool
    {
        return $u->memberships()->where('tenant_id', $o->tenant_id)->where('status', 'ACTIVE')->exists();
    }

    public function update(User $u, FulfilmentOrder $o): bool
    {
        return $this->view($u, $o) && $u->hasPermission('fulfilments.manage');
    }
}
