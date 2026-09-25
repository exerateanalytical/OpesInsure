<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Agent V1 — Cameroon Vehicle Power & Fiscal Power Institutional Master v1
 * (docs/spec/canonical/OpesInsure_Cameroon_Vehicle_Power_Fiscal_Power_Institutional_Master_v1.json).
 *
 * Additive; the existing vehicle master (vehicle_makes → models → generations → variants, risk_asset_vehicles,
 * policy_vehicle_snapshots) and rating v2 (rating_charge_codes, rating_runs, rule_sets) are reused:
 *  - vehicle_power_specs: technical power per variant, kW canonical, hp / PS derived, source value + unit preserved.
 *  - vehicle_fiscal_power_records: Cameroon puissance administrative (CV fiscal) from authoritative sources only,
 *    effective-dated versions, review workflow; a VERIFIED row is immutable (VPWR-007, DB trigger).
 *  - vehicle_fiscal_power_conflicts: conflicting authoritative values (linked to a DATA_STEWARD case).
 *  - vehicle_fiscal_power_bands, vehicle_stamp_duty_rate_schedules, vehicle_stamp_duty_rates: owner-provided baseline
 *    (source_registry CM-DGI-CGI-2026, CM-DGI-LF2023-CIRCULAR). Schedules are seeded DRAFT and must be approved by a
 *    checker in each environment before rating applies them.
 *  - vehicle_power_verification_audits: append-only verification trail.
 *  - Provenance is stored inline on each record (spec source_value_model); no separate vehicle_power_sources table
 *    (vehicle_master_sources already registers dataset sources).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_power_specs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('variant_id')->constrained('vehicle_variants');
            $t->unsignedInteger('version');
            $t->string('status', 32)->default('DRAFT');
            $t->decimal('power_kw', 10, 3)->nullable();
            $t->decimal('power_hp', 10, 3)->nullable();
            $t->decimal('power_ps', 10, 3)->nullable();
            $t->unsignedInteger('power_rpm')->nullable();
            $t->unsignedInteger('torque_nm')->nullable();
            $t->unsignedInteger('torque_rpm')->nullable();
            $t->unsignedInteger('displacement_cc')->nullable();
            $t->decimal('power_source_value', 12, 3)->nullable();
            $t->string('power_source_unit', 16)->nullable();
            $t->string('power_source_type', 48)->nullable();
            $t->string('power_source_name', 191)->nullable();
            $t->string('power_source_reference', 191)->nullable();
            $t->string('power_source_url', 500)->nullable();
            $t->timestampTz('power_verified_at')->nullable();
            $t->string('power_verification_status', 32)->default('UNVERIFIED');
            $t->string('conversion_method', 48);
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->text('notes')->nullable();
            $t->timestampsTz();
            $t->unique(['variant_id', 'version']);
            $t->index(['effective_from', 'effective_until']);
        });
        DB::statement("ALTER TABLE vehicle_power_specs ADD CONSTRAINT vps_unit_allowed CHECK (power_source_unit IS NULL OR power_source_unit IN ('KW','MECHANICAL_HP','METRIC_PS'))");
        DB::statement('ALTER TABLE vehicle_power_specs ADD CONSTRAINT vps_kw_positive CHECK (power_kw IS NULL OR power_kw > 0)');
        DB::statement("ALTER TABLE vehicle_power_specs ADD CONSTRAINT vps_status_allowed CHECK (status IN ('DRAFT','SOURCE_ATTACHED','PENDING_REVIEW','VERIFIED','SUPERSEDED','REJECTED'))");

        Schema::create('vehicle_fiscal_power_bands', function (Blueprint $t) {
            $t->string('code', 16)->primary();
            $t->unsignedSmallInteger('min_cv');
            $t->unsignedSmallInteger('max_cv')->nullable();
            $t->string('label_fr', 80);
            $t->string('label_en', 80);
            $t->string('jurisdiction', 2)->default('CM');
            $t->string('source_id', 64);
            $t->unsignedSmallInteger('sort_order');
            $t->timestampsTz();
        });

        Schema::create('vehicle_fiscal_power_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $t->foreignUuid('variant_id')->nullable()->constrained('vehicle_variants');
            $t->string('registration_number', 40)->nullable();
            $t->string('vin', 40)->nullable();
            $t->unsignedInteger('version');
            $t->string('review_state', 32)->default('DRAFT');
            $t->unsignedSmallInteger('fiscal_power_cv')->nullable();
            $t->string('fiscal_power_band_code', 16)->nullable();
            $t->foreign('fiscal_power_band_code')->references('code')->on('vehicle_fiscal_power_bands');
            $t->string('source_type', 40)->nullable();
            $t->string('source_reference', 191)->nullable();
            $t->uuid('source_document_id')->nullable();
            $t->string('source_url', 500)->nullable();
            $t->string('jurisdiction', 2)->default('CM');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('verification_status', 40)->default('PENDING_FISCAL_POWER_VERIFICATION');
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('submitted_by')->nullable()->constrained('users');
            $t->foreignUuid('verified_by')->nullable()->constrained('users');
            $t->timestampTz('verified_at')->nullable();
            $t->uuid('supersedes_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestampsTz();
            $t->index('fiscal_power_cv');
            $t->index('fiscal_power_band_code');
            $t->index('verification_status');
            $t->index('registration_number');
            $t->index(['effective_from', 'effective_until']);
        });
        DB::statement("ALTER TABLE vehicle_fiscal_power_records ADD CONSTRAINT vfpr_subject CHECK (variant_id IS NOT NULL OR registration_number IS NOT NULL OR vin IS NOT NULL)");
        DB::statement("ALTER TABLE vehicle_fiscal_power_records ADD CONSTRAINT vfpr_state_allowed CHECK (review_state IN ('DRAFT','SOURCE_ATTACHED','PENDING_REVIEW','VERIFIED','CONFLICT_REVIEW_REQUIRED','SUPERSEDED','REJECTED'))");
        DB::statement("ALTER TABLE vehicle_fiscal_power_records ADD CONSTRAINT vfpr_source_allowed CHECK (source_type IS NULL OR source_type IN ('CIVIC','CAMEROON_REGISTRATION_DOCUMENT','CAMEROON_AUTHORITY_DATA','MANUAL_VERIFIED'))");
        DB::statement("ALTER TABLE vehicle_fiscal_power_records ADD CONSTRAINT vfpr_verification_allowed CHECK (verification_status IN ('VERIFIED_CIVIC','VERIFIED_REGISTRATION','VERIFIED_AUTHORITY_DATA','VERIFIED_MANUAL_DOCUMENT','CONFLICT_REVIEW_REQUIRED','PENDING_FISCAL_POWER_VERIFICATION','RETIRED'))");
        // VPWR-004 / VPWR-005: a VERIFIED value is a positive integer with provenance and a checker.
        DB::statement("ALTER TABLE vehicle_fiscal_power_records ADD CONSTRAINT vfpr_verified_provenance CHECK (review_state <> 'VERIFIED' OR (fiscal_power_cv > 0 AND source_type IS NOT NULL AND source_reference IS NOT NULL AND verified_by IS NOT NULL AND verified_at IS NOT NULL AND verification_status LIKE 'VERIFIED_%'))");
        DB::statement("ALTER TABLE vehicle_fiscal_power_records ADD CONSTRAINT vfpr_manual_document CHECK (source_type IS DISTINCT FROM 'MANUAL_VERIFIED' OR review_state IN ('DRAFT','REJECTED') OR source_document_id IS NOT NULL)");
        // VPWR-007: a VERIFIED value is never overwritten — only superseded by a new version.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION vfpr_verified_immutable() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.review_state IN ('VERIFIED','SUPERSEDED') THEN RAISE EXCEPTION 'VPWR-007: verified fiscal power records cannot be deleted'; END IF;
    RETURN OLD;
  END IF;
  IF OLD.review_state IN ('VERIFIED','SUPERSEDED') AND (
       NEW.fiscal_power_cv IS DISTINCT FROM OLD.fiscal_power_cv OR NEW.source_type IS DISTINCT FROM OLD.source_type
    OR NEW.source_reference IS DISTINCT FROM OLD.source_reference OR NEW.source_document_id IS DISTINCT FROM OLD.source_document_id
    OR NEW.effective_from IS DISTINCT FROM OLD.effective_from OR NEW.verified_by IS DISTINCT FROM OLD.verified_by
    OR NEW.verified_at IS DISTINCT FROM OLD.verified_at OR NEW.variant_id IS DISTINCT FROM OLD.variant_id
    OR NEW.registration_number IS DISTINCT FROM OLD.registration_number
    OR (OLD.review_state = 'SUPERSEDED' AND NEW.review_state <> 'SUPERSEDED')
    OR (OLD.review_state = 'VERIFIED' AND NEW.review_state NOT IN ('VERIFIED','SUPERSEDED','CONFLICT_REVIEW_REQUIRED'))) THEN
    RAISE EXCEPTION 'VPWR-007: a verified fiscal power value cannot be overwritten; create a new version';
  END IF;
  RETURN NEW;
