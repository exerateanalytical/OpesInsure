<?php

declare(strict_types=1);

use App\Application\Claims\Taxonomy\ClaimTaxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Agent GP3 — Gap Closure Pack v1 file 03 (Motor, Repair Network, Experts & Claims Reference Master).
 *
 * Additive only; everything extends the canonical tables (no parallel garage / expert / reason tables):
 *  - provider_profiles (canonical provider master, categories GARAGE / EXPERT) gains the register provenance the pack needs
 *    (decision_reference, verified_at, verified_by); gp4 already added data_status / source_url / effective dating / phones.
 *  - provider_capabilities: garage services (partners.garage_service), expert specialties (partners.adjuster_type) and
 *    vehicle makes a provider handles — one row per capability, never a free-text blob.
 *  - vehicle_reference_values gains source / data_status and the pack's commercial_vehicle_class (16) and motorcycle_class (9)
 *    groups (PLATFORM_NORMALIZED classifications; variants stay PENDING_OFFICIAL_OR_LICENSED_SOURCE).
 *  - claim_decision_reason_codes (validated by the decision endpoint) gains aliases / source / reason_group and the pack's
 *    decision and rejection reasons, merged by code: synonyms of existing codes become aliases, only new meanings are rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $t) {
            if (! Schema::hasColumn('provider_profiles', 'decision_reference')) {
                $t->string('decision_reference', 191)->nullable();   // DGTCFM approval decision for technical experts
            }
            if (! Schema::hasColumn('provider_profiles', 'verified_at')) {
                $t->timestampTz('verified_at')->nullable();
            }
            if (! Schema::hasColumn('provider_profiles', 'verified_by')) {
                $t->uuid('verified_by')->nullable();
            }
            // Normally added by 2026_10_19_410001_provider_portal_gap_free (gp4); guarded so this pack never depends on it.
            foreach (['official_name' => 191, 'trade_name' => 191, 'data_status' => 32, 'data_source' => 120, 'source_url' => 500] as $col => $len) {
                if (! Schema::hasColumn('provider_profiles', $col)) {
                    $t->string($col, $len)->nullable();
                }
            }
            foreach (['phones', 'emails'] as $col) {
                if (! Schema::hasColumn('provider_profiles', $col)) {
                    $t->jsonb($col)->default('[]');
                }
            }
            foreach (['effective_from', 'effective_until'] as $col) {
                if (! Schema::hasColumn('provider_profiles', $col)) {
                    $t->date($col)->nullable();
                }
            }
        });

        // Pack 03 required_complete_variant_fields not yet on the variant (power_ps / fiscal CV stay in the power master).
        Schema::table('vehicle_variants', function (Blueprint $t) {
            $t->string('engine_code', 40)->nullable();
            $t->unsignedSmallInteger('seat_count')->nullable();
            $t->unsignedInteger('curb_weight_kg')->nullable();
            $t->unsignedInteger('gross_vehicle_weight_kg')->nullable();
            $t->unsignedInteger('payload_kg')->nullable();
        });

        Schema::create('provider_capabilities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->string('kind', 16);                 // SERVICE | SPECIALTY | VEHICLE_MAKE
            $t->string('code', 64);
            $t->string('source', 64)->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['provider_profile_id', 'kind', 'code']);
            $t->index(['kind', 'code']);
        });
        DB::statement("ALTER TABLE provider_capabilities ADD CONSTRAINT provider_capabilities_kind_chk CHECK (kind IN ('SERVICE','SPECIALTY','VEHICLE_MAKE'))");

        Schema::table('vehicle_reference_values', function (Blueprint $t) {
            $t->string('source', 64)->nullable();
            $t->string('data_status', 32)->nullable();
        });
        $now = now();
        foreach (ClaimTaxonomy::VEHICLE_CLASS_GROUPS as $group => $values) {
            $i = 0;
            foreach ($values as $code => [$en, $fr, $maps]) {
                $i++;
                $row = DB::table('vehicle_reference_values')->where(['group' => $group, 'code' => $code])->first();
                if (! $row) {
                    DB::table('vehicle_reference_values')->insert(['id' => (string) Str::uuid(), 'group' => $group, 'code' => $code, 'label_en' => $en, 'label_fr' => $fr,
                        'sort_order' => $i, 'active' => true, 'source' => ClaimTaxonomy::SOURCE, 'data_status' => 'PLATFORM_NORMALIZED', 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        Schema::table('claim_decision_reason_codes', function (Blueprint $t) {
            $t->jsonb('aliases')->default('[]');
            $t->string('source', 64)->nullable();
            $t->string('reason_group', 16)->nullable(); // DECISION | REJECTION (pack 03 lists)
        });
        foreach (ClaimTaxonomy::DECISION_REASONS as $code => [$scope, $label, $group, $aliasOf]) {
            if ($aliasOf !== null) {
                $target = DB::table('claim_decision_reason_codes')->where('code', $aliasOf)->first();
                if ($target) {
                    $aliases = array_values(array_unique([...json_decode((string) $target->aliases, true) ?: [], $code]));
                    DB::table('claim_decision_reason_codes')->where('code', $aliasOf)->update(['aliases' => json_encode($aliases), 'updated_at' => $now]);
                }

                continue;
            }
            $existing = DB::table('claim_decision_reason_codes')->where('code', $code)->first();
            if ($existing) {
                DB::table('claim_decision_reason_codes')->where('code', $code)->update(['reason_group' => $existing->reason_group ?? $group, 'updated_at' => $now]);

                continue;
            }
            DB::table('claim_decision_reason_codes')->insert(['code' => $code, 'applies_to' => $scope, 'label' => $label, 'customer_text' => $label, 'status' => 'ACTIVE',
                'data_origin' => 'PLATFORM_NORMALIZED', 'source' => ClaimTaxonomy::SOURCE, 'reason_group' => $group, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('claim_decision_reason_codes')->where('source', ClaimTaxonomy::SOURCE)->delete();
        Schema::table('claim_decision_reason_codes', fn (Blueprint $t) => $t->dropColumn(['aliases', 'source', 'reason_group']));
        DB::table('vehicle_reference_values')->whereIn('group', array_keys(ClaimTaxonomy::VEHICLE_CLASS_GROUPS))->delete();
        Schema::table('vehicle_reference_values', fn (Blueprint $t) => $t->dropColumn(['source', 'data_status']));
        Schema::dropIfExists('provider_capabilities');
        Schema::table('vehicle_variants', fn (Blueprint $t) => $t->dropColumn(['engine_code', 'seat_count', 'curb_weight_kg', 'gross_vehicle_weight_kg', 'payload_kg']));
        Schema::table('provider_profiles', fn (Blueprint $t) => $t->dropColumn(['decision_reference', 'verified_at', 'verified_by']));
    }
};
