<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * S2 branch scoping: the branch a record created by $actor in $tenantId belongs to — the branch of the actor's ACTIVE
 * memberships in that tenant when there is exactly one, else null (not determinable). Same rule as the SQL function
 * opes_membership_branch used by the branch triggers (migration 2026_11_13_200001).
 */
final class BranchStamp
{
    public static function of(?User $actor, ?string $tenantId): ?string
    {
        if ($actor === null || $tenantId === null) {
            return null;
        }
        $branches = DB::table('tenant_memberships')->where('user_id', $actor->getKey())->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')->whereNotNull('branch_id')->distinct()->pluck('branch_id');

        return $branches->count() === 1 ? (string) $branches->first() : null;
    }
}
