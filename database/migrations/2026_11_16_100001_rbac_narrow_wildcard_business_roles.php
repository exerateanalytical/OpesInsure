<?php

declare(strict_types=1);

use App\Application\Audit\AuditWriter;
use App\Application\Identity\RoleCatalogue;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Role narrowing 2026-09-30 (owner rule: every user sees exactly what their RBAC permissions allow).
 * CLAIMS_MANAGER, FINANCE_MANAGER, FINANCE_ADMIN and COMPLIANCE_ADMIN no longer default to '*'
 * (RoleCatalogue::*_PERMISSIONS). rbac:sync-role-permissions is additive and skips wildcard roles, so the stored
 * tenant roles of those codes are rewritten here: '*' is removed and the explicit catalogue list is merged in
 * (any other explicit custom grant is kept). Each rewrite is audited. Idempotent: a role without '*' is untouched.
 * SYSTEM_ADMIN / PLATFORM_ADMIN keep '*'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        DB::table('roles')->whereIn('code', RoleCatalogue::NARROWED_FROM_WILDCARD)->orderBy('id')->each(function (object $role): void {
            $old = array_values((array) (json_decode((string) $role->permissions, true) ?: []));
            if (! in_array('*', $old, true)) {
                return;
            }
            $new = array_values(array_unique([...array_values(array_diff($old, ['*'])), ...RoleCatalogue::defaultPermissions($role->code)]));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($new)]);

            if (Schema::hasTable('audit_log')) {
                $context = app(TenantContext::class);
                if ($role->tenant_id !== null) {
                    $context->set((string) $role->tenant_id);
                }
                try {
                    app(AuditWriter::class)->record('rbac.role.narrowed', 'role', (string) $role->id,
                        ['code' => $role->code, 'removed' => ['*'], 'permission_count' => count($new)], 'RBAC_WILDCARD_NARROWING',
                        ['old' => ['permissions' => $old], 'new' => ['permissions' => $new], 'source' => 'migration']);
                } finally {
                    $context->clear();
                }
            }
        });

        Artisan::call('rbac:sync-role-permissions');
    }

    public function down(): void
    {
        // Security narrowing, not reverted (restoring '*' would re-open the over-grant).
    }
};
