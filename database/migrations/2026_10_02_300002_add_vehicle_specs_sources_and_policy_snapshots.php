<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicle master config (Cameroon & Africa, database/data/vehicle_master_config_africa_2026.json).
 * Additive only:
 *  - data_source on makes/models/generations/variants, ranked by
 *    VehicleDataSource::PRIORITY (OPESINSURE_VERIFIED_OVERRIDE > CAMEROON_DISTRIBUTOR_VERIFIED
 *    > GLOBAL_VEHICLE_DATASET > MANUAL_PENDING_REVIEW); existing rows backfilled from provenance;
 *  - engine-variant specs (power, torque, cylinders, raw spec map) and generation body type;
 *  - external_ref for idempotent dataset imports;
 *  - policy_vehicle_snapshots: foreign keys + frozen spec snapshot captured once at policy issue.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['vehicle_makes', 'vehicle_models', 'vehicle_generations', 'vehicle_variants'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('data_source', 40)->nullable()->index();
            });
            DB::table($table)->whereNull('data_source')->update(['data_source' => DB::raw(
                "CASE provenance WHEN 'CUSTOMER_SUBMITTED' THEN 'MANUAL_PENDING_REVIEW' WHEN 'CAMEROON_DISTRIBUTOR' THEN 'CAMEROON_DISTRIBUTOR_VERIFIED' WHEN 'INDUSTRY_DATABASE' THEN 'GLOBAL_VEHICLE_DATASET' ELSE 'OPESINSURE_VERIFIED_OVERRIDE' END"
            )]);
        }

        Schema::table('vehicle_generations', function (Blueprint $t) {
            $t->string('body_type', 40)->nullable();
            $t->string('external_ref', 191)->nullable()->unique();
        });

        Schema::table('vehicle_variants', function (Blueprint $t) {
            $t->unsignedInteger('power_hp')->nullable();
            $t->decimal('power_kw', 8, 2)->nullable();
            $t->unsignedInteger('torque_nm')->nullable();
            $t->unsignedSmallInteger('cylinders')->nullable();
            $t->string('fuel_type_raw', 60)->nullable();
            $t->json('specs')->nullable();
            $t->string('external_ref', 191)->nullable()->unique();
        });

        Schema::create('policy_vehicle_snapshots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('policy_id')->unique()->constrained('policies');
            $t->foreignUuid('risk_asset_id')->nullable()->constrained('risk_assets');
            $t->foreignUuid('make_id')->nullable()->constrained('vehicle_makes');
            $t->foreignUuid('model_id')->nullable()->constrained('vehicle_models');
            $t->foreignUuid('generation_id')->nullable()->constrained('vehicle_generations');
            $t->foreignUuid('variant_id')->nullable()->constrained('vehicle_variants');
            $t->json('spec_snapshot');
            $t->string('snapshot_hash', 64);
            $t->timestamp('captured_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_vehicle_snapshots');
        Schema::table('vehicle_variants', function (Blueprint $t) {
            $t->dropUnique(['external_ref']);
            $t->dropColumn(['power_hp', 'power_kw', 'torque_nm', 'cylinders', 'fuel_type_raw', 'specs', 'external_ref']);
        });
        Schema::table('vehicle_generations', function (Blueprint $t) {
            $t->dropUnique(['external_ref']);
            $t->dropColumn(['body_type', 'external_ref']);
        });
        foreach (['vehicle_makes', 'vehicle_models', 'vehicle_generations', 'vehicle_variants'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['data_source']);
                $t->dropColumn('data_source');
            });
        }
    }
};
