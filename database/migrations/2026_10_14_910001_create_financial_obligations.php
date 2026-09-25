<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 9-1 — REQ-OBL-001 universal financial obligations (money-chain contract) + REQ-PAY-006 instalment link.
 *
 *  financial_obligations         one receivable / payable per money fact (premium, instalment, fee, tax, refund, commission, claim…).
 *  financial_obligation_events   append-only history (CREATED, SETTLEMENT, UNSETTLEMENT, WRITTEN_OFF, CANCELLED); a settlement reference is unique
 *                                per obligation, which makes ObligationService::settle idempotent.
 *  policy_premium_instalments.financial_obligation_id   instalment → its obligation.
 *  payment_intents.financial_obligation_id              a payment raised against a specific obligation (e.g. a later instalment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_obligations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('kind', 16);
            $t->string('type', 32);
            $t->string('debtor_type', 32)->nullable();
            $t->uuid('debtor_id')->nullable();
            $t->string('creditor_type', 32)->nullable();
            $t->uuid('creditor_id')->nullable();
            $t->string('source_type', 64);
            $t->uuid('source_id');
            $t->string('source_reference', 64)->nullable();
            $t->uuid('policy_id')->nullable();
            $t->string('currency', 3);
            $t->bigInteger('amount_minor');
            $t->bigInteger('outstanding_minor');
            $t->timestampTz('due_at');
            $t->string('status', 20)->default('OPEN');
            $t->timestampTz('settled_at')->nullable();
            $t->string('description')->nullable();
            $t->jsonb('metadata')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status', 'due_at']);
            $t->index(['debtor_type', 'debtor_id']);
            $t->index('policy_id');
        });
        DB::statement("CREATE UNIQUE INDEX fo_source_unique ON financial_obligations (source_type, source_id, type, COALESCE(source_reference, ''))");
        DB::statement("ALTER TABLE financial_obligations ADD CONSTRAINT fo_kind_allowed CHECK (kind IN ('RECEIVABLE','PAYABLE'))");
        DB::statement("ALTER TABLE financial_obligations ADD CONSTRAINT fo_status_allowed CHECK (status IN ('OPEN','PARTIALLY_SETTLED','SETTLED','WRITTEN_OFF','CANCELLED'))");
        DB::statement('ALTER TABLE financial_obligations ADD CONSTRAINT fo_amounts CHECK (amount_minor > 0 AND outstanding_minor >= 0 AND outstanding_minor <= amount_minor)');

        Schema::create('financial_obligation_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('financial_obligation_id')->constrained('financial_obligations');
            $t->string('event_type', 24);
            $t->bigInteger('amount_minor')->default(0);
            $t->bigInteger('outstanding_after_minor');
            $t->string('status_after', 20);
            $t->string('reference', 128)->nullable();
            $t->uuid('actor_id')->nullable();
            $t->jsonb('metadata')->nullable();
            $t->timestampTz('occurred_at');
            $t->index(['financial_obligation_id', 'occurred_at']);
        });
        DB::statement("CREATE UNIQUE INDEX foe_settlement_reference ON financial_obligation_events (financial_obligation_id, reference) WHERE event_type = 'SETTLEMENT' AND reference IS NOT NULL");

        Schema::table('policy_premium_instalments', fn (Blueprint $t) => $t->uuid('financial_obligation_id')->nullable()->index());
        Schema::table('payment_intents', fn (Blueprint $t) => $t->uuid('financial_obligation_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('payment_intents', fn (Blueprint $t) => $t->dropColumn('financial_obligation_id'));
        Schema::table('policy_premium_instalments', fn (Blueprint $t) => $t->dropColumn('financial_obligation_id'));
        Schema::dropIfExists('financial_obligation_events');
        Schema::dropIfExists('financial_obligations');
    }
};
