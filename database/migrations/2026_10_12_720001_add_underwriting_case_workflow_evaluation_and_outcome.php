<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 7B — REQ-UW-001…004. Additive only; extends the existing underwriting tables (no new case/decision table).
 *
 *  underwriting_cases      + DECISION_PENDING stored status (canonical referred→assigned→reviewing→decision_pending→outcome),
 *                          + outcome APPROVED | CONDITIONAL | COUNTEROFFERED | DECLINED (set by the human decision),
 *                          + latest system evaluation summary (recommendation, explainable risk score, rule set versions);
 *                            the full append-only evaluation lives in engine_evaluations (engine RULES, underwriting.evaluate).
 *  underwriting_decisions  + the evaluation the human saw and its recommendation (override visibility), + outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('underwriting_cases', function (Blueprint $t): void {
            $t->string('outcome', 16)->nullable();
            $t->string('recommendation', 24)->nullable();
            $t->unsignedSmallInteger('risk_score')->nullable();
            $t->string('risk_band', 8)->nullable();
            $t->jsonb('risk_factors')->default('[]');
            $t->jsonb('rule_set_versions')->default('{}');
            $t->foreignUuid('engine_evaluation_id')->nullable()->constrained('engine_evaluations');
            $t->timestampTz('evaluated_at')->nullable();
            $t->timestampTz('review_started_at')->nullable();
            $t->timestampTz('decision_pending_at')->nullable();
        });
        Schema::table('underwriting_decisions', function (Blueprint $t): void {
            $t->string('outcome', 16)->nullable();
            $t->string('system_recommendation', 24)->nullable();
            $t->foreignUuid('engine_evaluation_id')->nullable()->constrained('engine_evaluations');
        });

        DB::statement('ALTER TABLE underwriting_cases DROP CONSTRAINT IF EXISTS underwriting_case_status_allowed');
        DB::statement("ALTER TABLE underwriting_cases ADD CONSTRAINT underwriting_case_status_allowed CHECK (status IN ('QUEUED','IN_REVIEW','AWAITING_INFORMATION','DECISION_PENDING','DECIDED','CANCELLED'))");
        DB::statement("ALTER TABLE underwriting_cases ADD CONSTRAINT underwriting_case_outcome_allowed CHECK (outcome IS NULL OR outcome IN ('APPROVED','CONDITIONAL','COUNTEROFFERED','DECLINED'))");

        // Backfill outcomes of already-decided cases from their latest decision.
        DB::statement(<<<'SQL'
            UPDATE underwriting_cases c SET outcome = d.decision
            FROM (SELECT DISTINCT ON (underwriting_case_id) underwriting_case_id, decision FROM underwriting_decisions ORDER BY underwriting_case_id, decided_at DESC) d
            WHERE d.underwriting_case_id = c.id AND c.status = 'DECIDED' AND d.decision IN ('APPROVED','COUNTEROFFERED','DECLINED')
        SQL);
        DB::statement("UPDATE underwriting_decisions SET outcome = decision WHERE decision IN ('APPROVED','COUNTEROFFERED','DECLINED')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE underwriting_cases DROP CONSTRAINT IF EXISTS underwriting_case_outcome_allowed');
        DB::statement("UPDATE underwriting_cases SET status = 'IN_REVIEW' WHERE status = 'DECISION_PENDING'");
        DB::statement('ALTER TABLE underwriting_cases DROP CONSTRAINT IF EXISTS underwriting_case_status_allowed');
        DB::statement("ALTER TABLE underwriting_cases ADD CONSTRAINT underwriting_case_status_allowed CHECK (status IN ('QUEUED','IN_REVIEW','AWAITING_INFORMATION','DECIDED','CANCELLED'))");
        Schema::table('underwriting_decisions', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('engine_evaluation_id');
            $t->dropColumn(['outcome', 'system_recommendation']);
        });
        Schema::table('underwriting_cases', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('engine_evaluation_id');
            $t->dropColumn(['outcome', 'recommendation', 'risk_score', 'risk_band', 'risk_factors', 'rule_set_versions', 'evaluated_at', 'review_started_at', 'decision_pending_at']);
        });
    }
};
