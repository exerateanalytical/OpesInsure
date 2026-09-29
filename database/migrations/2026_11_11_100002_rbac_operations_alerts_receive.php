<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S12: SYSTEM_ADMIN and PLATFORM_ADMIN gain operations.alerts.receive (RoleCatalogue). Alert recipients are chosen by
 * the explicit string, never by '*', so a wildcard FINANCE_ADMIN does not get paged. rbac:sync-role-permissions skips
 * wildcard roles, so the string is appended to stored SYSTEM_ADMIN / PLATFORM_ADMIN roles here too (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }
        Artisan::call('rbac:sync-role-permissions');

        DB::table('roles')->whereIn('code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN'])->orderBy('id')->each(function (object $role): void {
            $perms = json_decode((string) $role->permissions, true) ?: [];
            if (! in_array('operations.alerts.receive', $perms, true)) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode([...$perms, 'operations.alerts.receive'])]);
            }
        });
    }

    public function down(): void
    {
        // Additive grant, not reverted.
    }
};
