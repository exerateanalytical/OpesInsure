<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cameroon Vehicle Institutional Master Data (owner spec v1.0). Additive only.
 * Data is seeded by `opesinsure:seed-vehicles` from
 * database/data/cameroon_vehicle_master_2026.json. Make/model/origin are
 * descriptive data — no rating rule reads these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_master_sources', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 80)->unique();
            $t->string('name');
            $t->string('provenance', 40);
            $t->string('content_hash', 64)->nullable();
            $t->timestamp('imported_at')->nullable();
            $t->timestamps();
        });

        Schema::create('vehicle_manufacturers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 80)->unique();
            $t->string('name');
            $t->string('country_of_origin', 2)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('vehicle_makes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 80)->unique();
            $t->string('name');
            $t->string('normalized_name', 120)->index();
            $t->string('country_of_origin', 2)->nullable();
            $t->foreignUuid('manufacturer_id')->nullable()->constrained('vehicle_manufacturers');
            $t->string('market_priority', 20)->default('NORMAL');
            $t->string('cameroon_status', 40)->default('UNVERIFIED');
            $t->string('segment', 20)->default('PASSENGER');
            $t->string('provenance', 40);
            $t->foreignUuid('source_id')->nullable()->constrained('vehicle_master_sources');
            $t->unsignedSmallInteger('ui_rank_cameroon')->nullable();
            $t->unsignedSmallInteger('ui_rank_chinese')->nullable();
            $t->boolean('active')->default(true);
            $t->uuid('merged_into_id')->nullable();
            $t->timestamp('admin_modified_at')->nullable();
            $t->timestamps();
            $t->index(['active', 'segment']);
        });

        Schema::create('vehicle_make_aliases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('make_id')->constrained('vehicle_makes');
            $t->string('alias');
            $t->string('normalized_alias', 120)->unique();
            $t->string('provenance', 40);
            $t->timestamps();
        });

        Schema::create('vehicle_models', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 160)->unique();
            $t->foreignUuid('make_id')->constrained('vehicle_makes');
            $t->string('name');
            $t->string('normalized_name', 160);
            $t->string('segment', 20)->default('PASSENGER');
            $t->string('status', 20)->default('ACTIVE'); // ACTIVE | HISTORICAL
            $t->string('provenance', 40);
            $t->boolean('active')->default(true);
            $t->timestamp('admin_modified_at')->nullable();
            $t->timestamps();
            $t->unique(['make_id', 'normalized_name']);
        });

        Schema::create('vehicle_model_aliases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('model_id')->constrained('vehicle_models');
            $t->foreignUuid('make_id')->constrained('vehicle_makes');
            $t->string('alias');
            $t->string('normalized_alias', 160);
            $t->string('provenance', 40);
            $t->timestamps();
            $t->unique(['make_id', 'normalized_alias']);
        });

        Schema::create('vehicle_generations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('model_id')->constrained('vehicle_models');
            $t->string('code', 200)->unique();
            $t->string('name');
            $t->unsignedSmallInteger('year_from')->nullable();
            $t->unsignedSmallInteger('year_to')->nullable();
            $t->string('provenance', 40);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('vehicle_variants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('model_id')->constrained('vehicle_models');
            $t->foreignUuid('generation_id')->nullable()->constrained('vehicle_generations');
            $t->string('code', 240)->unique();
            $t->string('name');
            $t->string('body_type', 40)->nullable();
            $t->string('powertrain', 40)->nullable();
            $t->string('hybrid_subtype', 10)->nullable();
            $t->string('transmission', 40)->nullable();
            $t->string('drive_type', 40)->nullable();
            $t->unsignedInteger('engine_capacity_cc')->nullable();
            $t->string('provenance', 40);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        // Enumerations (body types, usages, powertrains, …) with EN/FR labels;
        // table rather than config so they are editable without a deploy.
        Schema::create('vehicle_reference_values', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('group', 40);
            $t->string('code', 60);
            $t->string('label_en');
            $t->string('label_fr');
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamp('admin_modified_at')->nullable();
            $t->timestamps();
            $t->unique(['group', 'code']);
        });

        Schema::create('vehicle_master_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('entity_type', 60);
            $t->uuid('entity_id');
            $t->string('action', 40);
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('reason')->nullable();
            $t->uuid('actor_id')->nullable();
            $t->timestamp('occurred_at');
            $t->index(['entity_type', 'entity_id']);
        });

        Schema::create('vehicle_master_review_queue', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status', 40)->default('MASTER_DATA_REVIEW_REQUIRED')->index();
            $t->string('make_text');
            $t->string('model_text');
            $t->unsignedSmallInteger('model_year')->nullable();
            $t->string('body_type', 40)->nullable();
            $t->string('powertrain', 40)->nullable();
            $t->string('usage', 60)->nullable();
            $t->string('vin', 40)->nullable();
            $t->string('registration_number', 40)->nullable();
            $t->string('engine_number', 60)->nullable();
            $t->json('payload')->nullable();
            $t->uuid('tenant_id')->nullable();
            $t->uuid('risk_asset_id')->nullable();
            $t->uuid('submitted_by')->nullable();
            $t->uuid('resolved_make_id')->nullable();
            $t->uuid('resolved_model_id')->nullable();
            $t->uuid('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_notes')->nullable();
            $t->timestamps();
        });

        // Canonical vehicle record, 1:1 with a VEHICLE risk asset. Facts stay the
        // rating input; this is the reconciled, queryable description.
        Schema::create('risk_asset_vehicles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('risk_asset_id')->unique()->constrained('risk_assets');
            $t->foreignUuid('make_id')->nullable()->constrained('vehicle_makes');
            $t->foreignUuid('model_id')->nullable()->constrained('vehicle_models');
            $t->foreignUuid('generation_id')->nullable()->constrained('vehicle_generations');
            $t->foreignUuid('variant_id')->nullable()->constrained('vehicle_variants');
            $t->string('make_text')->nullable();
            $t->string('model_text')->nullable();
            $t->string('reconciliation_status', 30)->default('UNRESOLVED'); // MATCHED | PARTIAL | UNRESOLVED | PENDING_REVIEW
            $t->uuid('review_id')->nullable();
            $t->unsignedSmallInteger('model_year')->nullable();
            foreach (['body_type', 'vehicle_class', 'usage', 'ownership', 'powertrain', 'transmission', 'drive_type', 'condition'] as $c) {
                $t->string($c, 60)->nullable();
            }
            $t->string('hybrid_subtype', 10)->nullable();
            $t->string('registration_number', 40)->nullable()->index();
            $t->string('vin', 40)->nullable()->index();
            $t->string('engine_number', 60)->nullable();
            $t->unsignedInteger('engine_capacity_cc')->nullable();
            $t->decimal('engine_power_kw', 8, 2)->nullable();
            $t->unsignedInteger('horsepower')->nullable();
            $t->unsignedSmallInteger('fiscal_power')->nullable();
            $t->unsignedSmallInteger('seat_count')->nullable();
            $t->date('first_registration_date')->nullable();
            $t->date('import_date')->nullable();
            $t->string('country_of_origin', 2)->nullable();
            $t->unsignedInteger('odometer_km')->nullable();
            foreach (['purchase_value', 'declared_value', 'market_value', 'assessed_value', 'sum_insured'] as $c) {
                $t->unsignedBigInteger($c)->nullable(); // XAF
            }
            $t->unsignedInteger('gross_vehicle_weight_kg')->nullable();
            $t->unsignedInteger('payload_kg')->nullable();
            $t->unsignedSmallInteger('axle_count')->nullable();
            $t->string('cargo_type', 60)->nullable();
            $t->string('trailer_type', 60)->nullable();
            $t->string('commercial_activity', 120)->nullable();
            $t->decimal('battery_capacity_kwh', 8, 2)->nullable();
            $t->string('battery_type', 40)->nullable();
            $t->unsignedInteger('electric_range_km')->nullable();
            $t->string('charging_type', 40)->nullable();
            $t->string('battery_ownership', 20)->nullable(); // OWNED | LEASED
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['risk_asset_vehicles', 'vehicle_master_review_queue', 'vehicle_master_changes', 'vehicle_reference_values', 'vehicle_variants', 'vehicle_generations', 'vehicle_model_aliases', 'vehicle_models', 'vehicle_make_aliases', 'vehicle_makes', 'vehicle_manufacturers', 'vehicle_master_sources'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
