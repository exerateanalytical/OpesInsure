<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-RBAC-005 / REQ-RBAC-006 / REQ-SET-005 — one approval engine (WF-081 "generic approval_requests").
 *
 * approval_matrix_rules   central matrix (workflow, action, amount band, role, branch, product, insurer) — SCF §58, ESR ADM-029
 * approval_requests       every maker-checker request, whatever the domain (source_table/source_id link the domain row)
 * approval_decisions      one row per checker decision (multi-level approvals), append-only
 * sod_conflict_rules      segregation of duties: actor of action A on a subject may not perform action B on it
 * configuration_change_sets  REQ-SET-005 Draft→Review→Approved→Published, effective-dated, approved via approval_requests
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_matrix_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();   // NULL = platform default
            $t->string('action_code', 64);
            $t->string('workflow', 48);
            $t->string('category', 24);
            $t->string('description', 255)->nullable();
            $t->string('source_refs', 255)->nullable();
            $t->decimal('min_amount', 18, 2)->nullable();
            $t->decimal('max_amount', 18, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->uuid('product_id')->nullable();
            $t->uuid('insurer_tenant_id')->nullable();
            $t->string('branch_code', 64)->nullable();
            $t->string('checker_permission', 128)->nullable();
            $t->jsonb('checker_roles')->nullable();
            $t->unsignedSmallInteger('required_approvals')->default(1);
            $t->boolean('requires_maker_checker')->default(true);
            $t->boolean('exclude_subject_parties')->default(true);
            $t->smallInteger('priority')->default(100);
            $t->string('status', 16)->default('ACTIVE');
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->timestampsTz();
            $t->index(['action_code', 'status']);
        });

        Schema::create('approval_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->string('action_code', 64);
            $t->foreignUuid('matrix_rule_id')->nullable()->constrained('approval_matrix_rules');
            $t->string('subject_type', 64);
            $t->uuid('subject_id')->nullable();
            $t->string('source_table', 64)->nullable();
            $t->uuid('source_id')->nullable();
            $t->decimal('amount', 18, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->jsonb('context')->nullable();
            $t->jsonb('payload')->nullable();
            $t->text('reason')->nullable();
            $t->string('status', 16)->default('PENDING');
            $t->unsignedSmallInteger('required_approvals')->default(1);
            $t->unsignedSmallInteger('approvals_count')->default(0);
            $t->foreignUuid('requested_by')->constrained('users');
            $t->jsonb('excluded_user_ids')->nullable();
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->string('correlation_id', 64)->nullable();
            $t->timestampsTz();
            $t->index(['status', 'action_code']);
            $t->index(['subject_type', 'subject_id']);
            $t->unique(['source_table', 'source_id']);
        });

        Schema::create('approval_decisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('approval_request_id')->constrained('approval_requests');
            $t->foreignUuid('decided_by')->constrained('users');
            $t->string('decision', 16);
            $t->string('note', 500)->nullable();
            $t->timestampTz('created_at');
            $t->unique(['approval_request_id', 'decided_by']);
        });

        Schema::create('sod_conflict_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('first_action', 64);
            $t->string('second_action', 64);
            $t->string('scope', 16)->default('SUBJECT');   // SUBJECT: same subject only
            $t->string('description', 255)->nullable();
            $t->string('source_refs', 255)->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampsTz();
            $t->unique(['first_action', 'second_action']);
        });

        Schema::create('configuration_change_sets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->string('config_type', 64);
            $t->string('config_key', 128);
            $t->jsonb('previous_value')->nullable();
            $t->jsonb('proposed_value');
            $t->text('reason');
            $t->string('status', 16)->default('DRAFT');
            $t->date('effective_from')->nullable();
            $t->uuid('approval_request_id')->nullable();
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('published_by')->nullable()->constrained('users');
            $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->timestampsTz();
            $t->index(['config_type', 'config_key', 'status']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE approval_matrix_rules ADD CONSTRAINT approval_matrix_category_chk CHECK (category IN ('FINANCIAL','CONFIGURATION','ACCESS','DOCUMENT','UNDERWRITING','CLAIM','POLICY','OVERRIDE','DATA'))");
        DB::statement('ALTER TABLE approval_matrix_rules ADD CONSTRAINT approval_matrix_amount_chk CHECK (min_amount IS NULL OR max_amount IS NULL OR min_amount <= max_amount)');
        DB::statement('ALTER TABLE approval_matrix_rules ADD CONSTRAINT approval_matrix_levels_chk CHECK (required_approvals BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_chk CHECK (status IN ('PENDING','APPROVED','REJECTED','CANCELLED','AUTO_APPROVED'))");
        // Maker-checker at the database: the final decider is never the maker.
        DB::statement('ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_maker_checker CHECK (decided_by IS NULL OR decided_by <> requested_by)');
        DB::statement("ALTER TABLE approval_decisions ADD CONSTRAINT approval_decisions_decision_chk CHECK (decision IN ('APPROVED','REJECTED'))");
        DB::statement("ALTER TABLE configuration_change_sets ADD CONSTRAINT configuration_change_sets_status_chk CHECK (status IN ('DRAFT','IN_REVIEW','APPROVED','PUBLISHED','REJECTED','WITHDRAWN'))");
        DB::statement('ALTER TABLE configuration_change_sets ADD CONSTRAINT configuration_change_sets_publisher_chk CHECK (published_by IS NULL OR published_by <> created_by)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION approval_decisions_guard() RETURNS trigger AS $$
            DECLARE maker uuid;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'approval_decisions is append-only: % is not permitted', TG_OP;
                END IF;
                SELECT requested_by INTO maker FROM approval_requests WHERE id = NEW.approval_request_id;
                IF maker = NEW.decided_by THEN
                    RAISE EXCEPTION 'Maker-checker: the requester cannot decide their own approval request';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER approval_decisions_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON approval_decisions
                FOR EACH ROW EXECUTE FUNCTION approval_decisions_guard();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS approval_decisions_guard_trg ON approval_decisions; DROP FUNCTION IF EXISTS approval_decisions_guard();');
        }
        Schema::dropIfExists('configuration_change_sets');
        Schema::dropIfExists('sod_conflict_rules');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_matrix_rules');
    }
};
