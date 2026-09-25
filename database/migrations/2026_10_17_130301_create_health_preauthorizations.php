<?php

declare(strict_types=1);

use App\Application\Cases\CaseTypeCatalogue;
use App\Application\Health\Preauth\PreauthLifecycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Agent E3 — REQ-HLT-002 health preauthorization + guarantee of payment (App\Application\Health\Preauth).
 * Requests (typed ADMISSION / OUTPATIENT / PHARMACY / LAB), tariff-priced lines, admission stay extensions, an
 * append-only event history and the HEALTH_PREAUTHORIZATION case type (no SLA seeded). Authority reuses the existing
 * catalogue (config health_preauth.authority_type, default CLAIM_SETTLE); no limit is seeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_preauthorizations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->uuid('carrier_id')->nullable()->index();
            $t->string('preauth_number', 40)->unique();
            $t->string('request_type', 16);
            $t->string('status', 24)->default('REQUESTED');
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->string('member_ref', 120);
            $t->foreignUuid('provider_profile_id')->constrained('provider_profiles');
            $t->foreignUuid('provider_facility_id')->nullable()->constrained('provider_facilities');
            $t->foreignUuid('provider_contract_id')->nullable()->constrained('provider_contracts');
            $t->date('service_date');
            $t->jsonb('type_details')->default('{}');
            $t->text('clinical_notes')->nullable();
            $t->jsonb('eligibility')->default('{}');
            $t->boolean('eligible')->default(false);
            $t->string('currency', 3);
            $t->bigInteger('requested_amount_minor')->default(0);
            $t->bigInteger('insurer_amount_minor')->default(0);
            $t->bigInteger('approved_amount_minor')->nullable();
            $t->string('proposed_decision', 16)->nullable();
            $t->string('decision', 16)->nullable();          // APPROVED | PARTIAL | DECLINED | INFO_REQUESTED
            $t->string('decision_reason_code', 64)->nullable();
            $t->text('decision_notes')->nullable();
            $t->text('info_request')->nullable();
            $t->date('gop_valid_from')->nullable();
            $t->date('gop_valid_until')->nullable();
            $t->uuid('gop_manifest_id')->nullable();
            $t->jsonb('benefit_reservations')->default('[]');
            $t->date('admitted_on')->nullable();
            $t->date('approved_until')->nullable();          // ADMISSION: approved stay end (extended by approved extensions)
            $t->date('discharged_on')->nullable();
            $t->foreignUuid('case_id')->nullable()->constrained('cases');
            $t->uuid('maker_authority_check_id')->nullable();
            $t->uuid('checker_authority_check_id')->nullable();
            $t->uuid('referral_case_id')->nullable();
            $t->foreignUuid('requested_by')->nullable()->constrained('users');
            $t->foreignUuid('proposed_by')->nullable()->constrained('users');
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
            $t->index(['policy_id', 'member_ref']);
            $t->index(['provider_profile_id', 'status']);
        });

        Schema::create('health_preauthorization_extensions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('health_preauthorization_id')->constrained('health_preauthorizations');
            $t->unsignedSmallInteger('sequence');
            $t->string('status', 24)->default('REQUESTED');
            $t->date('requested_until');
            $t->bigInteger('requested_amount_minor')->default(0);
            $t->text('reason');
            $t->string('proposed_decision', 16)->nullable();
            $t->date('approved_until')->nullable();
            $t->bigInteger('approved_amount_minor')->nullable();
            $t->string('decision_reason_code', 64)->nullable();
            $t->foreignUuid('case_id')->nullable()->constrained('cases');
            $t->uuid('maker_authority_check_id')->nullable();
            $t->uuid('checker_authority_check_id')->nullable();
            $t->uuid('gop_manifest_id')->nullable();
            $t->foreignUuid('requested_by')->nullable()->constrained('users');
            $t->foreignUuid('proposed_by')->nullable()->constrained('users');
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->timestampsTz();
            $t->unique(['health_preauthorization_id', 'sequence']);
        });

        Schema::create('health_preauthorization_lines', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('health_preauthorization_id')->constrained('health_preauthorizations');
            $t->foreignUuid('extension_id')->nullable()->constrained('health_preauthorization_extensions');
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('medical_service_id')->constrained('medical_services');
            $t->string('service_code', 64);
            $t->string('category_code', 64);
            $t->string('provider_code', 64)->nullable();
            $t->decimal('quantity', 10, 2);
            $t->string('priced_from', 16);                  // TARIFF | REQUESTED
            $t->uuid('tariff_line_id')->nullable();
            $t->bigInteger('unit_price_minor');
            $t->bigInteger('copay_minor')->default(0);
            $t->decimal('insurer_share_percent', 5, 2)->default(100);
            $t->bigInteger('requested_amount_minor');
            $t->bigInteger('insurer_amount_minor');
            $t->jsonb('eligibility')->default('{}');
            $t->string('line_decision', 16)->nullable();    // APPROVED | PARTIAL | DECLINED
            $t->decimal('approved_quantity', 10, 2)->nullable();
            $t->bigInteger('approved_amount_minor')->nullable();
            $t->string('decline_reason', 255)->nullable();
            $t->timestampsTz();
            $t->unique(['health_preauthorization_id', 'line_no']);
        });

        Schema::create('health_preauthorization_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('health_preauthorization_id')->constrained('health_preauthorizations');
            $t->uuid('extension_id')->nullable();
            $t->string('event', 40);
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->text('reason')->nullable();
            $t->jsonb('payload')->default('{}');
            $t->timestampTz('occurred_at');
            $t->index(['health_preauthorization_id', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $states = "'".implode("','", PreauthLifecycle::STATES)."'";
            DB::statement("ALTER TABLE health_preauthorizations ADD CONSTRAINT health_preauth_status_chk CHECK (status IN ({$states}))");
            DB::statement("ALTER TABLE health_preauthorizations ADD CONSTRAINT health_preauth_type_chk CHECK (request_type IN ('ADMISSION','OUTPATIENT','PHARMACY','LAB'))");
            DB::statement("ALTER TABLE health_preauthorizations ADD CONSTRAINT health_preauth_decision_chk CHECK (decision IS NULL OR decision IN ('APPROVED','PARTIAL','DECLINED','INFO_REQUESTED'))");
            DB::statement('ALTER TABLE health_preauthorizations ADD CONSTRAINT health_preauth_validity_chk CHECK (gop_valid_until IS NULL OR gop_valid_from IS NULL OR gop_valid_until >= gop_valid_from)');
            DB::statement('ALTER TABLE health_preauthorizations ADD CONSTRAINT health_preauth_amount_chk CHECK (approved_amount_minor IS NULL OR approved_amount_minor >= 0)');
            DB::statement("ALTER TABLE health_preauthorization_extensions ADD CONSTRAINT health_preauth_ext_status_chk CHECK (status IN ('REQUESTED','PENDING_APPROVAL','REFERRED','APPROVED','PARTIALLY_APPROVED','DECLINED','CANCELLED'))");
            DB::statement('ALTER TABLE health_preauthorization_lines ADD CONSTRAINT health_preauth_line_qty_chk CHECK (quantity > 0 AND (approved_quantity IS NULL OR (approved_quantity >= 0 AND approved_quantity <= quantity)))');
            DB::statement('ALTER TABLE health_preauthorization_events ADD COLUMN seq bigserial');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION health_preauthorization_events_immutable() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'health_preauthorization_events is append-only'; END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER health_preauthorization_events_no_change BEFORE UPDATE OR DELETE ON health_preauthorization_events
                    FOR EACH ROW EXECUTE FUNCTION health_preauthorization_events_immutable();
            SQL);
        }

        // SLA via the case engine; no targets seeded.
        if (! DB::table('case_types')->where('code', PreauthLifecycle::CASE_TYPE)->exists()) {
            $def = PreauthLifecycle::caseDefinition();
            CaseTypeCatalogue::validate($def);
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => PreauthLifecycle::CASE_TYPE, 'version' => 1,
                'family_code' => DB::table('case_families')->where('code', 'CLAIMS')->exists() ? 'CLAIMS' : null,
                'name' => 'Health preauthorization / guarantee of payment', 'status' => 'EFFECTIVE', 'valid_from' => '2026-01-01', 'valid_to' => null,
                'states' => json_encode($def['states']), 'transitions' => json_encode($def['transitions']),
                'sla_policies' => json_encode($def['sla_policies']), 'auto_tasks' => '[]', 'subtypes' => json_encode(PreauthLifecycle::CASE_SUBTYPES),
                'default_confidentiality' => 'RESTRICTED', 'regulated' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS health_preauthorization_events_no_change ON health_preauthorization_events; DROP FUNCTION IF EXISTS health_preauthorization_events_immutable();');
        }
        Schema::dropIfExists('health_preauthorization_events');
        Schema::dropIfExists('health_preauthorization_lines');
        Schema::dropIfExists('health_preauthorization_extensions');
        Schema::dropIfExists('health_preauthorizations');
        // Case type versions and authority types are reference data: never deleted.
    }
};