END $$ LANGUAGE plpgsql;
CREATE TRIGGER vfpr_verified_immutable BEFORE UPDATE OR DELETE ON vehicle_fiscal_power_records FOR EACH ROW EXECUTE FUNCTION vfpr_verified_immutable();
SQL);

        Schema::create('vehicle_fiscal_power_conflicts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('record_id')->constrained('vehicle_fiscal_power_records');
            $t->foreignUuid('conflicting_record_id')->constrained('vehicle_fiscal_power_records');
            $t->jsonb('values');
            $t->uuid('case_id')->nullable();
            $t->string('status', 16)->default('OPEN');
            $t->foreignUuid('resolved_by')->nullable()->constrained('users');
            $t->timestampTz('resolved_at')->nullable();
            $t->text('resolution')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE vehicle_fiscal_power_conflicts ADD CONSTRAINT vfpc_status_allowed CHECK (status IN ('OPEN','RESOLVED'))");

        Schema::create('vehicle_stamp_duty_rate_schedules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('schedule_code', 64);
            $t->unsignedInteger('version');
            $t->string('status', 16)->default('DRAFT');
            $t->string('label_fr', 191);
            $t->string('jurisdiction', 2)->default('CM');
            $t->string('currency', 3)->default('XAF');
            $t->boolean('transport_license_required')->default(false);
            $t->string('license_verification_status_required', 16)->nullable();
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('data_status', 32)->default('OWNER_PROVIDED');
            $t->string('source_status', 48)->nullable();
            $t->jsonb('source_ids')->default('[]');
            $t->string('legal_reference', 255)->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['schedule_code', 'version']);
            $t->index(['effective_from', 'effective_until']);
        });
        DB::statement("ALTER TABLE vehicle_stamp_duty_rate_schedules ADD CONSTRAINT vsds_status_allowed CHECK (status IN ('DRAFT','APPROVED','REJECTED'))");
        DB::statement("ALTER TABLE vehicle_stamp_duty_rate_schedules ADD CONSTRAINT vsds_code_allowed CHECK (schedule_code IN ('PUBLIC_PASSENGER_AND_GOODS_TRANSPORT','OTHER_VEHICLES'))");
        DB::statement('ALTER TABLE vehicle_stamp_duty_rate_schedules ADD CONSTRAINT vsds_maker_checker CHECK (approved_by IS NULL OR created_by IS NULL OR approved_by <> created_by)');

        Schema::create('vehicle_stamp_duty_rates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('schedule_id')->constrained('vehicle_stamp_duty_rate_schedules')->cascadeOnDelete();
            $t->string('band_code', 16);
            $t->foreign('band_code')->references('code')->on('vehicle_fiscal_power_bands');
            $t->unsignedBigInteger('rate_xaf');
            $t->timestampsTz();
            $t->unique(['schedule_id', 'band_code']);
        });
        // Approved rate versions are history: never edited (a change is a new schedule version).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION vsdr_approved_immutable() RETURNS trigger AS $$
