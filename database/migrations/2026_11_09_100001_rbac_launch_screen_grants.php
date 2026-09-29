<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Launch screens 2026-09-29 (owner: RBAC-exact, nothing pending): BROKER_ADMIN gains parties.match.review (duplicate
 * customer review, BRK-017); CARRIER_ADMIN and UNDERWRITER gain rules.view (CAR-018 eligibility rules, config
 * suggested_roles); AGENT gains quotes.rate (re-price a client's quote on the compare / customise pages).
 * kyc.view is read-equivalent to kyc.manage in the portals (PortalAuthorization::EQUIVALENT_READS). Pushed to stored
 * tenant roles through rbac:sync-role-permissions (additive, wildcard roles untouched, idempotent).
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
