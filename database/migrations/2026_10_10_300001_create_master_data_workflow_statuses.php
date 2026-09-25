<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner Workflow Data Master v1 — reference-list catalogue statuses (additive).
 *
 * One row per owner catalogue item (e.g. geography.public_holidays, vehicles.usage_classes). It records the
 * owner's status (one of the 7 dataset status codes; the raw owner wording is kept in owner_status), where the
 * item lives in the platform (a master-data list, the vehicle master or the party-role catalogue), and whether
 * the values already there are verified. PENDING_SOURCE items carry no invented values.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('master_data_workflow_statuses')) {
            return;
        }
        Schema::create('master_data_workflow_statuses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('item_key', 96)->unique();          // owner_domain.owner_item
            $t->string('owner_domain', 64)->index();
            $t->string('owner_item', 64);
            $t->string('status', 24)->index();             // VERIFIED | PLATFORM_NORMALIZED | UNVERIFIED | PENDING_SOURCE | CONFIG_REQUIRED | DEMO_ONLY | RETIRED
            $t->string('owner_status', 40);                // wording in the owner file (e.g. PENDING_MASTER_REVIEW)
            $t->string('target', 24);                      // MASTER_DATA | VEHICLE_MASTER | PARTY_ROLES | EXTERNAL
            $t->string('domain_code', 64)->nullable();
            $t->string('list_code', 64)->nullable();
            $t->string('values_status', 24)->nullable();   // status of values already in the target list
            $t->string('source', 64)->default('OWNER_WORKFLOW_DATA_MASTER_V1');
            $t->string('source_version', 32)->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->text('note')->nullable();
            $t->jsonb('mapping')->nullable();              // owner code => platform code, unmapped codes
            $t->boolean('is_seeded')->default(true);
            $t->timestampTz('admin_modified_at')->nullable();
            $t->timestamps();
            $t->index(['domain_code', 'list_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_workflow_statuses');
    }
};
