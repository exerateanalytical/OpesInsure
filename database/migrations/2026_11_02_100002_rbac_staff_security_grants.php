<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile audit B4: BROKER_ADMIN, CARRIER_ADMIN and CARRIER_SUPER_ADMIN gain staff.security.read and staff.security.manage
 * (partner/staff/{user}/security, suspend-access, force-reauth). Pushed to stored tenant roles through
 * rbac:sync-role-permissions (additive, wildcard roles untouched, idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }
        Artisan::call('rbac:sync-role-permissions');
    }

    public function down(): void
    {
        // Additive grants are not reverted (a later sync would re-apply them).
    }
};
