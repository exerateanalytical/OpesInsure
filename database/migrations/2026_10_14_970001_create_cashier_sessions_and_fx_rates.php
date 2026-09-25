<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Batch 9-7 — REQ-PAY-013 multi-currency & immutable FX rates, REQ-PAY-010 cashier sessions. Additive only.
 |
 |  fx_rates: append-only (UPDATE/DELETE rejected by trigger). tenant_id NULL = platform rate. A correction is a
 |    new row with a later recorded_at / effective_at. Lookup is "latest effective_at <= as-of".
 |  fx_conversions: append-only; every stored conversion keeps the fx_rate_id used (inverse/cross legs recorded).
 |  EUR peg: the XAF (BEAC) and XOF (BCEAO) are pegged at 655.957 per EUR. Seeded as platform rows, source EUR_PEG.
 |  cashier_sessions: one OPEN session per cashier per branch (partial unique index); approval by a different user.
 |  cashier_collections: CASH/CHEQUE only, never anonymous (party or payer name), optional link to payment_intents and
 |    to financial_obligations (nullable uuid, no FK — table owned by agent 9-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->char('base_currency', 3);
            $t->char('quote_currency', 3);
            $t->decimal('rate', 24, 12);
            $t->string('source', 24);
            $t->string('source_reference', 128)->nullable();
            $t->timestampTz('effective_at');
            $t->foreignUuid('recorded_by')->nullable()->constrained('users');
            $t->timestampTz('recorded_at');
            $t->index(['base_currency', 'quote_currency', 'effective_at']);
        });
        DB::statement('ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_positive CHECK (rate > 0)');
        DB::statement('ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_pair CHECK (base_currency <> quote_currency)');
        DB::statement("ALTER TABLE fx_rates ADD CONSTRAINT fx_rates_source CHECK (source IN ('EUR_PEG','BEAC','BCEAO','ECB','BANK','MANUAL'))");

        Schema::create('fx_conversions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('fx_rate_id')->constrained('fx_rates');
            $t->foreignUuid('second_fx_rate_id')->nullable()->constrained('fx_rates');
            $t->string('method', 16);
            $t->char('from_currency', 3);
            $t->char('to_currency', 3);
            $t->bigInteger('from_amount_minor');
            $t->bigInteger('to_amount_minor');
            $t->decimal('applied_rate', 30, 15);
            $t->timestampTz('as_of');
            $t->string('subject_type', 40)->nullable();
            $t->uuid('subject_id')->nullable();
            $t->timestampTz('created_at');
            $t->index(['subject_type', 'subject_id']);
        });
        DB::statement("ALTER TABLE fx_conversions ADD CONSTRAINT fx_conversions_method CHECK (method IN ('DIRECT','INVERSE','CROSS'))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fx_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION '% is append-only', TG_TABLE_NAME USING ERRCODE = 'P0001';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER fx_rates_append_only BEFORE UPDATE OR DELETE ON fx_rates FOR EACH ROW EXECUTE FUNCTION fx_append_only();
CREATE TRIGGER fx_conversions_append_only BEFORE UPDATE OR DELETE ON fx_conversions FOR EACH ROW EXECUTE FUNCTION fx_append_only();
SQL);

        $at = '1999-01-01 00:00:00+00';
        foreach (['XAF', 'XOF'] as $q) {
            DB::table('fx_rates')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => null, 'base_currency' => 'EUR', 'quote_currency' => $q, 'rate' => '655.957',
                'source' => 'EUR_PEG', 'source_reference' => 'CFA franc fixed parity', 'effective_at' => $at, 'recorded_at' => now(),
            ]);
        }

        Schema::create('cashier_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('branch_id')->constrained('tenant_branches');
            $t->foreignUuid('cashier_user_id')->constrained('users');
            $t->char('currency', 3);
            $t->bigInteger('opening_float_minor');
            $t->string('status', 16)->default('OPEN');
            $t->timestampTz('opened_at');
            $t->bigInteger('expected_cash_minor')->nullable();
            $t->bigInteger('counted_cash_minor')->nullable();
            $t->bigInteger('variance_minor')->nullable();
            $t->bigInteger('cheque_total_minor')->nullable();
            $t->unsignedInteger('cheque_count')->nullable();
            $t->text('closing_notes')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_notes')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE cashier_sessions ADD CONSTRAINT cashier_sessions_status CHECK (status IN ('OPEN','CLOSED','APPROVED','REJECTED'))");
        DB::statement('ALTER TABLE cashier_sessions ADD CONSTRAINT cashier_sessions_float CHECK (opening_float_minor >= 0)');
        DB::statement('ALTER TABLE cashier_sessions ADD CONSTRAINT cashier_sessions_checker CHECK (decided_by IS NULL OR decided_by <> cashier_user_id)');
        DB::statement("CREATE UNIQUE INDEX cashier_sessions_one_open ON cashier_sessions (tenant_id, branch_id, cashier_user_id) WHERE status = 'OPEN'");

        Schema::create('cashier_collections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('cashier_session_id')->constrained('cashier_sessions');
            $t->string('method', 12);
            $t->char('currency', 3);
            $t->bigInteger('amount_minor');
            $t->bigInteger('session_amount_minor');
            $t->foreignUuid('fx_conversion_id')->nullable()->constrained('fx_conversions');
            $t->foreignUuid('payer_party_id')->nullable()->constrained('parties');
            $t->string('payer_name')->nullable();
            $t->foreignUuid('payment_intent_id')->nullable()->constrained('payment_intents');
            $t->uuid('financial_obligation_id')->nullable()->index();
            $t->string('cheque_number', 64)->nullable();
            $t->string('cheque_bank', 128)->nullable();
            $t->string('receipt_number', 64);
            $t->string('reference', 128)->nullable();
            $t->foreignUuid('collected_by')->constrained('users');
            $t->timestampTz('collected_at');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'receipt_number']);
        });
        DB::statement("ALTER TABLE cashier_collections ADD CONSTRAINT cashier_collections_method CHECK (method IN ('CASH','CHEQUE'))");
        DB::statement('ALTER TABLE cashier_collections ADD CONSTRAINT cashier_collections_amount CHECK (amount_minor > 0 AND session_amount_minor > 0)');
        DB::statement('ALTER TABLE cashier_collections ADD CONSTRAINT cashier_collections_payer CHECK (payer_party_id IS NOT NULL OR payer_name IS NOT NULL)');
        DB::statement("ALTER TABLE cashier_collections ADD CONSTRAINT cashier_collections_cheque CHECK (method <> 'CHEQUE' OR cheque_number IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_collections');
        Schema::dropIfExists('cashier_sessions');
        DB::unprepared('DROP TRIGGER IF EXISTS fx_conversions_append_only ON fx_conversions; DROP TRIGGER IF EXISTS fx_rates_append_only ON fx_rates;');
        Schema::dropIfExists('fx_conversions');
        Schema::dropIfExists('fx_rates');
        DB::unprepared('DROP FUNCTION IF EXISTS fx_append_only()');
    }
};
