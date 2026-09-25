<?php

declare(strict_types=1);

use App\Application\Finance\Subledger\SubledgerCatalogue;
use App\Application\Ledger\Posting\DefaultChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Agent F1 — Finance Counterparty Accounts & Commission Sub-Ledger (owner canonical spec v1).
 * Extends the Batch 9-10 money chain; never duplicates journals / obligations / commission_accruals / settlements:
 *   finance_counterparty_accounts   counterparty accounts per relationship, each linked to a GL control account (ledger_accounts)
 *   finance_ledger_entries          immutable sub-ledger projected from POSTED journal_lines (SubledgerProjector) with the full
 *                                   spec dimension set; append-only (a reversal is a new entry, the original is never touched)
 *   premium_remittances (+ allocations) broker → insurer premium remittances; allocations settle carrier PAYABLE PREMIUM
 *                                   obligations, the rest stays visible as unapplied cash (471100)
 *   finance_aging_settings          configurable aging date basis per tenant + scope
 * Seeds the new accounting events premium.remittance.recorded / premium.remittance.allocated (default mapping, tenant re-mappable).
 */
return new class extends Migration
{
    private const EVENTS = ['premium.remittance.recorded', 'premium.remittance.allocated'];

    public function up(): void
    {
        Schema::create('finance_counterparty_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('holder_type', 30)->nullable(); // null = the tenant itself
            $t->uuid('holder_id')->nullable();
            $t->string('counterparty_type', 30);
            $t->uuid('counterparty_id');
            $t->string('relationship_type', 40);
            $t->string('account_type', 40);
            $t->string('account_code', 80);
            $t->char('currency', 3);
            $t->string('status', 30)->default('PENDING_APPROVAL');
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->uuid('gl_control_account_id');
            $t->uuid('branch_id')->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->foreign('gl_control_account_id')->references('id')->on('ledger_accounts');
            $t->unique(['tenant_id', 'account_code', 'currency']);
            $t->index(['tenant_id', 'counterparty_type', 'counterparty_id']);
            $t->index(['tenant_id', 'gl_control_account_id']);
        });
        DB::statement("ALTER TABLE finance_counterparty_accounts ADD CONSTRAINT fca_status_check CHECK (status IN ('PENDING_APPROVAL','ACTIVE','SUSPENDED','CLOSED'))");

        Schema::create('finance_ledger_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->nullable()->index();
            $t->uuid('journal_id')->index();
            $t->uuid('journal_line_id')->unique();
            $t->uuid('account_id')->index();
            $t->uuid('counterparty_account_id')->nullable()->index();
            $t->string('entry_type', 40);
            $t->string('journal_type', 40)->nullable();
            $t->bigInteger('debit_amount')->default(0);
            $t->bigInteger('credit_amount')->default(0);
            $t->char('currency', 3);
            $t->date('transaction_date');
            $t->date('value_date');
            $t->date('posting_date');
            $t->string('status', 20)->default('POSTED');
            $t->uuid('reverses_entry_id')->nullable();
            $t->string('source_event_id', 120)->nullable();
            $t->string('source_entity_type', 60)->nullable();
            $t->string('source_entity_id', 120)->nullable();
            $t->string('idempotency_key', 160)->unique();
            foreach (SubledgerCatalogue::DIMENSIONS as $dim) {
                $t->string($dim, 64)->nullable();
            }
            $t->jsonb('financials')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tenant_id', 'currency', 'posting_date']);
            $t->index(['tenant_id', 'insurer_id']);
            $t->index(['tenant_id', 'broker_id']);
            $t->index(['tenant_id', 'agent_id']);
            $t->index(['tenant_id', 'customer_id']);
            $t->index(['tenant_id', 'policy_id']);
        });
        DB::statement("ALTER TABLE finance_ledger_entries ADD CONSTRAINT fle_one_side_check CHECK (debit_amount >= 0 AND credit_amount >= 0 AND NOT (debit_amount > 0 AND credit_amount > 0))");
        // Posted entries are immutable: corrections are reversal entries (principles.posted_entries_immutable).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION finance_ledger_entries_immutable() RETURNS trigger AS $$
BEGIN
  RAISE EXCEPTION 'finance_ledger_entries are immutable (%): post a reversal instead', TG_OP;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER finance_ledger_entries_no_update BEFORE UPDATE OR DELETE ON finance_ledger_entries FOR EACH ROW EXECUTE FUNCTION finance_ledger_entries_immutable();
SQL);

        Schema::create('premium_remittances', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('remittance_number', 60);
            $t->uuid('insurer_id');
            $t->uuid('broker_id')->nullable();
            $t->char('currency', 3);
            $t->bigInteger('amount_minor');
            $t->bigInteger('allocated_minor')->default(0);
            $t->string('status', 30)->default('UNAPPLIED');
            $t->date('remittance_date');
            $t->string('payment_reference', 120)->nullable();
            $t->string('idempotency_key', 120);
            $t->uuid('journal_id')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->unique(['tenant_id', 'remittance_number']);
            $t->index(['tenant_id', 'insurer_id', 'currency']);
        });
        DB::statement("ALTER TABLE premium_remittances ADD CONSTRAINT prm_amount_check CHECK (amount_minor > 0 AND allocated_minor >= 0 AND allocated_minor <= amount_minor)");
        DB::statement("ALTER TABLE premium_remittances ADD CONSTRAINT prm_status_check CHECK (status IN ('UNAPPLIED','PARTIALLY_APPLIED','APPLIED','DISPUTED','RECONCILIATION_HOLD'))");

        Schema::create('premium_remittance_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('premium_remittance_id')->index();
            $t->uuid('financial_obligation_id')->index();
            $t->uuid('policy_id')->nullable()->index();
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->uuid('journal_id')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->foreign('premium_remittance_id')->references('id')->on('premium_remittances');
            $t->foreign('financial_obligation_id')->references('id')->on('financial_obligations');
        });
        DB::statement('ALTER TABLE premium_remittance_allocations ADD CONSTRAINT pra_amount_check CHECK (amount_minor > 0)');

        Schema::create('finance_aging_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->string('scope', 40); // RECEIVABLE / PAYABLE / COMMISSION / REMITTANCE
            $t->string('basis', 40);
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'scope']);
        });

        $now = now();
        foreach (self::EVENTS as $code) {
            [$category, $debit, $credit, $description] = DefaultChartOfAccounts::EVENTS[$code];
            if (! DB::table('accounting_events')->where('code', $code)->exists()) {
                DB::table('accounting_events')->insert(['code' => $code, 'category' => $category, 'description' => $description, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
            }
            if (! DB::table('accounting_event_mappings')->where('event_code', $code)->whereNull('tenant_id')->exists()) {
                DB::table('accounting_event_mappings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'event_code' => $code, 'version' => 1, 'debit_account_code' => $debit,
                    'credit_account_code' => $credit, 'status' => 'ACTIVE', 'reason' => 'Default OHADA/CIMA chart', 'effective_from' => $now, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('accounting_event_mappings')->whereNull('tenant_id')->whereIn('event_code', self::EVENTS)->delete();
        DB::table('accounting_events')->whereIn('code', self::EVENTS)->delete();
        Schema::dropIfExists('finance_aging_settings');
        Schema::dropIfExists('premium_remittance_allocations');
        Schema::dropIfExists('premium_remittances');
        Schema::dropIfExists('finance_ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS finance_ledger_entries_immutable() CASCADE');
        Schema::dropIfExists('finance_counterparty_accounts');
    }
};
