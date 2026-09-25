<?php

declare(strict_types=1);

use App\Application\Cases\CaseTypeCatalogue;
use App\Application\Claims\Adjusters\ExpertAssignmentLifecycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * REQ-CLM-009 / WF-053 — expert / adjuster assignments.
 *
 * Extends the existing claim_assignments table (handler assignments stay assignment_type = HANDLER) with EXPERT rows
 * that point at the Batch 13A provider master (provider_profiles ADJUSTER/EXPERT), the insurer's network + contract,
 * the tariff-priced fee, and the lifecycle ASSIGNMENT_PENDING → … → REPORT_ACCEPTED. Every step is also an
 * append-only claim_assignment_events row. The SLA runs on a CLAIM_EXPERT_ASSIGNMENT case (case engine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_assignments', function (Blueprint $t): void {
            $t->uuid('assignee_id')->nullable()->change();
            $t->string('assignment_type', 16)->default('HANDLER');
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $t->foreignUuid('provider_profile_id')->nullable()->constrained('provider_profiles');
            $t->foreignUuid('provider_network_id')->nullable()->constrained('provider_networks');
            $t->foreignUuid('provider_contract_id')->nullable()->constrained('provider_contracts');
            $t->string('status', 32)->nullable();
            $t->foreignUuid('fee_service_id')->nullable()->constrained('medical_services');
            $t->uuid('fee_tariff_line_id')->nullable();
            $t->unsignedBigInteger('fee_amount_minor')->nullable();
            $t->string('fee_currency', 3)->nullable();
            $t->foreignUuid('case_id')->nullable()->constrained('cases');
            $t->text('instructions')->nullable();
            $t->text('decline_reason')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('inspection_scheduled_for')->nullable();
            $t->string('inspection_location', 255)->nullable();
            $t->timestampTz('inspected_at')->nullable();
            $t->text('inspection_notes')->nullable();
            $t->text('report_summary')->nullable();
            $t->unsignedBigInteger('assessed_loss_minor')->nullable();
            $t->foreignUuid('report_document_id')->nullable()->constrained('documents');
            $t->timestampTz('report_submitted_at')->nullable();
            $t->unsignedSmallInteger('return_count')->default(0);
            $t->uuid('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('review_notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('updated_at')->nullable();
            $t->index(['provider_profile_id', 'status']);
            $t->index(['claim_id', 'assignment_type']);
        });

        Schema::create('claim_assignment_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('claim_assignment_id')->constrained('claim_assignments')->cascadeOnDelete();
            $t->string('event', 32);
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32);
            $t->uuid('actor_id')->nullable();
            $t->string('actor_side', 16);              // INSURER | ADJUSTER
            $t->text('reason')->nullable();
            $t->jsonb('payload')->default('{}');
            $t->timestampTz('occurred_at');
            $t->index(['claim_assignment_id', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $states = "'".implode("','", ExpertAssignmentLifecycle::STATES)."'";
            DB::statement("ALTER TABLE claim_assignments ADD CONSTRAINT claim_assignments_type_chk CHECK (assignment_type IN ('HANDLER','EXPERT'))");
            DB::statement("ALTER TABLE claim_assignments ADD CONSTRAINT claim_assignments_expert_chk CHECK (
                (assignment_type = 'HANDLER' AND assignee_id IS NOT NULL AND status IS NULL)
                OR (assignment_type = 'EXPERT' AND provider_profile_id IS NOT NULL AND provider_network_id IS NOT NULL AND tenant_id IS NOT NULL AND status IN ({$states})))");
            DB::statement('ALTER TABLE claim_assignment_events ADD COLUMN seq bigserial');
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION claim_assignment_events_immutable() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'claim_assignment_events is append-only'; END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER claim_assignment_events_no_change BEFORE UPDATE OR DELETE ON claim_assignment_events
                    FOR EACH ROW EXECUTE FUNCTION claim_assignment_events_immutable();
            SQL);
        }

        // SLA via the case engine: one case per expert assignment, states mirror the assignment lifecycle.
        if (! DB::table('case_types')->where('code', ExpertAssignmentLifecycle::CASE_TYPE)->exists()) {
            $def = ExpertAssignmentLifecycle::caseDefinition();
            CaseTypeCatalogue::validate($def);
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => ExpertAssignmentLifecycle::CASE_TYPE, 'version' => 1,
                'family_code' => DB::table('case_families')->where('code', 'CLAIMS')->exists() ? 'CLAIMS' : null,
                'name' => 'Claim expert / adjuster assignment', 'status' => 'EFFECTIVE', 'valid_from' => '2026-01-01', 'valid_to' => null,
                'states' => json_encode($def['states']), 'transitions' => json_encode($def['transitions']),
                'sla_policies' => json_encode($def['sla_policies']), 'auto_tasks' => '[]', 'default_confidentiality' => 'NORMAL', 'regulated' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS claim_assignment_events_no_change ON claim_assignment_events; DROP FUNCTION IF EXISTS claim_assignment_events_immutable();');
            DB::statement('ALTER TABLE claim_assignments DROP CONSTRAINT IF EXISTS claim_assignments_expert_chk');
            DB::statement('ALTER TABLE claim_assignments DROP CONSTRAINT IF EXISTS claim_assignments_type_chk');
        }
        Schema::dropIfExists('claim_assignment_events');
        DB::table('claim_assignments')->where('assignment_type', 'EXPERT')->delete();
        Schema::table('claim_assignments', function (Blueprint $t): void {
            $t->dropIndex(['provider_profile_id', 'status']);
            $t->dropIndex(['claim_id', 'assignment_type']);
            foreach (['tenant_id', 'provider_profile_id', 'provider_network_id', 'provider_contract_id', 'fee_service_id', 'case_id', 'report_document_id'] as $fk) {
                $t->dropConstrainedForeignId($fk);
            }
            $t->dropColumn(['assignment_type', 'status', 'fee_tariff_line_id', 'fee_amount_minor', 'fee_currency', 'instructions', 'decline_reason', 'accepted_at',
                'inspection_scheduled_for', 'inspection_location', 'inspected_at', 'inspection_notes', 'report_summary', 'assessed_loss_minor',
                'report_submitted_at', 'return_count', 'reviewed_by', 'reviewed_at', 'review_notes', 'version', 'updated_at']);
        });
        // Case type versions are reference data: never deleted.
    }
};
