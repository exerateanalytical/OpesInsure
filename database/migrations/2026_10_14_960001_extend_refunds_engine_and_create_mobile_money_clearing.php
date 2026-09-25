<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 9-6.
 *
 * REQ-PAY-009 / WF-063 — the existing `refunds` table becomes the refund engine (no second refund table):
 *   CANDIDATE → CALCULATED → REQUESTED (reviewed, awaiting approval) → APPROVED → PAID → RECONCILED, or REJECTED.
 *   Legacy rows created straight into REQUESTED by FinancialCaseService::requestRefund keep working.
 *   source_type/source_id say what raised the refund (cancellation, endorsement, issuance_exception, manual).
 *   financial_obligation_id links the PAYABLE/REFUND obligation of agent 9-1 — nullable, deliberately no FK.
 *
 * REQ-PAY-011 — mobile-money clearing: provider success ≠ bank settlement. One clearing batch per provider
 * settlement (report) reference; items attach succeeded payments to it; reconciliation compares expected vs
 * bank-credited amounts. The suspense balance is derived (received-but-unallocated + unsettled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $t): void {
            $t->string('source_type', 40)->nullable();
            $t->uuid('source_id')->nullable();
            $t->jsonb('calculation')->nullable();
            $t->foreignUuid('calculated_by')->nullable()->constrained('users');
            $t->timestampTz('calculated_at')->nullable();
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $t->timestampTz('reviewed_at')->nullable();
            $t->foreignUuid('paid_by')->nullable()->constrained('users');
            $t->timestampTz('paid_at')->nullable();
            $t->string('payout_method', 32)->nullable();
            $t->foreignUuid('reconciled_by')->nullable()->constrained('users');
            $t->timestampTz('reconciled_at')->nullable();
            $t->string('bank_reference', 128)->nullable();
            $t->foreignUuid('rejected_by')->nullable()->constrained('users');
            $t->timestampTz('rejected_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->uuid('financial_obligation_id')->nullable(); // agent 9-1 financial_obligations — no FK by contract
            $t->index(['tenant_id', 'status']);
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('mobile_money_clearing_batches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('provider', 32);
            $t->string('settlement_reference', 128);
            $t->date('settlement_date');
            $t->string('currency', 3);
            $t->bigInteger('expected_minor')->default(0);   // sum of attached provider-succeeded payments
            $t->bigInteger('fee_minor')->default(0);        // provider commission withheld
            $t->bigInteger('settled_minor')->nullable();    // amount the bank actually credited
            $t->bigInteger('variance_minor')->nullable();   // settled + fee − expected
            $t->string('status', 16)->default('OPEN');     // OPEN | SETTLED | RECONCILED | VARIANCE
            $t->string('bank_reference', 128)->nullable();
            $t->timestampTz('settled_at')->nullable();
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('settled_by')->nullable()->constrained('users');
            $t->foreignUuid('reconciled_by')->nullable()->constrained('users');
            $t->timestampTz('reconciled_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'provider', 'settlement_reference']);
            $t->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE mobile_money_clearing_batches ADD CONSTRAINT mm_clearing_status_allowed CHECK (status IN ('OPEN','SETTLED','RECONCILED','VARIANCE'))");
        DB::statement('ALTER TABLE mobile_money_clearing_batches ADD CONSTRAINT mm_clearing_reconciler_separation CHECK (reconciled_by IS NULL OR reconciled_by <> settled_by)');

        Schema::create('mobile_money_clearing_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('clearing_batch_id')->constrained('mobile_money_clearing_batches')->cascadeOnDelete();
            $t->foreignUuid('payment_intent_id')->unique()->constrained('payment_intents'); // a payment clears once
            $t->bigInteger('amount_minor');
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_money_clearing_items');
        Schema::dropIfExists('mobile_money_clearing_batches');
        Schema::table('refunds', function (Blueprint $t): void {
            $t->dropIndex(['tenant_id', 'status']);
            $t->dropIndex(['source_type', 'source_id']);
            foreach (['calculated_by', 'reviewed_by', 'paid_by', 'reconciled_by', 'rejected_by'] as $c) {
                $t->dropConstrainedForeignId($c);
            }
            $t->dropColumn(['source_type', 'source_id', 'calculation', 'calculated_at', 'reviewed_at', 'paid_at', 'payout_method', 'reconciled_at',
                'bank_reference', 'rejected_at', 'rejection_reason', 'financial_obligation_id']);
        });
    }
};
