<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * REQ-CLM-012 (C12) — claim decisions: reason-code catalogue, amount per head, CLAIM_SETTLE authority referral,
 * appeal lineage (an appeal decision is a NEW row pointing at the original; a decided row is immutable) and the
 * customer notification of the decision.
 *
 * The owner has not supplied the decision/rejection reason taxonomy yet (ClaimReferenceCodes::TAXONOMY_STATUS),
 * so the seeded catalogue is marked data_origin PLATFORM_PROVISIONAL and can be replaced row by row.
 */
return new class extends Migration
{
    private const REASONS = [
        ['COVERED_IN_FULL', 'APPROVE', 'Loss covered in full under the policy'],
        ['DEDUCTIBLE_APPLIED', 'PARTIAL', 'Policy deductible/excess applied'],
        ['SUB_LIMIT_APPLIED', 'PARTIAL', 'Policy sub-limit or limit applied'],
        ['UNDERINSURANCE_AVERAGE', 'PARTIAL', 'Average (under-insurance) applied'],
        ['DEPRECIATION_APPLIED', 'PARTIAL', 'Depreciation / wear and tear deducted'],
        ['PARTIAL_COVERAGE', 'PARTIAL', 'Only part of the loss is covered'],
        ['NOT_COVERED', 'DECLINE', 'Loss not covered by the policy'],
        ['EXCLUSION_APPLIES', 'DECLINE', 'A policy exclusion applies'],
        ['POLICY_NOT_IN_FORCE', 'DECLINE', 'Policy not in force at the loss date'],
        ['LATE_NOTIFICATION', 'DECLINE', 'Loss notified outside the contractual deadline'],
        ['INSUFFICIENT_EVIDENCE', 'DECLINE', 'Evidence insufficient to establish the loss'],
        ['MISREPRESENTATION', 'DECLINE', 'Material misrepresentation or non-disclosure'],
        ['FRAUD_CONFIRMED', 'DECLINE', 'Fraud established by investigation'],
        ['APPEAL_UPHELD', 'ANY', 'Appeal upheld: original decision revised'],
        ['APPEAL_DISMISSED', 'ANY', 'Appeal dismissed: original decision maintained'],
    ];

    public function up(): void
    {
        Schema::create('claim_decision_reason_codes', function (Blueprint $t): void {
            $t->string('code', 64)->primary();
            $t->string('applies_to', 16);             // APPROVE | PARTIAL | DECLINE | ANY
            $t->string('label');
            $t->string('customer_text')->nullable(); // wording sent to the customer (falls back to label)
            $t->string('status', 16)->default('ACTIVE');
            $t->string('data_origin', 32)->default('PLATFORM_PROVISIONAL');
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE claim_decision_reason_codes ADD CONSTRAINT claim_decision_reason_scope CHECK (applies_to IN ('APPROVE','PARTIAL','DECLINE','ANY'))");
        foreach (self::REASONS as [$code, $scope, $label]) {
            DB::table('claim_decision_reason_codes')->insert(['code' => $code, 'applies_to' => $scope, 'label' => $label, 'customer_text' => $label, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('claim_decisions', function (Blueprint $t): void {
            $t->string('kind', 16)->default('ORIGINAL');                    // ORIGINAL | APPEAL
            $t->foreignUuid('appeal_of_decision_id')->nullable()->constrained('claim_decisions');
            $t->foreignUuid('dispute_id')->nullable()->constrained('claim_disputes');
            $t->jsonb('reason_codes')->default('[]');
            $t->jsonb('heads')->default('[]');                               // [{head, amount_minor}]
            $t->uuid('authority_check_id')->nullable();
            $t->uuid('referral_case_id')->nullable();
            $t->foreignUuid('returned_by')->nullable()->constrained('users');
            $t->text('return_reason')->nullable();
            $t->timestampTz('returned_at')->nullable();
            $t->uuid('notification_delivery_id')->nullable();
            $t->string('notification_status', 16)->nullable();              // QUEUED | NO_CONTACT
            $t->timestampTz('notified_at')->nullable();
            $t->index(['claim_id', 'kind']);
        });
        DB::statement("ALTER TABLE claim_decisions ADD CONSTRAINT claim_decision_kind_valid CHECK (kind IN ('ORIGINAL','APPEAL'))");
        DB::statement("ALTER TABLE claim_decisions ADD CONSTRAINT claim_decision_appeal_lineage CHECK ((kind = 'APPEAL') = (appeal_of_decision_id IS NOT NULL))");
        DB::statement('ALTER TABLE claim_decisions ADD CONSTRAINT claim_decision_checker_not_maker CHECK (returned_by IS NULL OR returned_by <> proposed_by)');

        // A decided (APPROVED) or returned decision is never overwritten; an appeal is a new row.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION claim_decisions_immutable() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    IF OLD.status IN ('APPROVED','RETURNED') THEN RAISE EXCEPTION 'claim decision % is final and cannot be deleted', OLD.id; END IF;
    RETURN OLD;
  END IF;
  IF OLD.status IN ('APPROVED','RETURNED') AND (
       NEW.status IS DISTINCT FROM OLD.status OR NEW.decision IS DISTINCT FROM OLD.decision
    OR NEW.approved_amount_minor IS DISTINCT FROM OLD.approved_amount_minor OR NEW.reason_code IS DISTINCT FROM OLD.reason_code
    OR NEW.reason_codes IS DISTINCT FROM OLD.reason_codes OR NEW.rationale IS DISTINCT FROM OLD.rationale
    OR NEW.heads IS DISTINCT FROM OLD.heads OR NEW.approved_by IS DISTINCT FROM OLD.approved_by
    OR NEW.proposed_by IS DISTINCT FROM OLD.proposed_by OR NEW.kind IS DISTINCT FROM OLD.kind
    OR NEW.appeal_of_decision_id IS DISTINCT FROM OLD.appeal_of_decision_id) THEN
    RAISE EXCEPTION 'claim decision % is final and cannot be overwritten', OLD.id;
  END IF;
  RETURN NEW;
END $$ LANGUAGE plpgsql;
CREATE TRIGGER claim_decisions_immutable BEFORE UPDATE OR DELETE ON claim_decisions FOR EACH ROW EXECUTE FUNCTION claim_decisions_immutable();
SQL);

        if (Schema::hasTable('notification_templates') && ! DB::table('notification_templates')->where('code', 'claim.decision.notified')->exists()) {
            $cols = Schema::getColumnListing('notification_templates');
            foreach (['SMS', 'EMAIL'] as $channel) {
                $row = ['id' => (string) Str::uuid(), 'code' => 'claim.decision.notified', 'purpose' => 'SERVICE', 'channel' => $channel, 'locale' => 'en', 'version' => 1,
                    'status' => 'ACTIVE', 'subject' => 'Decision on claim {{claim_number}}',
                    'body' => 'Claim {{claim_number}}: {{decision}}. Amount: {{amount}} {{currency}}. Reasons: {{reasons}}. You may appeal this decision.',
                    'required_variables' => json_encode(['claim_number', 'decision', 'amount', 'currency', 'reasons']), 'created_at' => now(), 'updated_at' => now()];
                DB::table('notification_templates')->insert(array_intersect_key($row, array_flip($cols)));
            }
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('code', 'claim.decision.notified')->delete();
        DB::unprepared('DROP TRIGGER IF EXISTS claim_decisions_immutable ON claim_decisions; DROP FUNCTION IF EXISTS claim_decisions_immutable();');
        DB::statement('ALTER TABLE claim_decisions DROP CONSTRAINT IF EXISTS claim_decision_kind_valid');
        DB::statement('ALTER TABLE claim_decisions DROP CONSTRAINT IF EXISTS claim_decision_appeal_lineage');
        DB::statement('ALTER TABLE claim_decisions DROP CONSTRAINT IF EXISTS claim_decision_checker_not_maker');
        Schema::table('claim_decisions', function (Blueprint $t): void {
            $t->dropIndex(['claim_id', 'kind']);
            $t->dropConstrainedForeignId('appeal_of_decision_id');
            $t->dropConstrainedForeignId('dispute_id');
            $t->dropConstrainedForeignId('returned_by');
            $t->dropColumn(['kind', 'reason_codes', 'heads', 'authority_check_id', 'referral_case_id', 'return_reason', 'returned_at', 'notification_delivery_id', 'notification_status', 'notified_at']);
        });
        Schema::dropIfExists('claim_decision_reason_codes');
    }
};
