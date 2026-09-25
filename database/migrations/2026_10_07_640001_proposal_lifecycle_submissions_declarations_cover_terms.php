<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 6D — proposal lifecycle (REQ-PRP-001…005). Additive only.
 *
 *  proposals.status            + INFORMATION_REQUIRED, RESUBMITTED (blueprint info-request loop). Existing codes kept:
 *                                mobile 1.3.0 reads DISCLOSURES_PENDING / DOCUMENTS_PENDING / UNDER_REVIEW / COUNTEROFFERED / PAYMENT_PENDING.
 *  proposals.question_set_id   PROPOSAL-stage question set (rules engine); legacy rows keep disclosure_schema_version_id.
 *  proposals.question_snapshot frozen questionnaire the customer answered (+ hash).
 *  proposal_submissions        append-only, hashed snapshot per submission / resubmission (immutable submitted snapshot).
 *  proposal_declarations       append-only declarations / attestations / consent with market-conduct evidence hashes.
 *  proposal_documents          + document_type_id / requirement_source (catalogue requirement, REQ-DUP-004).
 *  cover_term_rules            effective-date rules, durations, instalment plans per product version or line (REQ-PRP-005).
 */
return new class extends Migration
{
    private const STATUSES = ['DRAFT', 'DISCLOSURES_PENDING', 'DOCUMENTS_PENDING', 'SUBMITTED', 'UNDER_REVIEW', 'INFORMATION_REQUIRED', 'RESUBMITTED',
        'APPROVED', 'COUNTEROFFERED', 'DECLINED', 'PAYMENT_PENDING', 'WITHDRAWN'];

    public function up(): void
    {
        DB::statement('ALTER TABLE proposals DROP CONSTRAINT IF EXISTS proposal_status_allowed');
        DB::statement("ALTER TABLE proposals ADD CONSTRAINT proposal_status_allowed CHECK (status IN ('".implode("','", self::STATUSES)."'))");

        Schema::table('proposals', function (Blueprint $t): void {
            $t->foreignUuid('question_set_id')->nullable()->constrained('question_sets');
            $t->jsonb('question_snapshot')->nullable();
            $t->string('question_snapshot_hash', 64)->nullable();
            $t->jsonb('information_request')->nullable();
            $t->unsignedInteger('submission_count')->default(0);
            $t->string('submitted_snapshot_hash', 64)->nullable();
            $t->jsonb('cover_terms')->nullable();
            $t->timestampTz('withdrawn_at')->nullable();
        });

        DB::statement('ALTER TABLE proposal_disclosure_responses ALTER COLUMN disclosure_schema_version_id DROP NOT NULL');
        Schema::table('proposal_disclosure_responses', function (Blueprint $t): void {
            $t->foreignUuid('question_set_id')->nullable()->constrained('question_sets');
        });
        DB::statement('CREATE UNIQUE INDEX proposal_disclosure_responses_one_question_set ON proposal_disclosure_responses (proposal_id) WHERE disclosure_schema_version_id IS NULL');

        Schema::table('proposal_documents', function (Blueprint $t): void {
            $t->string('document_type_id', 64)->nullable();
            $t->string('requirement_source', 24)->nullable();
        });

        Schema::create('proposal_submissions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('proposal_id')->constrained('proposals');
            $t->unsignedInteger('sequence');
            $t->string('kind', 16); // SUBMIT | RESUBMIT
            $t->jsonb('snapshot');
            $t->string('snapshot_hash', 64);
            $t->foreignUuid('submitted_by')->nullable()->constrained('users');
            $t->timestampTz('submitted_at');
            $t->unique(['proposal_id', 'sequence']);
        });

        Schema::create('proposal_declarations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('proposal_id')->constrained('proposals');
            $t->string('code', 64);
            $t->string('text_version', 32);
            $t->string('text_hash', 64);
            $t->jsonb('statement');
            $t->string('legal_status', 24); // UNVERIFIED until reviewed by counsel
            $t->string('channel', 16);
            $t->jsonb('evidence')->default('{}');
            $t->foreignUuid('accepted_by')->nullable()->constrained('users');
            $t->timestampTz('accepted_at');
            $t->index(['proposal_id', 'code']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER proposal_submissions_append_only BEFORE UPDATE OR DELETE ON proposal_submissions FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
            CREATE TRIGGER proposal_declarations_append_only BEFORE UPDATE OR DELETE ON proposal_declarations FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
        SQL);

        Schema::create('cover_term_rules', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('insurance_product_id')->nullable()->constrained('insurance_products');
            $t->string('line_code', 32)->nullable();
            $t->jsonb('effective_date_rules');     // subset of IMMEDIATE, SPECIFIED_DATE, PAYMENT_DATE, APPROVAL_DATE, MIDNIGHT_RULE, CUSTOM
            $t->string('default_effective_rule', 24);
            $t->jsonb('durations');                 // [{unit: DAY|MONTH, value: n}] or [{unit: CUSTOM}]
            $t->jsonb('instalment_plans');          // subset of SINGLE, MONTHLY, QUARTERLY, SEMI_ANNUAL, ANNUAL, CUSTOM
            $t->unsignedSmallInteger('max_advance_days')->default(90);
            $t->unsignedInteger('instalment_fee_minor')->default(0);
            $t->string('non_payment_consequence', 32)->nullable(); // NO_COVER_UNTIL_PAID | GRACE_THEN_SUSPEND | CANCEL (NULL = UNVERIFIED)
            $t->string('status', 16)->default('ACTIVE');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE cover_term_rules ADD CONSTRAINT cover_term_rules_scope CHECK (insurance_product_id IS NOT NULL OR line_code IS NOT NULL)");
        DB::statement("ALTER TABLE cover_term_rules ADD CONSTRAINT cover_term_rules_status CHECK (status IN ('ACTIVE','RETIRED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cover_term_rules');
        Schema::dropIfExists('proposal_declarations');
        Schema::dropIfExists('proposal_submissions');
        Schema::table('proposal_documents', fn (Blueprint $t) => $t->dropColumn(['document_type_id', 'requirement_source']));
        DB::statement('DROP INDEX IF EXISTS proposal_disclosure_responses_one_question_set');
        Schema::table('proposal_disclosure_responses', fn (Blueprint $t) => $t->dropConstrainedForeignId('question_set_id'));
        Schema::table('proposals', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('question_set_id');
            $t->dropColumn(['question_snapshot', 'question_snapshot_hash', 'information_request', 'submission_count', 'submitted_snapshot_hash', 'cover_terms', 'withdrawn_at']);
        });
        DB::statement('ALTER TABLE proposals DROP CONSTRAINT IF EXISTS proposal_status_allowed');
        DB::statement("ALTER TABLE proposals ADD CONSTRAINT proposal_status_allowed CHECK (status IN ('DRAFT','DISCLOSURES_PENDING','DOCUMENTS_PENDING','SUBMITTED','UNDER_REVIEW','APPROVED','COUNTEROFFERED','DECLINED','PAYMENT_PENDING','WITHDRAWN'))");
    }
};
