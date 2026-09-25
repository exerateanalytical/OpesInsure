<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-CAN-001 (WF-044/045) — cancellation case: REQUESTED → UNDER_REVIEW → APPROVED | REJECTED | WITHDRAWN.
 * Wraps the CANCELLATION policy_transaction (which owns the refund figure and the policy status change);
 * records who initiated, the notice served, the review, the authority check, the CANCELLATION policy
 * version, the refund obligation and the documents revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_cancellations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->foreignUuid('policy_id')->constrained();
            $t->foreignUuid('policy_transaction_id')->unique()->constrained('policy_transactions');
            $t->string('status', 16)->default('REQUESTED');
            $t->string('initiated_by', 16);                 // INSURED | INSURER | INTERMEDIARY
            $t->string('reason_code', 64);
            $t->timestampTz('effective_at');
            $t->string('refund_basis', 16);                 // PRO_RATA | SHORT_RATE
            $t->bigInteger('refund_minor')->default(0);
            $t->string('currency', 3);
            $t->unsignedSmallInteger('notice_days')->default(0);
            $t->timestampTz('notice_served_at')->nullable();
            $t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->uuid('authority_check_id')->nullable();
            $t->uuid('policy_version_id')->nullable();
            $t->foreignUuid('refund_id')->nullable()->constrained('refunds');
            $t->unsignedInteger('documents_revoked')->default(0);
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
            $t->index(['policy_id', 'status']);
        });
        DB::statement("ALTER TABLE policy_cancellations ADD CONSTRAINT policy_cancellations_status CHECK (status IN ('REQUESTED','UNDER_REVIEW','APPROVED','REJECTED','WITHDRAWN'))");
        DB::statement("ALTER TABLE policy_cancellations ADD CONSTRAINT policy_cancellations_initiator CHECK (initiated_by IN ('INSURED','INSURER','INTERMEDIARY'))");
        DB::statement('ALTER TABLE policy_cancellations ADD CONSTRAINT policy_cancellations_refund CHECK (refund_minor >= 0)');
        DB::statement('ALTER TABLE policy_cancellations ADD CONSTRAINT policy_cancellations_checker CHECK ((reviewed_by IS NULL OR reviewed_by <> requested_by) AND (decided_by IS NULL OR decided_by <> requested_by))');
        // One open cancellation per policy.
        DB::statement("CREATE UNIQUE INDEX policy_cancellations_one_open ON policy_cancellations (policy_id) WHERE status IN ('REQUESTED','UNDER_REVIEW')");
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_cancellations');
    }
};
