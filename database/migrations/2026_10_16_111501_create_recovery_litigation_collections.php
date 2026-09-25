<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Agent C15 — REQ-REC-001 recoveries as RECEIVABLE obligations (+ receipts, claim.recovery.received mapping),
 * REQ-REC-002 litigation matters on LITIGATION cases, REQ-REC-003 collections (dunning, promises, write-off maker-checker).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_recoveries', function (Blueprint $t): void {
            $t->uuid('financial_obligation_id')->nullable()->index();
            $t->uuid('debtor_party_id')->nullable();
            $t->timestampTz('due_at')->nullable();
            $t->text('dispute_reason')->nullable();
            $t->string('close_reason')->nullable();
        });

        Schema::create('claim_recovery_receipts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('claim_recovery_id')->constrained('claim_recoveries')->cascadeOnDelete();
            $t->bigInteger('amount_minor');
            $t->string('currency', 3);
            $t->string('reference', 120);
            $t->uuid('journal_id')->nullable();
            $t->foreignUuid('received_by')->constrained('users');
            $t->timestampTz('received_at');
            $t->timestampsTz();
            $t->unique(['claim_recovery_id', 'reference']);
        });
        DB::statement('ALTER TABLE claim_recovery_receipts ADD CONSTRAINT claim_recovery_receipt_positive CHECK (amount_minor > 0)');

        Schema::create('legal_matters', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('case_id')->unique();
            $t->foreign('case_id')->references('id')->on('cases');
            $t->foreignUuid('claim_id')->nullable()->constrained();
            $t->foreignUuid('claim_recovery_id')->nullable()->constrained('claim_recoveries');
            $t->string('role', 16); // PLAINTIFF | DEFENDANT
            $t->string('court');
            $t->string('court_reference', 120)->nullable();
            $t->string('jurisdiction', 8)->default('CM');
            $t->uuid('lawyer_party_id')->nullable();
            $t->string('opposing_party_name')->nullable();
            $t->bigInteger('claimed_amount_minor')->default(0);
            $t->string('currency', 3);
            $t->string('status', 16)->default('ACTIVE'); // ACTIVE | CONCLUDED
            $t->string('outcome', 16)->nullable(); // WON | LOST | SETTLED | WITHDRAWN | DISMISSED
            $t->bigInteger('outcome_amount_minor')->nullable();
            $t->text('outcome_notes')->nullable();
            $t->timestampTz('concluded_at')->nullable();
            $t->foreignUuid('opened_by')->constrained('users');
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
        });
        Schema::create('legal_hearings', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('legal_matter_id')->constrained()->cascadeOnDelete();
            $t->timestampTz('scheduled_at');
            $t->string('location')->nullable();
            $t->string('purpose')->nullable();
            $t->string('status', 16)->default('SCHEDULED'); // SCHEDULED | HELD | ADJOURNED | CANCELLED
            $t->text('result')->nullable();
            $t->timestampsTz();
        });
        Schema::create('legal_deadlines', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('legal_matter_id')->constrained()->cascadeOnDelete();
            $t->string('description');
            $t->timestampTz('due_at');
            $t->string('status', 16)->default('OPEN'); // OPEN | MET | MISSED
            $t->timestampTz('completed_at')->nullable();
            $t->timestampsTz();
            $t->index(['status', 'due_at']);
        });
        Schema::create('legal_costs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('legal_matter_id')->constrained()->cascadeOnDelete();
            $t->string('cost_type', 24); // LAWYER_FEE | COURT_FEE | BAILIFF | EXPERT | OTHER
            $t->bigInteger('amount_minor');
            $t->string('currency', 3);
            $t->uuid('payee_party_id')->nullable();
            $t->uuid('financial_obligation_id')->nullable();
            $t->string('description')->nullable();
            $t->date('incurred_on');
            $t->foreignUuid('recorded_by')->constrained('users');
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE legal_costs ADD CONSTRAINT legal_cost_positive CHECK (amount_minor > 0)');

        Schema::create('collection_accounts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('financial_obligation_id')->unique();
            $t->string('stage', 16)->default('CURRENT'); // CURRENT | REMINDER_1 | REMINDER_2 | FINAL_NOTICE | ESCALATED
            $t->string('status', 16)->default('ACTIVE'); // ACTIVE | SETTLED | WRITTEN_OFF | CLOSED
            $t->uuid('case_id')->nullable();
            $t->timestampTz('last_notice_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status', 'stage']);
        });
        Schema::create('collection_notices', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('collection_account_id')->constrained()->cascadeOnDelete();
            $t->string('stage', 16);
            $t->bigInteger('outstanding_minor');
            $t->string('currency', 3);
            $t->integer('days_overdue');
            $t->timestampTz('issued_at');
            $t->unique(['collection_account_id', 'stage']);
        });
        Schema::create('collection_promises', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('collection_account_id')->constrained()->cascadeOnDelete();
            $t->bigInteger('amount_minor');
            $t->bigInteger('outstanding_at_promise_minor');
            $t->date('promised_for');
            $t->string('status', 16)->default('PENDING'); // PENDING | KEPT | BROKEN
            $t->text('notes')->nullable();
            $t->foreignUuid('recorded_by')->constrained('users');
            $t->timestampTz('evaluated_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE collection_promises ADD CONSTRAINT collection_promise_positive CHECK (amount_minor > 0)');
        Schema::create('collection_write_off_requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->uuid('financial_obligation_id');
            $t->bigInteger('outstanding_minor');
            $t->string('currency', 3);
            $t->text('reason');
            $t->string('status', 16)->default('PENDING'); // PENDING | APPROVED | REJECTED
            $t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->text('decision_note')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE collection_write_off_requests ADD CONSTRAINT collection_write_off_maker_checker CHECK (decided_by IS NULL OR decided_by <> requested_by)');
        DB::statement("CREATE UNIQUE INDEX collection_write_off_one_pending ON collection_write_off_requests (financial_obligation_id) WHERE status = 'PENDING'");

        // claim.recovery.received default mapping (DefaultChartOfAccounts::EVENTS): Dr Bank / Cr Claims expense.
        $now = now();
        if (! DB::table('accounting_events')->where('code', 'claim.recovery.received')->exists()) {
            DB::table('accounting_events')->insert(['code' => 'claim.recovery.received', 'category' => 'CLAIMS', 'description' => 'Claim recovery (subrogation / salvage / contribution) received.', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('accounting_event_mappings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'event_code' => 'claim.recovery.received', 'version' => 1, 'debit_account_code' => '521000',
                'credit_account_code' => '601000', 'status' => 'ACTIVE', 'reason' => 'Default OHADA/CIMA chart', 'effective_from' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('accounting_event_mappings')->whereNull('tenant_id')->where('event_code', 'claim.recovery.received')->delete();
        DB::table('accounting_events')->where('code', 'claim.recovery.received')->delete();
        foreach (['collection_write_off_requests', 'collection_promises', 'collection_notices', 'collection_accounts', 'legal_costs', 'legal_deadlines', 'legal_hearings', 'legal_matters', 'claim_recovery_receipts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('claim_recoveries', fn (Blueprint $t) => $t->dropColumn(['financial_obligation_id', 'debtor_party_id', 'due_at', 'dispute_reason', 'close_reason']));
    }
};
