<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-PRD-011 (PRE §66–73) — life & special products.
 *
 *  special_policy_profiles   one per policy: kind GROUP_MASTER | FLEET | OPEN_COVER | CONSTRUCTION | AGRICULTURE | LIFE + terms.
 *  policy_schedule_items     dated schedule entries (group members, fleet vehicles, construction sites/works,
 *                            agriculture plots/herds). Add = new row; remove = effective_until set. Never deleted.
 *  cargo_declarations        shipment declarations under an open cover (immutable; cancellation is a status).
 *  life_surrender_scales     carrier-supplied surrender factor tables per product (maker-checker activation).
 *  life_surrender_quotes     computed surrender values with rule trace (read-only on policies).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_policy_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->string('kind', 24);
            $t->jsonb('terms')->default('{}');
            $t->string('status', 16)->default('ACTIVE'); // ACTIVE, CLOSED
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestampsTz();
            $t->unique('policy_id');
            $t->index(['tenant_id', 'kind']);
        });
        DB::statement("ALTER TABLE special_policy_profiles ADD CONSTRAINT special_policy_profiles_kind CHECK (kind IN ('GROUP_MASTER','FLEET','OPEN_COVER','CONSTRUCTION','AGRICULTURE','LIFE'))");

        Schema::create('policy_schedule_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('profile_id')->constrained('special_policy_profiles');
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->string('item_type', 24); // GROUP_MEMBER, FLEET_VEHICLE, CONSTRUCTION_WORK, AGRI_PLOT, AGRI_HERD
            $t->string('item_key', 100); // member no / plate / site ref / plot ref
            $t->foreignUuid('party_id')->nullable()->constrained('parties');
            $t->foreignUuid('risk_asset_id')->nullable()->constrained('risk_assets');
            $t->string('display_name', 191);
            $t->string('category', 64)->nullable();
            $t->jsonb('facts')->default('{}');
            $t->bigInteger('sum_insured_minor')->nullable();
            $t->bigInteger('annual_premium_minor')->default(0);
            $t->bigInteger('adjustment_premium_minor')->default(0); // pro-rata AP (+) on add, RP (-) on removal
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->foreignUuid('added_by')->constrained('users');
            $t->foreignUuid('removed_by')->nullable()->constrained('users');
            $t->text('removal_reason')->nullable();
            $t->timestampsTz();
            $t->index(['policy_id', 'item_type', 'effective_from']);
        });
        // One open entry per key at a time.
        DB::statement('CREATE UNIQUE INDEX policy_schedule_items_open_key ON policy_schedule_items (profile_id, item_key) WHERE effective_until IS NULL');

        Schema::create('cargo_declarations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('profile_id')->constrained('special_policy_profiles');
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->unsignedInteger('sequence');
            $t->string('reference', 64);
            $t->string('conveyance', 24); // SEA, AIR, ROAD, RAIL, MULTIMODAL
            $t->string('goods_description', 500);
            $t->string('origin', 120);
            $t->string('destination', 120);
            $t->date('shipment_date');
            $t->bigInteger('insured_value_minor');
            $t->unsignedInteger('rate_bps');
            $t->bigInteger('premium_minor');
            $t->string('currency', 3)->default('XAF');
            $t->string('status', 16)->default('DECLARED'); // DECLARED, CANCELLED
            $t->jsonb('facts')->default('{}');
            $t->foreignUuid('declared_by')->constrained('users');
            $t->timestampTz('cancelled_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['profile_id', 'sequence']);
            $t->unique(['tenant_id', 'reference']);
        });

        Schema::create('life_surrender_scales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('insurance_product_id')->constrained('insurance_products');
            $t->unsignedInteger('version');
            $t->string('status', 16)->default('DRAFT'); // DRAFT, ACTIVE, RETIRED
            $t->string('basis', 24)->default('PREMIUMS_PAID'); // PREMIUMS_PAID | MATHEMATICAL_RESERVE (carrier-supplied)
            $t->unsignedSmallInteger('min_years_in_force')->default(2);
            $t->jsonb('factors_bps'); // {"2": 5000, "3": 6000, ...} policy year => bps of basis; last applies beyond
            $t->unsignedInteger('surrender_charge_bps')->default(0);
            $t->string('source_reference', 191)->nullable(); // carrier actuarial note reference
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['insurance_product_id', 'version']);
        });

        Schema::create('life_surrender_quotes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->foreignUuid('scale_id')->nullable()->constrained('life_surrender_scales');
            $t->date('as_of');
            $t->string('outcome', 32); // rules outcome
            $t->unsignedInteger('years_in_force');
            $t->bigInteger('basis_minor');
            $t->unsignedInteger('factor_bps');
            $t->bigInteger('gross_value_minor');
            $t->bigInteger('charge_minor');
            $t->bigInteger('loans_outstanding_minor')->default(0);
            $t->bigInteger('net_value_minor');
            $t->string('currency', 3)->default('XAF');
            $t->jsonb('reasons')->default('[]');
            $t->jsonb('trace')->default('[]');
            $t->foreignUuid('requested_by')->constrained('users');
            $t->timestampsTz();
            $t->index(['policy_id', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('life_surrender_quotes');
        Schema::dropIfExists('life_surrender_scales');
        Schema::dropIfExists('cargo_declarations');
        Schema::dropIfExists('policy_schedule_items');
        Schema::dropIfExists('special_policy_profiles');
    }
};
