<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 12 C13 — REQ-CLM-013 claim settlement.
 *
 *  claim_settlements         one calculated settlement (explainable breakdown) and its lifecycle
 *                            CALCULATED → OFFERED → ACCEPTED | DISPUTED → DISCHARGE_SIGNED → PAYMENT_PENDING → PAID (+ SUPERSEDED).
 *  claim_settlement_events   append-only history of every settlement transition.
 *  claim_payments.claim_settlement_id   the payment executing a settlement (nullable: legacy payments carry none).
 * The claim.settlement.approved / claim.settlement.paid accounting mappings already exist (2026_10_15_106001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_settlements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('claim_id')->constrained();
            $t->foreignUuid('claim_decision_id')->constrained('claim_decisions');
            $t->foreignUuid('payee_party_id')->constrained('parties');
            $t->string('reference', 32)->unique();
            $t->string('status', 24)->default('CALCULATED');
            $t->string('currency', 3);
            $t->bigInteger('covered_minor');
            $t->bigInteger('excluded_minor')->default(0);
            $t->bigInteger('deductible_minor')->default(0);
            $t->bigInteger('prior_payments_minor')->default(0);
            $t->bigInteger('adjustments_minor')->default(0);
            $t->bigInteger('remaining_limit_minor')->nullable();
            $t->bigInteger('gross_minor');
            $t->bigInteger('amount_minor');
            $t->boolean('limit_capped')->default(false);
            $t->string('limit_source', 24)->nullable();
            $t->jsonb('breakdown');
            $t->text('dispute_reason')->nullable();
            $t->uuid('discharge_document_id')->nullable();
            $t->uuid('signature_request_id')->nullable();
            $t->uuid('claim_payment_id')->nullable();
            $t->uuid('financial_obligation_id')->nullable();
            $t->uuid('approved_journal_id')->nullable();
            $t->uuid('paid_journal_id')->nullable();
            $t->foreignUuid('calculated_by')->constrained('users');
            $t->foreignUuid('offered_by')->nullable()->constrained('users');
            $t->timestampTz('offered_at')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('disputed_at')->nullable();
            $t->timestampTz('discharge_signed_at')->nullable();
            $t->timestampTz('payment_requested_at')->nullable();
            $t->timestampTz('paid_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->index(['claim_id', 'status']);
            $t->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE claim_settlements ADD CONSTRAINT claim_settlements_status_allowed CHECK (status IN ('CALCULATED','OFFERED','ACCEPTED','DISPUTED','DISCHARGE_SIGNED','PAYMENT_PENDING','PAID','SUPERSEDED'))");
        DB::statement('ALTER TABLE claim_settlements ADD CONSTRAINT claim_settlements_amounts CHECK (covered_minor >= 0 AND excluded_minor >= 0 AND deductible_minor >= 0 AND prior_payments_minor >= 0 AND amount_minor >= 0 AND (remaining_limit_minor IS NULL OR amount_minor <= GREATEST(remaining_limit_minor, 0)))');
        DB::statement('ALTER TABLE claim_settlements ADD CONSTRAINT claim_settlements_offer_checker CHECK (offered_by IS NULL OR offered_by <> calculated_by)');
        // At most one live (not superseded / not paid) settlement per claim.
        DB::statement("CREATE UNIQUE INDEX claim_settlements_one_live ON claim_settlements (claim_id) WHERE status NOT IN ('SUPERSEDED','PAID')");

        Schema::create('claim_settlement_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('claim_settlement_id')->constrained('claim_settlements');
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->jsonb('details')->nullable();
            $t->timestampTz('occurred_at');
            $t->index(['claim_settlement_id', 'occurred_at']);
        });

        Schema::table('claim_payments', function (Blueprint $t): void {
            $t->foreignUuid('claim_settlement_id')->nullable()->constrained('claim_settlements');
        });
    }

    public function down(): void
    {
        Schema::table('claim_payments', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('claim_settlement_id');
        });
        Schema::dropIfExists('claim_settlement_events');
        Schema::dropIfExists('claim_settlements');
    }
};
