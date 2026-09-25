<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 13A — REQ-PRV-001 / REQ-PRV-002 / REQ-PRV-004 (FINANCE_REINSURANCE_PROVIDER_EXPANSION_V1 Part IV).
 *
 * A provider is NOT a parallel identity: it is a party (parties) holding a canonical partner row (partners, type
 * HEALTH_PROVIDER | GARAGE | ADJUSTER | EXPERT | SURVEYOR) plus one provider_profiles row carrying the credentialing
 * state. Canonical providers are platform-wide (never duplicated per insurer); insurers attach them through their
 * own networks, memberships, contracts and versioned tariffs. Explicit provider ↔ insurer relationships (REQ-PRV-004)
 * are rows in the existing party_relationships graph (REQ-PTY-003), not a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('partner_id')->unique()->constrained('partners');
            $t->foreignUuid('party_id')->constrained('parties');
            $t->string('category', 24);                       // HEALTH | GARAGE | ADJUSTER | EXPERT | SURVEYOR
            $t->string('provider_type_code', 64);             // master data provider.provider_type / partners.adjuster_type …
            $t->string('credentialing_status', 16)->default('PROSPECT');
            $t->uuid('legacy_health_provider_id')->nullable(); // health_providers (master data engine) row this replaces
            $t->string('registration_number', 120)->nullable();
            $t->string('country_code', 2)->default('CM');
            $t->string('city_code', 64)->nullable();
            $t->string('region_code', 64)->nullable();
            $t->jsonb('details')->default('{}');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['category', 'credentialing_status']);
        });

        Schema::create('provider_credentialing_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->string('from_status', 16)->nullable();
            $t->string('to_status', 16);
            $t->text('reason')->nullable();
            $t->string('evidence_reference', 500)->nullable();
            $t->uuid('actor_id')->nullable();
            $t->timestampTz('occurred_at');
            $t->index(['provider_profile_id', 'occurred_at']);
        });

        Schema::create('provider_facilities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->string('code', 64);
            $t->string('name');
            $t->string('facility_type_code', 64)->nullable();
            $t->string('city_code', 64)->nullable();
            $t->string('region_code', 64)->nullable();
            $t->text('address')->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampsTz();
            $t->unique(['provider_profile_id', 'code']);
        });

        Schema::create('provider_facility_specialties', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_facility_id')->constrained('provider_facilities');
            $t->string('specialty_code', 64);                  // provider.medical_specialty
            $t->timestampsTz();
            $t->unique(['provider_facility_id', 'specialty_code']);
        });

        // Medical service catalogue (platform-wide) + provider code mapping.
        Schema::create('medical_services', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 64)->unique();
            $t->string('name');
            $t->string('category_code', 64);                   // MEDICAL_SERVICE_CATEGORIES
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampsTz();
        });

        Schema::create('provider_facility_services', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_facility_id')->constrained('provider_facilities');
            $t->foreignUuid('medical_service_id')->constrained('medical_services');
            $t->string('specialty_code', 64)->nullable();
            $t->timestampsTz();
            $t->unique(['provider_facility_id', 'medical_service_id']);
        });

        Schema::create('provider_service_code_mappings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->string('provider_code', 120);
            $t->foreignUuid('medical_service_id')->constrained('medical_services');
            $t->timestampsTz();
            $t->unique(['provider_profile_id', 'provider_code']);
        });

        // Insurer-named networks (tenant = insurer workspace).
        Schema::create('provider_networks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->foreignUuid('carrier_id')->nullable()->constrained('carriers');
            $t->string('code', 64);
            $t->string('name');
            $t->string('network_type_code', 64);              // NETWORK_TYPES
            $t->string('category', 24)->default('HEALTH');
            $t->string('status', 16)->default('ACTIVE');
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('provider_network_memberships', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_network_id')->constrained('provider_networks');
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->foreignUuid('provider_facility_id')->nullable()->constrained('provider_facilities');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('status', 16)->default('ACTIVE');       // ACTIVE | ENDED
            $t->text('end_reason')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->index(['provider_network_id', 'provider_profile_id']);
        });

        Schema::create('provider_contracts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->foreignUuid('provider_network_id')->constrained('provider_networks');
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->string('contract_number', 80);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('settlement_mode', 16)->default('CASHLESS'); // CASHLESS | REIMBURSEMENT | BOTH
            $t->string('status', 16)->default('DRAFT');              // DRAFT | ACTIVE | TERMINATED
            $t->string('document_reference', 500)->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'contract_number']);
        });

        Schema::create('provider_tariff_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_contract_id')->constrained('provider_contracts');
            $t->unsignedInteger('version');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('currency', 3)->default('XAF');
            $t->string('status', 16)->default('DRAFT');        // DRAFT | APPROVED | SUPERSEDED
            $t->uuid('created_by')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['provider_contract_id', 'version']);
        });

        Schema::create('provider_tariff_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('provider_tariff_version_id')->constrained('provider_tariff_versions');
            $t->foreignUuid('medical_service_id')->constrained('medical_services');
            $t->unsignedBigInteger('price_minor');
            $t->unsignedBigInteger('contracted_price_minor');
            $t->unsignedBigInteger('copay_minor')->default(0);
            $t->decimal('insurer_share_percent', 5, 2);
            $t->timestampsTz();
            $t->unique(['provider_tariff_version_id', 'medical_service_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE provider_credentialing_events ADD COLUMN seq bigserial');
            DB::statement("ALTER TABLE provider_profiles ADD CONSTRAINT provider_profiles_category_chk CHECK (category IN ('HEALTH','GARAGE','ADJUSTER','EXPERT','SURVEYOR'))");
            DB::statement("ALTER TABLE provider_profiles ADD CONSTRAINT provider_profiles_status_chk CHECK (credentialing_status IN ('PROSPECT','APPLICATION','UNDER_REVIEW','APPROVED','ACTIVE','SUSPENDED','TERMINATED'))");
            DB::statement("ALTER TABLE provider_network_memberships ADD CONSTRAINT provider_memberships_dates_chk CHECK (effective_to IS NULL OR effective_to > effective_from)");
            DB::statement("ALTER TABLE provider_contracts ADD CONSTRAINT provider_contracts_mode_chk CHECK (settlement_mode IN ('CASHLESS','REIMBURSEMENT','BOTH'))");
            DB::statement("ALTER TABLE provider_tariff_versions ADD CONSTRAINT provider_tariff_status_chk CHECK (status IN ('DRAFT','APPROVED','SUPERSEDED'))");
            DB::statement('ALTER TABLE provider_tariff_versions ADD CONSTRAINT provider_tariff_maker_checker_chk CHECK (approved_by IS NULL OR created_by IS NULL OR approved_by <> created_by)');
            DB::statement('ALTER TABLE provider_tariff_lines ADD CONSTRAINT provider_tariff_lines_amounts_chk CHECK (contracted_price_minor <= price_minor AND copay_minor <= contracted_price_minor AND insurer_share_percent BETWEEN 0 AND 100)');
            // Credentialing history is append-only.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION provider_credentialing_events_immutable() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'provider_credentialing_events is append-only'; END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER provider_credentialing_events_no_change BEFORE UPDATE OR DELETE ON provider_credentialing_events
                    FOR EACH ROW EXECUTE FUNCTION provider_credentialing_events_immutable();
            SQL);
            // Approved tariff lines are frozen: a price change is a new version.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION provider_tariff_lines_frozen() RETURNS trigger AS $$
                DECLARE s text;
                BEGIN
                    SELECT status INTO s FROM provider_tariff_versions WHERE id = COALESCE(OLD.provider_tariff_version_id, NEW.provider_tariff_version_id);
                    IF s <> 'DRAFT' THEN RAISE EXCEPTION 'Tariff version is % — create a new version', s; END IF;
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER provider_tariff_lines_frozen BEFORE INSERT OR UPDATE OR DELETE ON provider_tariff_lines
                    FOR EACH ROW EXECUTE FUNCTION provider_tariff_lines_frozen();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS provider_tariff_lines_frozen ON provider_tariff_lines; DROP FUNCTION IF EXISTS provider_tariff_lines_frozen();');
            DB::unprepared('DROP TRIGGER IF EXISTS provider_credentialing_events_no_change ON provider_credentialing_events; DROP FUNCTION IF EXISTS provider_credentialing_events_immutable();');
        }
        foreach (['provider_tariff_lines', 'provider_tariff_versions', 'provider_contracts', 'provider_network_memberships', 'provider_networks',
            'provider_service_code_mappings', 'provider_facility_services', 'medical_services', 'provider_facility_specialties', 'provider_facilities',
            'provider_credentialing_events', 'provider_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
