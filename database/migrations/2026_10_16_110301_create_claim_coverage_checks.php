<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-CLM-003 (agent C3) — coverage-at-loss checks stored on the claim as immutable snapshots.
 * The evaluation columns never change after insert; only the one-time human resolution may be written
 * (a non-confirmed outcome is never an automatic rejection — it routes to review).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_coverage_checks', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('claim_id')->constrained('claims');
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->uuid('policy_version_id')->nullable();
            $t->uuid('reported_policy_version_id')->nullable();
            $t->string('coverage_code', 64)->nullable();
            $t->timestampTz('loss_occurred_at');
            $t->timestampTz('reported_at');
            $t->string('outcome', 32);
            $t->jsonb('reasons');
            $t->jsonb('snapshot');
            $t->char('snapshot_hash', 64);
            $t->string('engine_version', 16);
            $t->foreignUuid('checked_by')->nullable()->constrained('users');
            $t->timestampTz('checked_at');
            $t->string('resolution', 24)->nullable();
            $t->text('resolution_note')->nullable();
            $t->foreignUuid('resolved_by')->nullable()->constrained('users');
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampsTz();
            $t->index(['claim_id', 'checked_at']);
            $t->index(['tenant_id', 'outcome']);
        });
        DB::statement("ALTER TABLE claim_coverage_checks ADD CONSTRAINT claim_coverage_checks_outcome CHECK (outcome IN ('COVERAGE_CONFIRMED','REVIEW_REQUIRED','POTENTIAL_EXCLUSION','OUTSIDE_COVERAGE'))");
        DB::statement("ALTER TABLE claim_coverage_checks ADD CONSTRAINT claim_coverage_checks_resolution CHECK (resolution IS NULL OR resolution IN ('COVERED','NOT_COVERED','PARTIALLY_COVERED'))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION claim_coverage_check_freeze() RETURNS trigger AS $$
            BEGIN
              IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'claim_coverage_checks rows are immutable'; END IF;
              IF NEW.claim_id IS DISTINCT FROM OLD.claim_id OR NEW.policy_id IS DISTINCT FROM OLD.policy_id
                 OR NEW.policy_version_id IS DISTINCT FROM OLD.policy_version_id OR NEW.reported_policy_version_id IS DISTINCT FROM OLD.reported_policy_version_id
                 OR NEW.coverage_code IS DISTINCT FROM OLD.coverage_code OR NEW.loss_occurred_at IS DISTINCT FROM OLD.loss_occurred_at
                 OR NEW.reported_at IS DISTINCT FROM OLD.reported_at OR NEW.outcome IS DISTINCT FROM OLD.outcome
                 OR NEW.reasons::text IS DISTINCT FROM OLD.reasons::text OR NEW.snapshot::text IS DISTINCT FROM OLD.snapshot::text
                 OR NEW.snapshot_hash IS DISTINCT FROM OLD.snapshot_hash OR NEW.checked_at IS DISTINCT FROM OLD.checked_at
                 OR OLD.resolution IS NOT NULL THEN
                RAISE EXCEPTION 'claim_coverage_checks rows are immutable';
              END IF;
              RETURN NEW;
            END; $$ LANGUAGE plpgsql;
            CREATE TRIGGER claim_coverage_checks_freeze BEFORE UPDATE OR DELETE ON claim_coverage_checks FOR EACH ROW EXECUTE FUNCTION claim_coverage_check_freeze();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS claim_coverage_checks_freeze ON claim_coverage_checks; DROP FUNCTION IF EXISTS claim_coverage_check_freeze();');
        Schema::dropIfExists('claim_coverage_checks');
    }
};
