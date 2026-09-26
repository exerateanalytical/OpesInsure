<?php

declare(strict_types=1);

use App\Application\OperationsTaxonomy\OperationsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Gap Closure Pack v1 files 10 + 11 (database/data/gap_closure_2026). Additive only: extends the canonical tables
 | (case engine, complaints, document status changes, retention_schedules, sla_policy_overrides,
 | document_numbering_families, notification_templates) with the pack's codes/fields, adds the one missing registry
 | (signatory_authorities, PENDING_PRIVATE_SOURCE — empty) and the private-onboarding staging table fed by the
 | generic ImportPipeline. Seeding (DRAFT notification templates only) is idempotent; case families stay the owner's 10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_tasks', fn (Blueprint $t) => $t->string('task_type', 32)->nullable());
        Schema::table('queues', fn (Blueprint $t) => $t->string('queue_type', 32)->nullable());
        Schema::table('cases', function (Blueprint $t): void {
            $t->string('closure_reason', 32)->nullable();
            $t->string('escalation_reason', 32)->nullable();
            $t->timestampTz('escalated_at')->nullable();
        });
        Schema::table('complaints', fn (Blueprint $t) => $t->string('resolution_reason', 40)->nullable());
        Schema::table('document_status_changes', fn (Blueprint $t) => $t->string('reason_code', 40)->nullable());

        Schema::table('retention_schedules', function (Blueprint $t): void {
            $t->string('retention_class', 32)->nullable();
            $t->boolean('legal_hold_override')->default(true);
            $t->string('destruction_method', 40)->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->string('source', 64)->nullable();
            $t->index('retention_class');
        });

        Schema::table('sla_policy_overrides', function (Blueprint $t): void {
            $t->string('sla_profile_code', 64)->nullable();
            $t->string('priority', 16)->nullable();
            $t->uuid('calendar_id')->nullable();
            $t->jsonb('pause_states')->default('[]');
            $t->jsonb('escalation_thresholds')->default('[]');
            $t->string('source', 64)->nullable();
        });

        Schema::table('document_numbering_families', function (Blueprint $t): void {
            $t->string('sequence_scope', 24)->nullable();
            $t->boolean('branch_component')->default(false);
            $t->boolean('product_component')->default(false);
            $t->string('check_digit', 16)->nullable();
            $t->string('reset_rule', 16)->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->string('approval_status', 24)->nullable();
        });

        Schema::table('notification_templates', fn (Blueprint $t) => $t->string('event_code', 48)->nullable()->index());

        Schema::create('signatory_authorities', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('authority_id', 64);
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->string('organization_type', 16);
            $t->uuid('organization_id');
            $t->string('person_id', 64);
            $t->string('role', 80);
            $t->jsonb('document_types')->default('[]');
            $t->jsonb('financial_limits')->nullable();
            $t->jsonb('product_scope')->default('[]');
            $t->jsonb('branch_scope')->default('[]');
            $t->string('signature_method', 32)->nullable();
            $t->string('certificate_id', 128)->nullable();
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('status', 32)->default('PENDING_VERIFICATION');
            $t->string('source_mandate_document_id', 128);
            $t->string('source', 40)->default('GAP_CLOSURE_PACK_2026');
            $t->uuid('onboarding_record_id')->nullable();
            $t->timestampsTz();
            $t->unique(['organization_type', 'organization_id', 'authority_id']);
        });
        DB::statement("ALTER TABLE signatory_authorities ADD CONSTRAINT signatory_mandate_evidence CHECK (length(trim(source_mandate_document_id)) > 0)");

        Schema::create('tenant_onboarding_records', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->string('dataset', 40);
            $t->string('organization_type', 16);
            $t->uuid('organization_id')->nullable();
            $t->string('record_key', 128);
            $t->jsonb('payload');
            $t->string('source_document_id', 128)->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->string('data_status', 32)->default('PENDING_PRIVATE_SOURCE');
            $t->string('review_status', 16)->default('RECEIVED');
            $t->uuid('import_batch_id')->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->string('promoted_table', 64)->nullable();
            $t->uuid('promoted_id')->nullable();
            $t->string('source', 40)->default('GAP_CLOSURE_PACK_2026');
            $t->timestampsTz();
            $t->index(['dataset', 'organization_type', 'organization_id']);
        });
        DB::statement("CREATE UNIQUE INDEX tenant_onboarding_records_key ON tenant_onboarding_records (dataset, organization_type, COALESCE(organization_id, '00000000-0000-0000-0000-000000000000'::uuid), record_key) WHERE review_status <> 'REJECTED'");
        DB::statement("ALTER TABLE tenant_onboarding_records ADD CONSTRAINT tor_review_status CHECK (review_status IN ('RECEIVED','ACCEPTED','REJECTED'))");
        DB::statement("ALTER TABLE tenant_onboarding_records ADD CONSTRAINT tor_org_type CHECK (organization_type IN ('INSURER','BROKER','TENANT'))");
        DB::statement('ALTER TABLE tenant_onboarding_records ADD CONSTRAINT tor_checker CHECK (reviewed_by IS NULL OR created_by IS NULL OR reviewed_by <> created_by)');

        app(OperationsSeeder::class)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_onboarding_records');
        Schema::dropIfExists('signatory_authorities');
        Schema::table('notification_templates', fn (Blueprint $t) => $t->dropColumn('event_code'));
        Schema::table('document_numbering_families', fn (Blueprint $t) => $t->dropColumn(['sequence_scope', 'branch_component', 'product_component', 'check_digit', 'reset_rule', 'effective_from', 'effective_until', 'approval_status']));
        Schema::table('sla_policy_overrides', fn (Blueprint $t) => $t->dropColumn(['sla_profile_code', 'priority', 'calendar_id', 'pause_states', 'escalation_thresholds', 'source']));
        Schema::table('retention_schedules', fn (Blueprint $t) => $t->dropColumn(['retention_class', 'legal_hold_override', 'destruction_method', 'effective_from', 'effective_until', 'source']));
        Schema::table('document_status_changes', fn (Blueprint $t) => $t->dropColumn('reason_code'));
        Schema::table('complaints', fn (Blueprint $t) => $t->dropColumn('resolution_reason'));
        Schema::table('cases', fn (Blueprint $t) => $t->dropColumn(['closure_reason', 'escalation_reason', 'escalated_at']));
        Schema::table('queues', fn (Blueprint $t) => $t->dropColumn('queue_type'));
        Schema::table('case_tasks', fn (Blueprint $t) => $t->dropColumn('task_type'));
    }
};
