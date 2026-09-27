<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * E9 (mobile audit 2026-09-27, docs/spec/RBAC_MATRIX_BROKER_CARRIER.md §3a): FINANCE_OFFICER gains carrier.dashboard.read +
 * carrier.finance.read, CLAIMS_OFFICER carrier.dashboard.read + carrier.claims.read. Push them to stored tenant roles
 * through rbac:sync-role-permissions (additive, wildcard roles untouched, idempotent). Rows are scoped to the
 * membership carrier_id by CarrierScopeResolver.
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
