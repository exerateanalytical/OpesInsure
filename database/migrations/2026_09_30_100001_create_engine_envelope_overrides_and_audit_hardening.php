<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-ENG-001 engine_evaluations (append-only EngineResult store, ICE §0.2/§0.4)
 * REQ-OVR-001 engine_overrides (single controlled-override table, maker-checker CHECK)
 * REQ-AUD-001 audit_log append-only trigger (UPDATE/DELETE rejected) + hash_version
 * REQ-AUD-002 audit_log structured fields: branch, old/new, source, IP/device, approval
 * REQ-DUP-016 audit_log kept physically; blueprint name exposed as the audit_events VIEW (no second table)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $t) {
            $t->uuid('branch_id')->nullable();
            $t->jsonb('old_values')->nullable();
            $t->jsonb('new_values')->nullable();
            $t->string('source', 32)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('device_id', 128)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->uuid('approval_id')->nullable();
            $t->smallInteger('hash_version')->default(1);
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::create('engine_evaluations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->string('engine', 24);
            $t->string('operation', 64);
            $t->string('subject_type', 64);
            $t->uuid('subject_id')->nullable();
            $t->string('outcome', 32);
            $t->boolean('blocking')->default(false);
            $t->timestampTz('reference_at');
            $t->timestampTz('recorded_as_of');
            $t->char('inputs_hash', 64);
            $t->jsonb('resolved_versions')->default('{}');
            $t->jsonb('trace')->default('[]');
            $t->jsonb('reasons')->default('[]');
            $t->jsonb('warnings')->default('[]');
            $t->string('correlation_id', 64)->nullable();
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->timestampTz('created_at');
        });

        Schema::create('engine_overrides', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->foreignUuid('engine_evaluation_id')->nullable()->constrained('engine_evaluations');
            $t->string('override_type', 48);
            $t->string('subject_type', 64);
            $t->uuid('subject_id')->nullable();
            $t->string('field', 64)->nullable();
            $t->string('previous_outcome', 32)->nullable();
            $t->string('new_outcome', 32)->nullable();
            $t->jsonb('previous_value')->nullable();
            $t->jsonb('new_value')->nullable();
            $t->string('reason_code', 64);
            $t->text('justification');
            $t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->foreignUuid('rejected_by')->nullable()->constrained('users');
            $t->timestampTz('rejected_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->uuid('authority_grant_id')->nullable();
            $t->string('status', 24)->default('REQUESTED');
            $t->string('correlation_id', 64)->nullable();
            $t->timestampTz('created_at');
            $t->index(['subject_type', 'subject_id', 'status']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX engine_evaluations_subject_idx ON engine_evaluations (subject_type, subject_id, engine, created_at DESC)');
        DB::statement("ALTER TABLE engine_evaluations ADD CONSTRAINT engine_evaluations_inputs_hash_hex CHECK (inputs_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE engine_overrides ADD CONSTRAINT engine_overrides_status_chk CHECK (status IN ('REQUESTED','APPROVED','AUTO_APPROVED','REJECTED'))");
        // Maker-checker: a human approver can never be the requester.
        DB::statement('ALTER TABLE engine_overrides ADD CONSTRAINT engine_overrides_maker_checker CHECK (approved_by IS NULL OR approved_by <> requested_by)');
        DB::statement("ALTER TABLE engine_overrides ADD CONSTRAINT engine_overrides_approval_shape CHECK (
            (status = 'REQUESTED' AND approved_by IS NULL AND rejected_by IS NULL)
            OR (status = 'APPROVED' AND approved_by IS NOT NULL AND approved_at IS NOT NULL)
            OR (status = 'AUTO_APPROVED' AND approved_at IS NOT NULL AND (authority_grant_id IS NOT NULL OR approved_by IS NULL))
            OR (status = 'REJECTED' AND rejected_by IS NOT NULL AND rejected_at IS NOT NULL AND rejected_by <> requested_by))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_append_only_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Table % is append-only: % is not permitted', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION guard_engine_override_update() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'REQUESTED' THEN
                    RAISE EXCEPTION 'engine_overrides % is already decided (%)', OLD.id, OLD.status USING ERRCODE = 'insufficient_privilege';
                END IF;
                IF (NEW.id, NEW.tenant_id, NEW.engine_evaluation_id, NEW.override_type, NEW.subject_type, NEW.subject_id, NEW.field,
                    NEW.previous_outcome, NEW.new_outcome, NEW.previous_value::text, NEW.new_value::text, NEW.reason_code, NEW.justification,
                    NEW.requested_by, NEW.authority_grant_id, NEW.correlation_id, NEW.created_at)
                   IS DISTINCT FROM
                   (OLD.id, OLD.tenant_id, OLD.engine_evaluation_id, OLD.override_type, OLD.subject_type, OLD.subject_id, OLD.field,
                    OLD.previous_outcome, OLD.new_outcome, OLD.previous_value::text, OLD.new_value::text, OLD.reason_code, OLD.justification,
                    OLD.requested_by, OLD.authority_grant_id, OLD.correlation_id, OLD.created_at) THEN
                    RAISE EXCEPTION 'engine_overrides: only the decision columns may change' USING ERRCODE = 'insufficient_privilege';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_log_append_only BEFORE UPDATE OR DELETE ON audit_log FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
            CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON audit_log FOR EACH STATEMENT EXECUTE FUNCTION reject_append_only_mutation();
            CREATE TRIGGER engine_evaluations_append_only BEFORE UPDATE OR DELETE ON engine_evaluations FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
            CREATE TRIGGER engine_evaluations_no_truncate BEFORE TRUNCATE ON engine_evaluations FOR EACH STATEMENT EXECUTE FUNCTION reject_append_only_mutation();
            CREATE TRIGGER engine_overrides_no_delete BEFORE DELETE ON engine_overrides FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
            CREATE TRIGGER engine_overrides_decision_only BEFORE UPDATE ON engine_overrides FOR EACH ROW EXECUTE FUNCTION guard_engine_override_update();

            CREATE VIEW audit_events AS
                SELECT sequence, id, tenant_id, branch_id, actor_id, action, subject_type, subject_id, reason_code,
                       old_values, new_values, metadata, source, ip_address, device_id, user_agent, approval_id,
                       correlation_id, previous_hash, entry_hash, hash_version, created_at
                FROM audit_log;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP VIEW IF EXISTS audit_events;
                DROP TRIGGER IF EXISTS audit_log_append_only ON audit_log;
                DROP TRIGGER IF EXISTS audit_log_no_truncate ON audit_log;
                SQL);
        }
        Schema::dropIfExists('engine_overrides');
        Schema::dropIfExists('engine_evaluations');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS guard_engine_override_update(); DROP FUNCTION IF EXISTS reject_append_only_mutation();');
        }
        Schema::table('audit_log', function (Blueprint $t) {
            $t->dropIndex(['subject_type', 'subject_id']);
            $t->dropColumn(['branch_id', 'old_values', 'new_values', 'source', 'ip_address', 'device_id', 'user_agent', 'approval_id', 'hash_version']);
        });
    }
};