DECLARE s text;
BEGIN
  SELECT status INTO s FROM vehicle_stamp_duty_rate_schedules WHERE id = OLD.schedule_id;
  IF s IS DISTINCT FROM 'DRAFT' THEN RAISE EXCEPTION 'Approved stamp duty rates are immutable; create a new schedule version'; END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END $$ LANGUAGE plpgsql;
CREATE TRIGGER vsdr_approved_immutable BEFORE UPDATE OR DELETE ON vehicle_stamp_duty_rates FOR EACH ROW EXECUTE FUNCTION vsdr_approved_immutable();
SQL);

        // VPWR-009: the public passenger / goods transport schedule needs a verified VALID transport licence (maker-checker).
        Schema::create('vehicle_transport_licences', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->string('registration_number', 40);
            $t->string('licence_number', 80);
            $t->string('licence_type', 48)->nullable();
            $t->string('issuing_authority', 191)->nullable();
            $t->date('valid_from');
            $t->date('valid_until')->nullable();
            $t->string('status', 16)->default('PENDING');
            $t->uuid('source_document_id')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('verified_by')->nullable()->constrained('users');
            $t->timestampTz('verified_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'registration_number']);
        });
        DB::statement("ALTER TABLE vehicle_transport_licences ADD CONSTRAINT vtl_status_allowed CHECK (status IN ('PENDING','VALID','EXPIRED','SUSPENDED','REVOKED','REJECTED'))");
        DB::statement("ALTER TABLE vehicle_transport_licences ADD CONSTRAINT vtl_valid_verified CHECK (status <> 'VALID' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL AND (created_by IS NULL OR verified_by <> created_by)))");

        Schema::create('vehicle_power_verification_audits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('subject_type', 48);
            $t->uuid('subject_id');
            $t->string('action', 64);
            $t->string('from_state', 32)->nullable();
            $t->string('to_state', 32)->nullable();
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->jsonb('payload')->default('{}');
            $t->timestampTz('created_at');
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::table('rating_runs', fn (Blueprint $t) => $t->jsonb('fiscal_power_snapshot')->nullable());

        // Rule engine: exemptions come only from rules (spec exemption_handling RULE_ENGINE_REQUIRED).
        DB::statement('ALTER TABLE rule_sets DROP CONSTRAINT IF EXISTS rule_sets_domain_allowed');
        DB::statement("ALTER TABLE rule_sets ADD CONSTRAINT rule_sets_domain_allowed CHECK (domain IN ('ELIGIBILITY','COMPLETENESS','UNDERWRITING','REFERRAL','DOCUMENTS','QUESTION_EFFECT','TAX_EXEMPTION'))");

        $now = now();
        DB::table('rating_charge_codes')->insertOrIgnore([
            'code' => 'AUTOMOBILE_STAMP_DUTY', 'kind' => 'TAX', 'jurisdiction' => 'CM', 'name' => 'Droit de timbre automobile (automobile stamp duty)',
            'default_basis' => 'FIXED', 'legal_reference' => null,
            'verification_status' => 'UNVERIFIED', 'open_question' => 'OQ-VPWR-1', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Owner-provided baseline (spec cameroon_automobile_stamp_duty, source_registry CM-DGI-LF2023-CIRCULAR).
        foreach ([
            ['CV_02_07', 2, 7, '02 à 7 CV', '2 to 7 fiscal HP'],
            ['CV_08_13', 8, 13, '08 à 13 CV', '8 to 13 fiscal HP'],
            ['CV_14_20', 14, 20, '14 à 20 CV', '14 to 20 fiscal HP'],
            ['CV_GT_20', 21, null, 'Plus de 20 CV', 'More than 20 fiscal HP'],
        ] as $i => [$code, $min, $max, $fr, $en]) {
            DB::table('vehicle_fiscal_power_bands')->insertOrIgnore(['code' => $code, 'min_cv' => $min, 'max_cv' => $max, 'label_fr' => $fr, 'label_en' => $en,
                'jurisdiction' => 'CM', 'source_id' => 'CM-DGI-LF2023-CIRCULAR', 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([
            ['PUBLIC_PASSENGER_AND_GOODS_TRANSPORT', 'Véhicules de transport en commun de personnes et de marchandises', true, 'VALID', ['CV_02_07' => 15000, 'CV_08_13' => 25000, 'CV_14_20' => 50000, 'CV_GT_20' => 150000]],
            ['OTHER_VEHICLES', 'Autres véhicules', false, null, ['CV_02_07' => 30000, 'CV_08_13' => 50000, 'CV_14_20' => 75000, 'CV_GT_20' => 200000]],
        ] as [$code, $label, $licence, $licenceStatus, $rates]) {
            if (DB::table('vehicle_stamp_duty_rate_schedules')->where('schedule_code', $code)->exists()) {
                continue;
            }
            $id = (string) Str::uuid();
            DB::table('vehicle_stamp_duty_rate_schedules')->insert(['id' => $id, 'schedule_code' => $code, 'version' => 1, 'status' => 'DRAFT', 'label_fr' => $label,
                'jurisdiction' => 'CM', 'currency' => 'XAF', 'transport_license_required' => $licence, 'license_verification_status_required' => $licenceStatus,
                // Effective date of the baseline is an owner question (OQ-VPWR-1): the current CGI (2026) is the cited primary source.
                'effective_from' => '2026-01-01', 'data_status' => 'OWNER_PROVIDED', 'source_status' => 'VERIFIED_CURRENT_RULE_BASELINE',
                'source_ids' => json_encode(['CM-DGI-CGI-2026', 'CM-DGI-LF2023-CIRCULAR']),
                'legal_reference' => 'Cameroon General Tax Code, automobile stamp duty provisions', 'created_at' => $now, 'updated_at' => $now]);
            foreach ($rates as $band => $xaf) {
                DB::table('vehicle_stamp_duty_rates')->insert(['id' => (string) Str::uuid(), 'schedule_id' => $id, 'band_code' => $band, 'rate_xaf' => $xaf, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('rule_sets')->where('domain', 'TAX_EXEMPTION')->delete();
        DB::statement('ALTER TABLE rule_sets DROP CONSTRAINT IF EXISTS rule_sets_domain_allowed');
        DB::statement("ALTER TABLE rule_sets ADD CONSTRAINT rule_sets_domain_allowed CHECK (domain IN ('ELIGIBILITY','COMPLETENESS','UNDERWRITING','REFERRAL','DOCUMENTS','QUESTION_EFFECT'))");
        Schema::table('rating_runs', fn (Blueprint $t) => $t->dropColumn('fiscal_power_snapshot'));
        DB::table('rating_charge_codes')->where('code', 'AUTOMOBILE_STAMP_DUTY')->delete();
        Schema::dropIfExists('vehicle_power_verification_audits');
        Schema::dropIfExists('vehicle_transport_licences');
        DB::unprepared('DROP TRIGGER IF EXISTS vsdr_approved_immutable ON vehicle_stamp_duty_rates; DROP FUNCTION IF EXISTS vsdr_approved_immutable();');
        Schema::dropIfExists('vehicle_stamp_duty_rates');
        Schema::dropIfExists('vehicle_stamp_duty_rate_schedules');
        Schema::dropIfExists('vehicle_fiscal_power_conflicts');
        DB::unprepared('DROP TRIGGER IF EXISTS vfpr_verified_immutable ON vehicle_fiscal_power_records; DROP FUNCTION IF EXISTS vfpr_verified_immutable();');
        Schema::dropIfExists('vehicle_fiscal_power_records');
        Schema::dropIfExists('vehicle_fiscal_power_bands');
        Schema::dropIfExists('vehicle_power_specs');
    }
};
