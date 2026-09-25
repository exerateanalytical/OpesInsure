<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-CAS-001 / REQ-CAL-001 / REQ-DUP-022 — ICE Engine 6 core (additive).
 *
 * Canonical work model: cases + case_tasks + sla_clocks (the blueprint's
 * "sla_definitions" are case_types.sla_policies, ICE §0 mapping). Existing
 * fragments (underwriting_cases, underwriting_referral_tasks, compliance_cases,
 * claim_disputes, support_tickets, renewal_work_items) are NOT dropped: they
 * get nullable link columns and are projected into "My Work" (strangler).
 *
 * Business hours / calendar exceptions extend the Batch 1 business_calendars
 * table (which stays the holiday seed input); nothing here seeds hours or
 * holidays — both are owner facts (OQ-6.2, UNVERIFIED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_types', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 48);
            $t->unsignedInteger('version')->default(1);
            // OQ-6.4: blueprint family (one of the owner's 8 case types). Unknown until the owner maps it.
            $t->string('family_code', 48)->nullable();
            $t->string('name', 160);
            $t->string('status', 16)->default('DRAFT');
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->jsonb('states');
            $t->jsonb('transitions');
            $t->jsonb('sla_policies')->default('[]');
            $t->jsonb('auto_tasks')->default('[]');
            $t->string('default_confidentiality', 16)->default('NORMAL');
            $t->boolean('regulated')->default(false);
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->unique(['code', 'version']);
        });
        DB::statement("ALTER TABLE case_types ADD CONSTRAINT case_types_status_allowed CHECK (status IN ('DRAFT','APPROVED','EFFECTIVE','SUPERSEDED'))");

        Schema::create('queues', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->uuid('carrier_id')->nullable();
            $t->string('code', 64);
            $t->string('name', 160);
            $t->jsonb('case_type_codes')->default('[]');
            $t->foreignUuid('branch_id')->nullable()->constrained('tenant_branches');
            $t->string('routing_rule', 24)->default('PULL');
            $t->boolean('is_default')->default(false);
            $t->boolean('active')->default(true);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE queues ADD CONSTRAINT queues_routing_rule_allowed CHECK (routing_rule IN ('PULL','LEAST_LOADED','ROUND_ROBIN'))");

        Schema::create('queue_members', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('queue_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained();
            $t->jsonb('skills')->default('[]');
            $t->unsignedInteger('capacity')->default(20);
            $t->boolean('active')->default(true);
            $t->timestampTz('last_assigned_at')->nullable();
            $t->timestampsTz();
            $t->unique(['queue_id', 'user_id']);
        });

        Schema::create('cases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('carrier_id')->nullable();
            $t->foreignUuid('case_type_id')->constrained('case_types');
            $t->string('case_type_code', 48);
            $t->string('case_number', 40)->unique();
            $t->string('title', 255);
            $t->string('status', 48);
            $t->string('priority', 16)->default('NORMAL');
            $t->string('confidentiality', 16)->default('NORMAL');
            $t->string('subject_type', 64)->nullable();
            $t->uuid('subject_id')->nullable();
            $t->uuid('parent_case_id')->nullable();
            $t->foreignUuid('owner_user_id')->nullable()->constrained('users');
            $t->foreignUuid('queue_id')->nullable()->constrained('queues');
            $t->foreignUuid('branch_id')->nullable()->constrained('tenant_branches');
            $t->string('jurisdiction', 8)->default('CM');
            $t->string('source_type', 64)->nullable();
            $t->uuid('source_id')->nullable();
            $t->timestampTz('opened_at');
            $t->foreignUuid('opened_by')->nullable()->constrained('users');
            $t->timestampTz('first_responded_at')->nullable();
            $t->timestampTz('due_at')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->string('outcome', 32)->nullable();
            $t->boolean('legal_hold')->default(false);
            $t->string('idempotency_key', 100)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->unique(['source_type', 'source_id']);
            $t->index(['tenant_id', 'status']);
            $t->index(['tenant_id', 'owner_user_id']);
            $t->index(['queue_id', 'owner_user_id']);
            $t->index(['subject_type', 'subject_id']);
        });
        DB::statement("ALTER TABLE cases ADD CONSTRAINT cases_confidentiality_allowed CHECK (confidentiality IN ('NORMAL','RESTRICTED','STR_RESTRICTED'))");
        DB::statement("ALTER TABLE cases ADD CONSTRAINT cases_priority_allowed CHECK (priority IN ('LOW','NORMAL','HIGH','URGENT'))");

        Schema::create('case_participants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('case_id')->constrained('cases')->cascadeOnDelete();
            $t->string('party_type', 16);
            $t->string('party_ref', 128);
            $t->string('role', 16);
            $t->timestampTz('added_at');
            $t->timestampTz('removed_at')->nullable();
        });

        Schema::create('case_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('case_id')->constrained('cases')->cascadeOnDelete();
            $t->unsignedInteger('seq');
            $t->string('type', 48);
            $t->string('from_status', 48)->nullable();
            $t->string('to_status', 48)->nullable();
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->jsonb('payload')->default('{}');
            $t->string('correlation_id', 64)->nullable();
            $t->timestampTz('occurred_at');
            $t->unique(['case_id', 'seq']);
        });

        Schema::create('case_tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('case_id')->constrained('cases')->cascadeOnDelete();
            $t->string('template_code', 64)->nullable();
            $t->string('title', 255);
            $t->string('status', 16)->default('OPEN');
            $t->foreignUuid('assignee_user_id')->nullable()->constrained('users');
            $t->foreignUuid('queue_id')->nullable()->constrained('queues');
            $t->timestampTz('due_at')->nullable();
            $t->timestampTz('overdue_notified_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->foreignUuid('completed_by')->nullable()->constrained('users');
            $t->jsonb('result')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->index(['assignee_user_id', 'status']);
        });
        DB::statement("ALTER TABLE case_tasks ADD CONSTRAINT case_tasks_status_allowed CHECK (status IN ('OPEN','IN_PROGRESS','BLOCKED','DONE','CANCELLED'))");

        Schema::create('case_decisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('case_id')->constrained('cases')->cascadeOnDelete();
            $t->string('decision_type', 48);
            $t->string('outcome', 48);
            $t->text('rationale');
            $t->jsonb('conditions')->default('[]');
            $t->foreignUuid('decided_by')->constrained('users');
            $t->uuid('authority_grant_id')->nullable();
            $t->uuid('reverses_decision_id')->nullable();
            $t->timestampTz('decided_at');
        });

        Schema::create('diary_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('case_id')->nullable()->constrained('cases')->cascadeOnDelete();
            $t->string('subject_type', 64);
            $t->uuid('subject_id');
            $t->foreignUuid('author_id')->constrained('users');
            $t->string('entry_type', 16);
            $t->text('body');
            $t->timestampTz('follow_up_at')->nullable();
            $t->timestampTz('follow_up_notified_at')->nullable();
            $t->string('visibility', 16)->default('INTERNAL');
            $t->uuid('supersedes_entry_id')->nullable();
            $t->timestampTz('created_at');
            $t->index(['author_id', 'follow_up_at']);
        });
        DB::statement("ALTER TABLE diary_entries ADD CONSTRAINT diary_entries_type_allowed CHECK (entry_type IN ('NOTE','CALL','MEETING','FOLLOW_UP'))");

        Schema::create('sla_clocks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('case_id')->constrained('cases')->cascadeOnDelete();
            $t->string('metric', 64);
            $t->unsignedInteger('target_business_minutes');
            $t->unsignedTinyInteger('warn_at_pct')->default(80);
            $t->string('escalate_to', 64)->nullable();
            $t->timestampTz('started_at');
            $t->unsignedInteger('paused_total_business_minutes')->default(0);
            $t->timestampTz('paused_since')->nullable();
            $t->timestampTz('due_at');
            $t->timestampTz('warn_at')->nullable();
            $t->timestampTz('warned_at')->nullable();
            $t->timestampTz('breached_at')->nullable();
            $t->timestampTz('stopped_at')->nullable();
            $t->timestampsTz();
            $t->unique(['case_id', 'metric']);
            $t->index(['stopped_at', 'due_at']);
        });

        // REQ-CAL-001: branch/jurisdiction hours and dated exceptions. business_calendars stays the holiday seed.
        Schema::create('calendar_business_hours', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('jurisdiction', 8);
            $t->foreignUuid('branch_id')->nullable()->constrained('tenant_branches');
            $t->unsignedTinyInteger('weekday'); // ISO 1 = Monday … 7 = Sunday
            $t->time('opens');
            $t->time('closes');
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->index(['jurisdiction', 'branch_id', 'weekday']);
        });
        DB::statement('ALTER TABLE calendar_business_hours ADD CONSTRAINT calendar_business_hours_window CHECK (weekday BETWEEN 1 AND 7 AND closes > opens)');

        Schema::create('calendar_exceptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('jurisdiction', 8);
            $t->foreignUuid('branch_id')->nullable()->constrained('tenant_branches');
            $t->date('date');
            $t->string('kind', 16);
            $t->string('label', 160);
            $t->string('source_reference', 255)->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->index(['jurisdiction', 'date']);
        });
        DB::statement("ALTER TABLE calendar_exceptions ADD CONSTRAINT calendar_exceptions_kind_allowed CHECK (kind IN ('HOLIDAY','CLOSURE','EXTRA_DAY'))");

        // Self-references need the primary key to exist first.
        Schema::table('cases', fn (Blueprint $t) => $t->foreign('parent_case_id')->references('id')->on('cases'));
        Schema::table('case_decisions', fn (Blueprint $t) => $t->foreign('reverses_decision_id')->references('id')->on('case_decisions'));
        Schema::table('diary_entries', fn (Blueprint $t) => $t->foreign('supersedes_entry_id')->references('id')->on('diary_entries'));

        // Append-only guards (INV-6.4).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION opes_case_append_only() RETURNS trigger AS $$
