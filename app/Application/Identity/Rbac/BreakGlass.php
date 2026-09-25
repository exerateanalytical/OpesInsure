<?php

declare(strict_types=1);

namespace App\Application\Identity\Rbac;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\RoleCatalogue;
use App\Models\PrivilegedAccessGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-RBAC-004 break-glass: the ONLY way a platform administrator reaches
 * business data. Requires ALL of:
 *   1. the explicit permission platform.break-glass.use;
 *   2. an APPROVED privileged_access_grants row for this user in the current
 *      tenant (maker-checker approved by someone else — PrivilegedAccessService),
 *      inside its [starts_at, expires_at] window and not revoked;
 *   3. the grant's scope listing the permission, or its module wildcard
 *      ("claims.*").
 * Every use is written to privileged_access_events (USED) and the audit log
 * (privileged_access.break_glass), once per grant+permission per request.
 */
final class BreakGlass
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function grantFor(User $user, string $tenantId, string $permission): ?PrivilegedAccessGrant
    {
        $wanted = [$permission, Str::before($permission, '.').'.*'];

        return PrivilegedAccessGrant::query()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', $tenantId)
            ->where('status', 'APPROVED')
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now())
            ->where(function ($q) use ($wanted) {
                foreach ($wanted as $w) {
                    $q->orWhereJsonContains('scope', $w);
                }
            })
            ->orderByDesc('expires_at')
            ->first();
    }

    /** Checks and, when it allows, audits the use. */
    public function allows(User $user, string $tenantId, string $permission, PermissionEvaluator $evaluator): bool
    {
        if (! $evaluator->allows($user, RoleCatalogue::BREAK_GLASS_PERMISSION)) {
            return false;
        }
        $grant = $this->grantFor($user, $tenantId, $permission);
        if (! $grant) {
            return false;
        }
        $this->record($grant, $user, $permission);

        return true;
    }

    private function record(PrivilegedAccessGrant $grant, User $user, string $permission): void
    {
        $key = 'break_glass:'.$grant->id.':'.$permission;
        $request = app()->bound('request') ? request() : null;
        if ($request && $request->attributes->get($key)) {
            return;
        }
        $request?->attributes->set($key, true);

        DB::table('privileged_access_events')->insert([
            'id' => (string) Str::uuid(),
            'privileged_access_grant_id' => $grant->id,
            'event_type' => 'USED',
            'actor_id' => $user->getKey(),
            'purpose' => substr((string) $grant->purpose, 0, 64),
            'resource_type' => 'permission',
            'resource_id' => null,
            'metadata' => json_encode(['permission' => $permission, 'route' => $request?->path()]),
            'occurred_at' => now(),
        ]);
        $this->audit->record('privileged_access.break_glass', 'privileged_access_grant', $grant->id, ['permission' => $permission, 'purpose' => $grant->purpose], 'break_glass');
    }
}
