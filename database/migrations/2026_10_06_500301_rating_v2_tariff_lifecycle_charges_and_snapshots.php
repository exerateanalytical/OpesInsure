<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 5C — REQ-RAT-001..005 rating v2. Additive only: extends the canonical rating tables
 * (tariff_versions, tax_levy_versions, fee_schedule_versions, rating_runs, quote_offers) instead of
 * creating parallel ones (traceability §3 "Tariff workflow", "Rating endpoint").
 *
 * - tariff_versions: PRE §75 lifecycle adds SCHEDULED / ACTIVE / EXPIRED (APPROVED kept: an approved,
 *   not-yet-scheduled version; IN_REVIEW is PRE's REVIEW).
 * - rating_charge_codes: the charge-code catalogue (REQ-RAT-003). No legal reference or rate is asserted:
 *   every seeded row is UNVERIFIED pending owner answer OQ-9.
 * - tax_levy_versions / fee_schedule_versions: data_status marks DEMO/UNVERIFIED rates; both become
 *   Temporal-engine artifacts so a past date resolves exactly one version.
 * - rating_runs: stores every resolved version (tariff, tax, fee), the EngineResult, outputs hash,
 *   reference/as-of instants and per-CIMA-branch allocation (REQ-RAT-004/005).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tariff_versions DROP CONSTRAINT IF EXISTS tariff_status_allowed');
        DB::statement("ALTER TABLE tariff_versions ADD CONSTRAINT tariff_status_allowed CHECK (status IN ('DRAFT','IN_REVIEW','APPROVED','SCHEDULED','ACTIVE','EXPIRED','RETIRED','REJECTED'))");
        Schema::table('tariff_versions', function (Blueprint $t) {
            $t->timestampTz('scheduled_at')->nullable();
            $t->timestampTz('activated_at')->nullable();
            $t->timestampTz('expired_at')->nullable();
            $t->string('engine_version', 16)->default('2');
        });

        Schema::create('rating_charge_codes', function (Blueprint $t) {
            $t->string('code', 48)->primary();
            $t->string('kind', 16);                 // TAX | LEVY | STATUTORY | FEE
            $t->string('jurisdiction', 8)->default('CM');
            $t->string('name', 160);
            $t->string('default_basis', 24);        // PREMIUM | PREMIUM_AND_FEES | FIXED
            $t->string('legal_reference', 255)->nullable();   // NULL until the owner confirms (OQ-9)
            $t->string('verification_status', 16)->default('UNVERIFIED');
            $t->string('open_question', 16)->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE rating_charge_codes ADD CONSTRAINT rating_charge_kind_allowed CHECK (kind IN ('TAX','LEVY','STATUTORY','FEE'))");
        DB::statement("ALTER TABLE rating_charge_codes ADD CONSTRAINT rating_charge_basis_allowed CHECK (default_basis IN ('PREMIUM','PREMIUM_AND_FEES','FIXED'))");
        DB::statement("ALTER TABLE rating_charge_codes ADD CONSTRAINT rating_charge_verification_allowed CHECK (verification_status IN ('UNVERIFIED','VERIFIED'))");
        $now = now();
        foreach ([
            ['TAX', 'TAX', 'Premium tax (generic line used by legacy single-rate tables)', 'PREMIUM'],
            ['FEE', 'FEE', 'Fixed fee (generic line used by legacy fixed-fee tables)', 'FIXED'],
            ['PLATFORM_FEE', 'FEE', 'Platform service fee', 'FIXED'],
        ] as [$code, $kind, $name, $basis]) {
            DB::table('rating_charge_codes')->insertOrIgnore([
                'code' => $code, 'kind' => $kind, 'jurisdiction' => 'CM', 'name' => $name, 'default_basis' => $basis,
                'legal_reference' => null, 'verification_status' => 'UNVERIFIED', 'open_question' => 'OQ-9', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (['tax_levy_versions', 'fee_schedule_versions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('data_status', 24)->default('DEMO_UNVERIFIED');
                $t->foreignUuid('created_by')->nullable()->constrained('users');
                $t->foreignUuid('approved_by')->nullable()->constrained('users');
                $t->timestampTz('approved_at')->nullable();
                $t->string('source_reference', 255)->nullable();
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_data_status_allowed CHECK (data_status IN ('DEMO_UNVERIFIED','OWNER_CONFIRMED'))");
        }

        Schema::table('rating_runs', function (Blueprint $t) {
            $t->string('engine_version', 16)->nullable();
            $t->timestampTz('reference_at')->nullable();
            $t->timestampTz('recorded_as_of')->nullable();
            $t->jsonb('resolved_versions')->default('{}');
            $t->jsonb('engine_result')->nullable();
            $t->string('output_hash', 64)->nullable();
            $t->jsonb('branch_allocation')->default('[]');
            $t->string('allocation_status', 32)->nullable();
        });
        Schema::table('quote_offers', fn (Blueprint $t) => $t->foreignUuid('rating_run_id')->nullable()->constrained('rating_runs'));

        DB::table('versioned_artifact_registry')->where('artifact_type', 'tariff')
            ->update(['effective_statuses' => json_encode(['APPROVED', 'SCHEDULED', 'ACTIVE', 'EXPIRED']), 'updated_at' => $now]);
        foreach ([
            ['tax_levy', 'tax_levy_versions', ['jurisdiction', 'line_code']],
            ['fee_schedule', 'fee_schedule_versions', ['code', 'tenant_id']],
        ] as [$type, $table, $keys]) {
            DB::table('versioned_artifact_registry')->insertOrIgnore([
                'artifact_type' => $type, 'source_table' => $table, 'key_columns' => json_encode($keys),
                'from_column' => 'effective_from', 'until_column' => 'effective_until', 'granularity' => 'DAY', 'status_column' => 'status',
                'effective_statuses' => json_encode(['APPROVED']), 'version_column' => 'version', 'rule_category' => 'TERMS',
                'bitemporal' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('versioned_artifact_registry')->whereIn('artifact_type', ['tax_levy', 'fee_schedule'])->delete();
        DB::table('versioned_artifact_registry')->where('artifact_type', 'tariff')->update(['effective_statuses' => json_encode(['APPROVED'])]);
        Schema::table('quote_offers', fn (Blueprint $t) => $t->dropConstrainedForeignId('rating_run_id'));
        Schema::table('rating_runs', fn (Blueprint $t) => $t->dropColumn(['engine_version', 'reference_at', 'recorded_as_of', 'resolved_versions', 'engine_result', 'output_hash', 'branch_allocation', 'allocation_status']));
        foreach (['tax_levy_versions', 'fee_schedule_versions'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_data_status_allowed");
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('created_by');
                $t->dropConstrainedForeignId('approved_by');
                $t->dropColumn(['data_status', 'approved_at', 'source_reference']);
            });
        }
        Schema::dropIfExists('rating_charge_codes');
        Schema::table('tariff_versions', fn (Blueprint $t) => $t->dropColumn(['scheduled_at', 'activated_at', 'expired_at', 'engine_version']));
        DB::statement('ALTER TABLE tariff_versions DROP CONSTRAINT IF EXISTS tariff_status_allowed');
        DB::statement("ALTER TABLE tariff_versions ADD CONSTRAINT tariff_status_allowed CHECK (status IN ('DRAFT','IN_REVIEW','APPROVED','RETIRED','REJECTED'))");
    }
};
