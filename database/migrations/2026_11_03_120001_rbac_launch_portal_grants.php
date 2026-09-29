<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Launch 2026-09-29 (portals functional, owner: RBAC-exact, nothing pending): CARRIER_STAFF gains catalogue.view;
 * CARRIER_ADMIN tariff.manage, kyc.manage, identity.invite, tenant.manage, documents.letterheads.manage;
 * CARRIER_SUPER_ADMIN documents.letterheads.approve (uploader != approver); REINSURANCE_OFFICER facultative + recoveries;
 * BROKER_STAFF quotes.read; BROKER_ADMIN payout.request, kyc.manage. Portal writes stay own-organisation only. Pushed to stored tenant roles through
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
