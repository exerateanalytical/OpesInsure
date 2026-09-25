<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Agent E10 — REQ-CMP-001 compliance cases on the case engine + REQ-CMP-003 governance registers.
 |
 |  - compliance_cases stays the regulatory record (subject, severity, closure) and keeps its own
 |    status + compliance_case_events; the workflow container is the linked COMPLIANCE_INVESTIGATION
 |    case (compliance_cases.case_id, LegacyWorkItemBridge), driven by ComplianceCaseService in the
 |    same transaction. The legacy findings jsonb is migrated forward into compliance_findings and no
 |    longer written.
 |  - compliance_findings / compliance_corrective_actions (owner, due date, maker-checker verification)
 |    / compliance_evidence_links (document or external reference, sha256 pinned).
 |  - REQ-CMP-003 (Reg. 010-24 ICT + vendor/outsourcing governance): STRUCTURE ONLY. OQ-8.1 — the owner
 |    has not supplied the requirement text, so no obligation, deadline or rating threshold is encoded;
 |    registers carry an `attributes` jsonb for owner-defined fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_findings', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('compliance_case_id')->constrained()->cascadeOnDelete();
            $t->string('finding_number', 64)->unique();
            $t->string('title', 255);
            $t->text('description')->nullable();
            $t->string('severity', 16);
            $t->string('category', 64)->nullable();
            $t->string('status', 24)->default('OPEN');
            $t->foreignUuid('raised_by')->nullable()->constrained('users');
            $t->timestampTz('closed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->index(['compliance_case_id', 'status']);
        });
        DB::statement("ALTER TABLE compliance_findings ADD CONSTRAINT compliance_findings_severity CHECK (severity IN ('LOW','MEDIUM','HIGH','CRITICAL'))");
        DB::statement("ALTER TABLE compliance_findings ADD CONSTRAINT compliance_findings_status CHECK (status IN ('OPEN','REMEDIATING','CLOSED','WITHDRAWN'))");

        Schema::create('compliance_corrective_actions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('compliance_case_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('finding_id')->constrained('compliance_findings')->cascadeOnDelete();
            $t->text('description');
            $t->foreignUuid('owner_user_id')->constrained('users');
            $t->date('due_on');
            $t->string('status', 24)->default('PLANNED');
            $t->uuid('case_task_id')->nullable()->index();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('completed_by')->nullable()->constrained('users');
            $t->timestampTz('completed_at')->nullable();
            $t->text('completion_notes')->nullable();
            $t->foreignUuid('verified_by')->nullable()->constrained('users');
            $t->timestampTz('verified_at')->nullable();
            $t->text('verification_notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->index(['tenant_id', 'status', 'due_on']);
        });
        DB::statement("ALTER TABLE compliance_corrective_actions ADD CONSTRAINT compliance_actions_status CHECK (status IN ('PLANNED','IN_PROGRESS','COMPLETED','VERIFIED','CANCELLED'))");
        DB::statement('ALTER TABLE compliance_corrective_actions ADD CONSTRAINT compliance_actions_verifier_separation CHECK (verified_by IS NULL OR verified_by <> completed_by)');

        Schema::create('compliance_evidence_links', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('compliance_case_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('finding_id')->nullable()->constrained('compliance_findings')->cascadeOnDelete();
            $t->foreignUuid('corrective_action_id')->nullable()->constrained('compliance_corrective_actions')->cascadeOnDelete();
            $t->foreignUuid('document_id')->nullable()->constrained('documents');
            $t->string('external_reference', 255)->nullable();
            $t->string('sha256', 64)->nullable();
            $t->string('description', 1000);
            $t->foreignUuid('linked_by')->constrained('users');
            $t->timestampTz('linked_at');
        });
        DB::statement('ALTER TABLE compliance_evidence_links ADD CONSTRAINT compliance_evidence_target CHECK (document_id IS NOT NULL OR external_reference IS NOT NULL)');

        // --- REQ-CMP-003 registers (structure only, OQ-8.1) ---
        Schema::create('governance_ict_assets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('asset_code', 64);
            $t->string('name', 255);
            $t->string('category', 64);
            $t->string('criticality', 16)->nullable();
            $t->foreignUuid('owner_user_id')->nullable()->constrained('users');
            $t->uuid('vendor_id')->nullable()->index();
            $t->string('status', 24)->default('ACTIVE');
            $t->jsonb('attributes')->default('{}');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'asset_code']);
        });
        Schema::create('governance_ict_incidents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('incident_number', 64)->unique();
            $t->foreignUuid('ict_asset_id')->nullable()->constrained('governance_ict_assets');
            $t->string('title', 255);
            $t->text('description')->nullable();
            $t->string('severity', 16);
            $t->string('status', 24)->default('OPEN');
            $t->timestampTz('detected_at');
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampTz('reported_externally_at')->nullable();
            $t->string('external_reference', 255)->nullable();
            $t->uuid('compliance_case_id')->nullable()->index();
            $t->foreignUuid('recorded_by')->nullable()->constrained('users');
            $t->jsonb('attributes')->default('{}');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('governance_vendors', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('vendor_code', 64);
            $t->string('name', 255);
            $t->foreignUuid('party_id')->nullable()->constrained('parties');
            $t->text('services')->nullable();
            $t->boolean('is_outsourcing')->default(false);
            $t->string('criticality', 16)->nullable();
            $t->string('risk_rating', 16)->nullable();
            $t->string('status', 24)->default('ACTIVE');
            $t->jsonb('attributes')->default('{}');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'vendor_code']);
        });
        Schema::create('governance_outsourcing_contracts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('vendor_id')->constrained('governance_vendors');
            $t->string('contract_reference', 128);
            $t->text('service_description');
            $t->date('start_on');
            $t->date('end_on')->nullable();
            $t->string('risk_rating', 16)->nullable();
            $t->string('status', 24)->default('ACTIVE');
            $t->foreignUuid('document_id')->nullable()->constrained('documents');
            $t->jsonb('attributes')->default('{}');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'contract_reference']);
        });
        Schema::create('governance_due_diligence_reviews', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('vendor_id')->constrained('governance_vendors');
            $t->foreignUuid('contract_id')->nullable()->constrained('governance_outsourcing_contracts');
            $t->date('review_date');
            $t->string('outcome', 24);
            $t->string('risk_rating', 16)->nullable();
            $t->date('next_review_on')->nullable();
            $t->text('notes')->nullable();
            $t->foreignUuid('reviewed_by')->constrained('users');
            $t->jsonb('attributes')->default('{}');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
        });
        Schema::create('governance_exit_plans', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('contract_id')->constrained('governance_outsourcing_contracts');
            $t->text('summary');
            $t->string('status', 24)->default('DRAFT');
            $t->date('last_tested_on')->nullable();
            $t->foreignUuid('prepared_by')->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->jsonb('attributes')->default('{}');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE governance_exit_plans ADD CONSTRAINT governance_exit_plan_checker CHECK (approved_by IS NULL OR approved_by <> prepared_by)');
        foreach (['governance_ict_assets' => 'criticality', 'governance_vendors' => 'risk_rating', 'governance_outsourcing_contracts' => 'risk_rating', 'governance_due_diligence_reviews' => 'risk_rating'] as $table => $col) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$col} CHECK ({$col} IS NULL OR {$col} IN ('LOW','MEDIUM','HIGH','CRITICAL'))");
        }
        DB::statement("ALTER TABLE governance_ict_incidents ADD CONSTRAINT governance_ict_incidents_severity CHECK (severity IN ('LOW','MEDIUM','HIGH','CRITICAL'))");

        // Migrate forward: legacy compliance_cases.findings jsonb → compliance_findings rows (no parallel state).
        foreach (DB::table('compliance_cases')->whereNotNull('tenant_id')->whereRaw("findings::text NOT IN ('[]','{}','null')")->get() as $case) {
            $items = json_decode((string) $case->findings, true) ?: [];
            $n = 0;
            foreach ($items as $key => $value) {
                $text = is_array($value) ? (string) ($value['title'] ?? json_encode($value)) : (string) $value;
                DB::table('compliance_findings')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $case->tenant_id, 'compliance_case_id' => $case->id,
                    'finding_number' => $case->case_number.'-F'.(++$n), 'title' => Str::limit(is_string($key) ? $key.': '.$text : $text, 250),
                    'severity' => in_array($case->severity, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true) ? $case->severity : 'MEDIUM',
                    'status' => $case->status === 'CLOSED' ? 'CLOSED' : 'OPEN', 'category' => 'MIGRATED_LEGACY',
                    'closed_at' => $case->status === 'CLOSED' ? $case->closed_at : null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['governance_exit_plans', 'governance_due_diligence_reviews', 'governance_outsourcing_contracts', 'governance_vendors', 'governance_ict_incidents', 'governance_ict_assets', 'compliance_evidence_links', 'compliance_corrective_actions', 'compliance_findings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
