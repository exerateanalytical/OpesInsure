<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 2026-09-27 (docs/spec/RBAC_MATRIX_BROKER_CARRIER.md): push the new RoleCatalogue grants
 * (BROKER_ADMIN distribution.agreements.view, CARRIER_ADMIN distribution.agreements.view) and the claims.read →
 * claims.view rename to the stored tenant roles, through the existing rbac:sync-role-permissions command
 * (additive only, wildcard roles untouched, idempotent), then drop the retired claims.read catalogue row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }
        Artisan::call('rbac:sync-role-permissions');
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('code', 'claims.read')->delete();
        }
    }

    public function down(): void
    {
        // Additive grants and a rename are not reverted (a later sync would re-apply them).
    }
};