BEGIN RAISE EXCEPTION '% is append-only', TG_TABLE_NAME; END; $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS case_events_append_only ON case_events;
CREATE TRIGGER case_events_append_only BEFORE UPDATE OR DELETE ON case_events FOR EACH ROW WHEN (pg_trigger_depth() = 0) EXECUTE FUNCTION opes_case_append_only();
DROP TRIGGER IF EXISTS case_decisions_append_only ON case_decisions;
CREATE TRIGGER case_decisions_append_only BEFORE UPDATE OR DELETE ON case_decisions FOR EACH ROW WHEN (pg_trigger_depth() = 0) EXECUTE FUNCTION opes_case_append_only();
SQL);
        // diary: body is immutable; only the follow-up notification marker may be set.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION opes_diary_append_only() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'diary_entries is append-only'; END IF;
  IF NEW.body IS DISTINCT FROM OLD.body OR NEW.entry_type IS DISTINCT FROM OLD.entry_type OR NEW.author_id IS DISTINCT FROM OLD.author_id
     OR NEW.follow_up_at IS DISTINCT FROM OLD.follow_up_at OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
    RAISE EXCEPTION 'diary_entries is append-only';
  END IF;
  RETURN NEW;
END; $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS diary_entries_append_only ON diary_entries;
CREATE TRIGGER diary_entries_append_only BEFORE UPDATE OR DELETE ON diary_entries FOR EACH ROW WHEN (pg_trigger_depth() = 0) EXECUTE FUNCTION opes_diary_append_only();
SQL);

        // REQ-DUP-022 links (strangler): legacy work items point at the canonical case/task.
        foreach (['underwriting_cases', 'compliance_cases', 'claim_disputes', 'support_tickets', 'renewal_work_items'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'case_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->foreignUuid('case_id')->nullable()->constrained('cases')->nullOnDelete());
            }
        }
        if (Schema::hasTable('underwriting_referral_tasks') && ! Schema::hasColumn('underwriting_referral_tasks', 'case_task_id')) {
            Schema::table('underwriting_referral_tasks', fn (Blueprint $t) => $t->foreignUuid('case_task_id')->nullable()->constrained('case_tasks')->nullOnDelete());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('underwriting_referral_tasks', 'case_task_id')) {
            Schema::table('underwriting_referral_tasks', fn (Blueprint $t) => $t->dropConstrainedForeignId('case_task_id'));
        }
        foreach (['underwriting_cases', 'compliance_cases', 'claim_disputes', 'support_tickets', 'renewal_work_items'] as $table) {
            if (Schema::hasColumn($table, 'case_id')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('case_id'));
            }
        }
        foreach (['calendar_exceptions', 'calendar_business_hours', 'sla_clocks', 'diary_entries', 'case_decisions', 'case_tasks', 'case_events', 'case_participants', 'cases', 'queue_members', 'queues', 'case_types'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS opes_case_append_only() CASCADE; DROP FUNCTION IF EXISTS opes_diary_append_only() CASCADE;');
    }
};
