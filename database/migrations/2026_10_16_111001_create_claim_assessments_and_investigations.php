<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-CLM-010 (WF-054/055) — claim assessment (a recommendation, never a decision) and claim investigation.
 * Assessment content and attached fraud indicators are immutable at database level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_assessments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('claim_id')->constrained('claims');
            $t->string('assessment_number', 40)->unique();
            $t->string('status', 16)->default('SUBMITTED'); // SUBMITTED | ACCEPTED | REJECTED | SUPERSEDED
            $t->jsonb('heads'); // [{head_code, claimed_minor?, recommended_minor, note?}]
            $t->bigInteger('recommended_total_minor');
            $t->string('currency', 3);
            $t->text('rationale');
            $t->foreignUuid('assessor_user_id')->constrained('users');
            $t->foreignUuid('adjuster_report_document_id')->nullable()->constrained('documents');
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestampsTz();
            $t->index(['claim_id', 'status']);
        });

        Schema::create('claim_investigations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('claim_id')->constrained('claims');
            $t->foreignUuid('case_id')->nullable()->constrained('cases');
            $t->string('status', 16)->default('OPEN'); // OPEN | CONCLUDED
            $t->string('reason_code', 64);
            $t->text('reason');
            $t->text('findings')->nullable();
            $t->string('outcome', 32)->nullable();
            $t->text('outcome_summary')->nullable();
            $t->foreignUuid('opened_by')->constrained('users');
            $t->foreignUuid('concluded_by')->nullable()->constrained('users');
            $t->timestampTz('concluded_at')->nullable();
            $t->timestampsTz();
            $t->index(['claim_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX claim_investigations_one_open ON claim_investigations (claim_id) WHERE status = 'OPEN'");

        Schema::create('claim_investigation_indicators', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('investigation_id')->constrained('claim_investigations')->cascadeOnDelete();
            $t->string('indicator_id', 64); // reference to the fraud-indicator engine (opaque)
            $t->string('indicator_code', 64)->nullable();
            $t->jsonb('snapshot');
            $t->foreignUuid('attached_by')->constrained('users');
            $t->timestampTz('attached_at');
            $t->unique(['investigation_id', 'indicator_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION claim_assessment_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'CLAIM_ASSESSMENT_IMMUTABLE: delete is not allowed';
                END IF;
                IF NEW.claim_id IS DISTINCT FROM OLD.claim_id OR NEW.heads IS DISTINCT FROM OLD.heads
                   OR NEW.recommended_total_minor IS DISTINCT FROM OLD.recommended_total_minor OR NEW.currency IS DISTINCT FROM OLD.currency
                   OR NEW.rationale IS DISTINCT FROM OLD.rationale OR NEW.assessor_user_id IS DISTINCT FROM OLD.assessor_user_id
                   OR NEW.adjuster_report_document_id IS DISTINCT FROM OLD.adjuster_report_document_id THEN
                    RAISE EXCEPTION 'CLAIM_ASSESSMENT_IMMUTABLE: assessment content cannot change';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER claim_assessments_immutable BEFORE UPDATE OR DELETE ON claim_assessments
                FOR EACH ROW EXECUTE FUNCTION claim_assessment_immutable();

            CREATE OR REPLACE FUNCTION claim_investigation_indicator_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'CLAIM_INVESTIGATION_INDICATOR_IMMUTABLE: % is not allowed', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER claim_investigation_indicators_append_only BEFORE UPDATE OR DELETE ON claim_investigation_indicators
                FOR EACH ROW EXECUTE FUNCTION claim_investigation_indicator_append_only();

            CREATE OR REPLACE FUNCTION claim_investigation_concluded_immutable() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'CONCLUDED' THEN
                    RAISE EXCEPTION 'CLAIM_INVESTIGATION_CONCLUDED: % is not allowed', TG_OP;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER claim_investigations_concluded_immutable BEFORE UPDATE OR DELETE ON claim_investigations
                FOR EACH ROW EXECUTE FUNCTION claim_investigation_concluded_immutable();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_investigation_indicators');
        Schema::dropIfExists('claim_investigations');
        Schema::dropIfExists('claim_assessments');
        DB::unprepared('DROP FUNCTION IF EXISTS claim_assessment_immutable(); DROP FUNCTION IF EXISTS claim_investigation_indicator_append_only(); DROP FUNCTION IF EXISTS claim_investigation_concluded_immutable();');
    }
};
