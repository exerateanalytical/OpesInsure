<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-AOM-001 insurer capability profile (versioned, maker-checker, pinned on transactions),
 * REQ-SET-002 insurer setup lifecycle + 23-item activation checklist,
 * REQ-SET-003 broker setup lifecycle + 19-item activation checklist.
 *
 * Additive only. Policy issuance / document generation modes are NOT stored twice:
 * they are derived from document_issuance_profiles (document engine) — see CapabilityResolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_capability_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->constrained('carriers')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 16)->default('DRAFT'); // DRAFT | SUBMITTED | ACTIVE | SUPERSEDED | REJECTED
            $t->timestampTz('effective_from')->nullable();
            $t->timestampTz('effective_until')->nullable();
            $t->unsignedSmallInteger('maturity_level')->nullable();
            $t->string('maturity_code', 32)->nullable();
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('submitted_by')->nullable()->constrained('users');
            $t->timestampTz('submitted_at')->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->text('notes')->nullable();
            $t->timestampsTz();
            $t->unique(['carrier_id', 'version']);
        });
        DB::statement("ALTER TABLE carrier_capability_profiles ADD CONSTRAINT carrier_capability_profiles_status CHECK (status IN ('DRAFT','SUBMITTED','ACTIVE','SUPERSEDED','REJECTED'))");
        DB::statement("ALTER TABLE carrier_capability_profiles ADD CONSTRAINT carrier_capability_profiles_maturity CHECK (maturity_level IS NULL OR maturity_level BETWEEN 1 AND 5)");
        DB::statement("CREATE UNIQUE INDEX carrier_capability_profiles_one_active ON carrier_capability_profiles (carrier_id) WHERE status = 'ACTIVE'");

        Schema::create('carrier_capability_modes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('profile_id')->constrained('carrier_capability_profiles')->cascadeOnDelete();
            $t->string('capability', 40);
            $t->string('mode', 40);
            $t->string('execution_mode', 16); // MANUAL | CONFIGURED | HYBRID | REMOTE_API
            $t->foreignUuid('scope_product_id')->nullable()->constrained('insurance_products');
            $t->string('scope_class_code', 64)->nullable();
            $t->jsonb('config')->default('{}');
            $t->string('fallback_mode', 40)->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE carrier_capability_modes ADD CONSTRAINT carrier_capability_modes_exec CHECK (execution_mode IN ('MANUAL','CONFIGURED','HYBRID','REMOTE_API'))");
        DB::statement("CREATE UNIQUE INDEX carrier_capability_modes_scope ON carrier_capability_modes (profile_id, capability, COALESCE(scope_product_id, '00000000-0000-0000-0000-000000000000'::uuid), COALESCE(scope_class_code, ''))");

        // Immutable pin of the resolved mode on a transaction (quote, proposal, issuance request, claim…).
        Schema::create('capability_pins', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('subject_type', 48);
            $t->uuid('subject_id');
            $t->foreignUuid('carrier_id')->constrained('carriers');
            $t->foreignUuid('product_id')->nullable()->constrained('insurance_products');
            $t->string('capability', 40);
            $t->string('mode', 40);
            $t->string('execution_mode', 16);
            $t->foreignUuid('profile_id')->nullable()->constrained('carrier_capability_profiles');
            $t->unsignedInteger('profile_version')->nullable();
            $t->string('source', 32); // CAPABILITY_PROFILE | DOCUMENT_ISSUANCE_PROFILE | DEFAULT
            $t->foreignUuid('pinned_by')->nullable()->constrained('users');
            $t->timestampTz('pinned_at');
            $t->unique(['subject_type', 'subject_id', 'capability']);
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION capability_pins_immutable() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'capability_pins are immutable (REQ-AOM-001)'; END; $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER capability_pins_no_update BEFORE UPDATE OR DELETE ON capability_pins FOR EACH ROW EXECUTE FUNCTION capability_pins_immutable()');

        Schema::create('carrier_setups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->unique()->constrained('carriers')->restrictOnDelete();
            $t->string('status', 24)->default('DRAFT');
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('submitted_for_approval_by')->nullable()->constrained('users');
            $t->timestampTz('submitted_for_approval_at')->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('activated_at')->nullable();
            $t->jsonb('activation_snapshot')->nullable();
            $t->text('notes')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE carrier_setups ADD CONSTRAINT carrier_setups_status CHECK (status IN ('DRAFT','REGULATORY_REVIEW','CONFIGURATION','TESTING','READY_FOR_APPROVAL','ACTIVE','SUSPENDED','INACTIVE','TERMINATED'))");

        Schema::create('partner_setups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('partner_id')->unique()->constrained('partners')->restrictOnDelete();
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $t->string('status', 24)->default('DRAFT');
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('submitted_for_activation_by')->nullable()->constrained('users');
            $t->timestampTz('submitted_for_activation_at')->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('activated_at')->nullable();
            $t->jsonb('activation_snapshot')->nullable();
            $t->text('notes')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE partner_setups ADD CONSTRAINT partner_setups_status CHECK (status IN ('DRAFT','REVIEW','CONFIGURATION','TESTING','ACTIVE','SUSPENDED','TERMINATED'))");

        // Manual attestations for checklist items that cannot be evaluated from data (shared by both lifecycles).
        Schema::create('setup_checklist_attestations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('setup_type', 16); // CARRIER | PARTNER
            $t->uuid('setup_id');
            $t->string('item_code', 48);
            $t->string('status', 16); // COMPLETE | NOT_APPLICABLE | PENDING
            $t->string('evidence_reference', 255)->nullable();
            $t->text('notes')->nullable();
            $t->foreignUuid('recorded_by')->constrained('users');
            $t->timestampTz('recorded_at');
            $t->timestampsTz();
            $t->unique(['setup_type', 'setup_id', 'item_code']);
        });
        DB::statement("ALTER TABLE setup_checklist_attestations ADD CONSTRAINT setup_checklist_attestations_status CHECK (status IN ('COMPLETE','NOT_APPLICABLE','PENDING'))");
    }

    public function down(): void
    {
        foreach (['setup_checklist_attestations', 'partner_setups', 'carrier_setups', 'capability_pins', 'carrier_capability_modes', 'carrier_capability_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS capability_pins_immutable() CASCADE');
    }
};
