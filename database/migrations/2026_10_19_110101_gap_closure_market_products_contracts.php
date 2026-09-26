<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap Closure Pack file 01 (Insurance Market, Product, Agreement & Commission Master) — additive only.
 * Every gated dataset extends its CANONICAL table (no parallel tables):
 *  - insurer CIMA branch authorizations  → insurer_regulatory_authorizations (+ reasons, verifier, source URL, import batch)
 *  - broker directory enrichment          → institution_profiles / institution_offices (now carrier XOR partner)
 *  - broker–insurer agreements            → carrier_broker_agreements (+ contract terms) / _products (+ endorse, renew)
 *  - insurer product catalogue            → insurance_products (+ class, channels, modes, rules, sources; B2C off)
 *  - commission tables                    → commission_rule_versions (+ beneficiary, basis, fixed amount, event, tax)
 * Gated values default to their pack status (PENDING_PRIVATE_SOURCE …) and are never invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurer_regulatory_authorizations', function (Blueprint $t) {
            $t->text('suspension_reason')->nullable();
            $t->text('revocation_reason')->nullable();
            $t->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('verified_at')->nullable();
            $t->string('source_url', 512)->nullable();
            $t->uuid('import_batch_id')->nullable()->index();
        });

        // Broker directory enrichment reuses the insurer institutional directory tables.
        DB::statement('ALTER TABLE institution_profiles ALTER COLUMN carrier_id DROP NOT NULL');
        DB::statement('ALTER TABLE institution_offices ALTER COLUMN carrier_id DROP NOT NULL');
        Schema::table('institution_profiles', function (Blueprint $t) {
            $t->foreignUuid('partner_id')->nullable()->unique()->constrained('partners')->cascadeOnDelete();
            $t->unsignedInteger('official_sequence')->nullable();
            $t->string('locality', 120)->nullable();
            $t->string('street_address', 255)->nullable();
            $t->string('responsible_person', 255)->nullable();
            $t->string('license_reference', 120)->nullable();
            $t->unsignedSmallInteger('authorized_year')->nullable();
            $t->string('source_url', 512)->nullable();
            $t->uuid('import_batch_id')->nullable();
        });
        Schema::table('institution_offices', function (Blueprint $t) {
            $t->foreignUuid('partner_id')->nullable()->constrained('partners')->cascadeOnDelete();
            $t->unique(['partner_id', 'name']);
        });
        DB::statement('ALTER TABLE institution_profiles ADD CONSTRAINT institution_profiles_subject_check CHECK ((carrier_id IS NULL) <> (partner_id IS NULL))');
        DB::statement('ALTER TABLE institution_offices ADD CONSTRAINT institution_offices_subject_check CHECK ((carrier_id IS NULL) <> (partner_id IS NULL))');

        Schema::table('carrier_broker_agreements', function (Blueprint $t) {
            $t->string('agreement_type', 40)->nullable();
            $t->jsonb('authorized_cima_branches')->default('[]');
            $t->jsonb('premium_remittance_terms')->nullable();
            $t->jsonb('cancellation_terms')->nullable();
            $t->uuid('commission_rule_set_id')->nullable();
            $t->uuid('sla_profile_id')->nullable();
            $t->uuid('settlement_profile_id')->nullable();
            $t->string('data_exchange_mode', 24)->nullable();   // MANUAL | FILE | API
            $t->uuid('api_profile_id')->nullable();
            $t->uuid('source_document_id')->nullable();
            $t->string('data_status', 32)->default('PENDING_PRIVATE_SOURCE');
        });
        DB::table('carrier_broker_agreements')->where('is_demo', true)->update(['data_status' => 'DEMO']);
        Schema::table('carrier_broker_agreement_products', function (Blueprint $t) {
            $t->boolean('can_endorse')->default(false);
            $t->boolean('can_renew')->default(false);
        });

        Schema::table('insurance_products', function (Blueprint $t) {
            $t->string('product_class', 32)->nullable();
            $t->jsonb('distribution_channels')->default('[]');
            $t->boolean('direct_b2c_enabled')->default(false);
            $t->string('tariff_mode', 32)->nullable();
            $t->string('underwriting_mode', 32)->nullable();
            $t->jsonb('claims_requirements')->nullable();
            $t->jsonb('renewal_rules')->nullable();
            $t->jsonb('cancellation_rules')->nullable();
            $t->uuid('document_requirement_profile_id')->nullable();
            $t->jsonb('source_document_ids')->default('[]');
            $t->string('catalogue_data_status', 32)->nullable();   // PENDING_PRIVATE_SOURCE until the insurer's source is attached
        });

        Schema::table('commission_rule_versions', function (Blueprint $t) {
            $t->string('beneficiary_type', 24)->nullable();   // BROKER | AGENT | SUB_AGENT | REFERRER | OTHER_AUTHORIZED
            $t->string('basis_type', 24)->nullable();         // WRITTEN_PREMIUM | COLLECTED_PREMIUM | …
            $t->bigInteger('fixed_amount_minor')->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('cima_branch_code', 32)->nullable();
            $t->uuid('insurance_class_id')->nullable();
            $t->uuid('agent_id')->nullable();
            $t->string('channel', 32)->nullable();
            $t->string('earning_event', 32)->nullable();
            $t->jsonb('clawback_rule')->nullable();
            $t->string('tax_treatment', 48)->nullable();
            $t->string('source_document', 512)->nullable();
            $t->string('data_status', 32)->nullable();
        });
        DB::statement("ALTER TABLE commission_rule_versions ADD CONSTRAINT commission_rule_beneficiary_valid CHECK (beneficiary_type IS NULL OR beneficiary_type IN ('BROKER','AGENT','SUB_AGENT','REFERRER','OTHER_AUTHORIZED'))");
        DB::statement("ALTER TABLE commission_rule_versions ADD CONSTRAINT commission_rule_basis_type_valid CHECK (basis_type IS NULL OR basis_type IN ('WRITTEN_PREMIUM','COLLECTED_PREMIUM','NET_PREMIUM','GROSS_PREMIUM','FIXED_AMOUNT','INSTALLMENT_BASED'))");
        DB::statement('ALTER TABLE commission_rule_versions ADD CONSTRAINT commission_rule_fixed_amount_valid CHECK (fixed_amount_minor IS NULL OR fixed_amount_minor >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE commission_rule_versions DROP CONSTRAINT IF EXISTS commission_rule_beneficiary_valid, DROP CONSTRAINT IF EXISTS commission_rule_basis_type_valid, DROP CONSTRAINT IF EXISTS commission_rule_fixed_amount_valid');
        Schema::table('commission_rule_versions', fn (Blueprint $t) => $t->dropColumn(['beneficiary_type', 'basis_type', 'fixed_amount_minor', 'currency', 'cima_branch_code', 'insurance_class_id', 'agent_id', 'channel', 'earning_event', 'clawback_rule', 'tax_treatment', 'source_document', 'data_status']));
        Schema::table('insurance_products', fn (Blueprint $t) => $t->dropColumn(['product_class', 'distribution_channels', 'direct_b2c_enabled', 'tariff_mode', 'underwriting_mode', 'claims_requirements', 'renewal_rules', 'cancellation_rules', 'document_requirement_profile_id', 'source_document_ids', 'catalogue_data_status']));
        Schema::table('carrier_broker_agreement_products', fn (Blueprint $t) => $t->dropColumn(['can_endorse', 'can_renew']));
        Schema::table('carrier_broker_agreements', fn (Blueprint $t) => $t->dropColumn(['agreement_type', 'authorized_cima_branches', 'premium_remittance_terms', 'cancellation_terms', 'commission_rule_set_id', 'sla_profile_id', 'settlement_profile_id', 'data_exchange_mode', 'api_profile_id', 'source_document_id', 'data_status']));
        DB::statement('ALTER TABLE institution_profiles DROP CONSTRAINT IF EXISTS institution_profiles_subject_check');
        DB::statement('ALTER TABLE institution_offices DROP CONSTRAINT IF EXISTS institution_offices_subject_check');
        Schema::table('institution_offices', function (Blueprint $t) {
            $t->dropUnique(['partner_id', 'name']);
            $t->dropConstrainedForeignId('partner_id');
        });
        Schema::table('institution_profiles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('partner_id');
            $t->dropColumn(['official_sequence', 'locality', 'street_address', 'responsible_person', 'license_reference', 'authorized_year', 'source_url', 'import_batch_id']);
        });
        Schema::table('insurer_regulatory_authorizations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('verified_by');
            $t->dropColumn(['suspension_reason', 'revocation_reason', 'verified_at', 'source_url', 'import_batch_id']);
        });
    }
};
