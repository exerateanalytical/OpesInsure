<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap closure pack 04 (health provider / service / tariff master) + Provider Portal Hospital/Clinic Gap-Free spec v1.
 * REQ-PRV-001/002/003, REQ-HLT-001..003.
 *
 * Additive only. Extends the canonical provider master (provider_profiles / provider_facilities), the medical service
 * catalogue (medical_services) and tariffs (provider_tariff_versions / provider_tariff_lines) with the fields the packs
 * name, and adds ONLY the portal entities that did not exist:
 *   provider_departments (FACILITY → DEPARTMENT → SERVICE_UNIT), provider_users + provider_user_facilities (portal role +
 *   facility scope), treatment_episodes (+ lines), provider_reconciliations (+ lines), provider_disputes.
 * Spec entities already served by canonical tables are NOT recreated (see ProviderWorkspaceRegister::ENTITY_MAP):
 * preauthorizations → health_preauthorizations, admissions → health_preauthorizations(ADMISSION) + _extensions,
 * provider_claims → health_provider_claims, settlements → health_provider_settlement_batches, eligibility →
 * health_eligibility_checks, provider accounts/entries → derived (never an editable balance), audit → audit_log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $t) {
            $t->foreignUuid('parent_provider_id')->nullable()->constrained('provider_profiles'); // provider group / head office
            $t->string('official_name')->nullable();
            $t->string('trade_name')->nullable();
            $t->string('ownership_type', 32)->nullable();          // PUBLIC | PRIVATE | FAITH_BASED | NGO | MILITARY …
            $t->string('provider_category', 64)->nullable();       // MINSANTE category (1st…6th category) — official import only
            $t->string('health_district', 120)->nullable();
            $t->string('health_area', 120)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->jsonb('phones')->default('[]');
            $t->jsonb('emails')->default('[]');
            $t->string('website', 300)->nullable();
            $t->string('license_or_authorization_reference', 200)->nullable();
            $t->string('tax_identifier', 64)->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->string('source_url', 500)->nullable();
            $t->string('data_status', 32)->nullable();             // gap-closure vocabulary; NULL = insurer-registered
            $t->string('data_source', 120)->nullable();
        });

        Schema::table('provider_facilities', function (Blueprint $t) {
            $t->string('health_district', 120)->nullable();
            $t->string('health_area', 120)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->jsonb('contacts')->default('{}');
            $t->boolean('is_head_office')->default(false);
        });

        // Facility + source-system tracking on the canonical provider claim (drill-down OUTSTANDING → INSURER → FACILITY → CLAIM, HIS/EMR).
        Schema::table('health_provider_claims', function (Blueprint $t) {
            $t->foreignUuid('provider_facility_id')->nullable()->constrained('provider_facilities');
            $t->string('source_system', 64)->nullable();
        });

        Schema::create('provider_departments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_facility_id')->constrained('provider_facilities');
            $t->uuid('parent_department_id')->nullable();
            $t->string('level', 16)->default('DEPARTMENT');       // DEPARTMENT | SERVICE_UNIT
            $t->string('code', 64);
            $t->string('name');
            $t->string('specialty_code', 64)->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampsTz();
            $t->unique(['provider_facility_id', 'code']);
        });
        Schema::table('provider_departments', fn (Blueprint $t) => $t->foreign('parent_department_id')->references('id')->on('provider_departments'));

        Schema::table('medical_services', function (Blueprint $t) {
            $t->string('name_fr')->nullable();
            $t->string('service_family', 64)->nullable();          // provider.service_family
            $t->string('specialty_code', 64)->nullable();
            $t->string('unit', 32)->nullable();
            $t->boolean('preauthorization_required_default')->nullable(); // NULL = not configured (never invented)
            $t->string('inpatient_outpatient', 16)->nullable();    // INPATIENT | OUTPATIENT | BOTH
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->string('external_standard_code', 64)->nullable();
            $t->string('data_status', 32)->nullable();
            $t->string('data_source', 120)->nullable();
        });

        Schema::table('provider_tariff_versions', function (Blueprint $t) {
            $t->string('data_status', 32)->nullable();             // PENDING_PRIVATE_SOURCE until the signed schedule is loaded
            $t->string('data_source', 120)->nullable();
            $t->string('source_document_reference', 500)->nullable();
        });

        Schema::table('provider_tariff_lines', function (Blueprint $t) {
            $t->string('member_share_type', 16)->nullable();       // NONE | FIXED | PERCENT
            $t->decimal('member_share_value', 14, 2)->nullable();
            $t->unsignedBigInteger('limit_minor')->nullable();
            $t->string('frequency_limit', 64)->nullable();
            $t->boolean('preauthorization_required')->nullable();
        });

        Schema::create('provider_users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->foreignUuid('user_id')->constrained('users');
            $t->string('provider_role', 40);                       // spec roles (provider.provider_portal_role)
            $t->string('facility_scope', 16)->default('ASSIGNED'); // ALL | ASSIGNED
            $t->string('status', 16)->default('ACTIVE');           // ACTIVE | REVOKED
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['provider_profile_id', 'user_id']);
        });

        Schema::create('provider_user_facilities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_user_id')->constrained('provider_users')->cascadeOnDelete();
            $t->foreignUuid('provider_facility_id')->constrained('provider_facilities');
            $t->timestampsTz();
            $t->unique(['provider_user_id', 'provider_facility_id']);
        });

        Schema::create('treatment_episodes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->string('episode_number', 40)->unique();
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->foreignUuid('provider_facility_id')->nullable()->constrained('provider_facilities');
            $t->foreignUuid('provider_department_id')->nullable()->constrained('provider_departments');
            $t->foreignUuid('policy_id')->nullable()->constrained('policies');
            $t->string('member_ref', 120);
            $t->foreignUuid('preauthorization_id')->nullable()->constrained('health_preauthorizations');
            $t->uuid('eligibility_check_id')->nullable();
            $t->string('episode_type', 24);                        // OUTPATIENT | INPATIENT | EMERGENCY | DAY_CASE | PHARMACY | LAB
            $t->string('status', 16)->default('OPEN');             // OPEN | CLOSED | BILLED | CANCELLED
            $t->date('started_on');
            $t->date('ended_on')->nullable();
            $t->text('diagnosis_summary')->nullable();              // medical: restricted to clinical roles
            $t->string('attending_practitioner', 200)->nullable();
            $t->foreignUuid('health_provider_claim_id')->nullable()->constrained('health_provider_claims');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'provider_profile_id', 'status']);
        });

        Schema::create('treatment_episode_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('treatment_episode_id')->constrained('treatment_episodes')->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('medical_service_id')->constrained('medical_services');
            $t->string('provider_code', 64)->nullable();
            $t->unsignedInteger('quantity')->default(1);
            $t->bigInteger('unit_price_minor');
            $t->date('service_date');
            $t->string('performed_by', 200)->nullable();
            $t->timestampsTz();
            $t->unique(['treatment_episode_id', 'line_no']);
        });

        Schema::create('provider_reconciliations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->foreignUuid('settlement_batch_id')->nullable()->constrained('health_provider_settlement_batches');
            $t->string('payment_reference', 120);
            $t->date('received_on');
            $t->string('currency', 3);
            $t->bigInteger('amount_minor');
            $t->bigInteger('allocated_minor')->default(0);
            $t->string('status', 24)->default('UNMATCHED_EXTERNAL'); // spec reconciliation statuses
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'provider_profile_id', 'payment_reference']);
        });

        Schema::create('provider_reconciliation_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_reconciliation_id')->constrained('provider_reconciliations');
            $t->foreignUuid('health_provider_claim_id')->constrained('health_provider_claims');
            $t->bigInteger('amount_minor');
            $t->string('match_type', 16);                          // AUTO | MANUAL
            $t->text('reason')->nullable();                        // mandatory for MANUAL matches
            $t->uuid('created_by')->nullable();
            $t->timestampTz('created_at');
        });

        Schema::create('provider_disputes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->string('dispute_number', 40)->unique();
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->string('subject_type', 24);                        // CLAIM | CLAIM_LINE | SETTLEMENT | RECONCILIATION
            $t->foreignUuid('health_provider_claim_id')->nullable()->constrained('health_provider_claims');
            $t->unsignedSmallInteger('claim_line_no')->nullable();
            $t->foreignUuid('settlement_batch_id')->nullable()->constrained('health_provider_settlement_batches');
            $t->foreignUuid('provider_reconciliation_id')->nullable()->constrained('provider_reconciliations');
            $t->string('reason_code', 48);                         // provider.dispute_reason
            $t->bigInteger('disputed_amount_minor')->default(0);
            $t->string('currency', 3)->nullable();
            $t->text('description');
            $t->string('status', 32)->default('SUBMITTED');        // spec dispute statuses
            $t->text('response')->nullable();
            $t->bigInteger('resolution_amount_minor')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->uuid('resolved_by')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'provider_profile_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE provider_departments ADD CONSTRAINT provider_departments_level_chk CHECK (level IN ('DEPARTMENT','SERVICE_UNIT'))");
            DB::statement("ALTER TABLE provider_users ADD CONSTRAINT provider_users_scope_chk CHECK (facility_scope IN ('ALL','ASSIGNED'))");
            DB::statement("ALTER TABLE treatment_episodes ADD CONSTRAINT treatment_episodes_status_chk CHECK (status IN ('OPEN','CLOSED','BILLED','CANCELLED'))");
            DB::statement('ALTER TABLE treatment_episode_lines ADD CONSTRAINT treatment_episode_lines_amounts_chk CHECK (quantity > 0 AND unit_price_minor >= 0)');
            DB::statement("ALTER TABLE provider_reconciliations ADD CONSTRAINT provider_reconciliations_chk CHECK (amount_minor > 0 AND allocated_minor >= 0 AND allocated_minor <= amount_minor AND status IN ('MATCHED','PARTIALLY_MATCHED','UNMATCHED_INTERNAL','UNMATCHED_EXTERNAL','AMOUNT_MISMATCH','REFERENCE_MISMATCH','DATE_MISMATCH','DUPLICATE','RESOLVED'))");
            DB::statement("ALTER TABLE provider_reconciliation_lines ADD CONSTRAINT provider_reconciliation_lines_chk CHECK (amount_minor > 0 AND match_type IN ('AUTO','MANUAL') AND (match_type = 'AUTO' OR reason IS NOT NULL))");
            DB::statement("ALTER TABLE provider_disputes ADD CONSTRAINT provider_disputes_chk CHECK (subject_type IN ('CLAIM','CLAIM_LINE','SETTLEMENT','RECONCILIATION') AND status IN ('DRAFT','SUBMITTED','ACKNOWLEDGED','UNDER_REVIEW','MORE_INFORMATION_REQUIRED','RESOLVED_PROVIDER','RESOLVED_INSURER','PARTIALLY_RESOLVED','ESCALATED','CLOSED') AND disputed_amount_minor >= 0)");
            // Allocations and disputes never disappear from financial history.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION provider_portal_append_only() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION '% is append-only', TG_TABLE_NAME; END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER provider_reconciliation_lines_no_change BEFORE UPDATE OR DELETE ON provider_reconciliation_lines
                    FOR EACH ROW EXECUTE FUNCTION provider_portal_append_only();
                CREATE TRIGGER provider_disputes_no_delete BEFORE DELETE ON provider_disputes
                    FOR EACH ROW EXECUTE FUNCTION provider_portal_append_only();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS provider_reconciliation_lines_no_change ON provider_reconciliation_lines; DROP TRIGGER IF EXISTS provider_disputes_no_delete ON provider_disputes; DROP FUNCTION IF EXISTS provider_portal_append_only();');
        }
        foreach (['provider_disputes', 'provider_reconciliation_lines', 'provider_reconciliations', 'treatment_episode_lines', 'treatment_episodes', 'provider_user_facilities', 'provider_users', 'provider_departments'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('health_provider_claims', function (Blueprint $t) {
            $t->dropConstrainedForeignId('provider_facility_id');
            $t->dropColumn('source_system');
        });
        Schema::table('provider_tariff_lines', fn (Blueprint $t) => $t->dropColumn(['member_share_type', 'member_share_value', 'limit_minor', 'frequency_limit', 'preauthorization_required']));
        Schema::table('provider_tariff_versions', fn (Blueprint $t) => $t->dropColumn(['data_status', 'data_source', 'source_document_reference']));
        Schema::table('medical_services', fn (Blueprint $t) => $t->dropColumn(['name_fr', 'service_family', 'specialty_code', 'unit', 'preauthorization_required_default', 'inpatient_outpatient', 'effective_from', 'effective_until', 'external_standard_code', 'data_status', 'data_source']));
        Schema::table('provider_facilities', fn (Blueprint $t) => $t->dropColumn(['health_district', 'health_area', 'latitude', 'longitude', 'contacts', 'is_head_office']));
        Schema::table('provider_profiles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_provider_id');
            $t->dropColumn(['official_name', 'trade_name', 'ownership_type', 'provider_category', 'health_district', 'health_area', 'latitude', 'longitude', 'phones', 'emails', 'website',
                'license_or_authorization_reference', 'tax_identifier', 'effective_from', 'effective_until', 'source_url', 'data_status', 'data_source']);
        });
    }
};
