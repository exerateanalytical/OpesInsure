<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 2026-09-27 (docs/spec/RBAC_MATRIX_BROKER_CARRIER.md §3): insurance company admins see everything about
 * their own company. Push the new RoleCatalogue health grants (CARRIER_STAFF / CLAIMS_OFFICER maker side,
 * CARRIER_ADMIN / CARRIER_SUPER_ADMIN every health read and decision) to the stored tenant roles through the existing
 * rbac:sync-role-permissions command (additive only, wildcard roles untouched, idempotent). The health queues and the
 * health API are narrowed to the caller's carrier (PortalScope, EnsureHealthCarrierScope).
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
