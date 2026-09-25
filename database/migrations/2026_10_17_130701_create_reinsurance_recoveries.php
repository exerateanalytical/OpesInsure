<?php

declare(strict_types=1);

use App\Application\Ledger\Posting\DefaultChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Batch 14C / agent E7 — REQ-REI-004 reinsurance recoveries.
 | One recovery per (claim, recovery source = treaty version or facultative placement), split per reinsurer.
 | Lifecycle ESTIMATED -> NOTIFIED -> AGREED -> BILLED -> SETTLED -> CLOSED (+ DISPUTED).
 | Large-loss threshold is per treaty and has NO default (null = no large-loss notification).
 | Accounting events reinsurance.recovery.billed / .settled get platform default mappings.
 */
return new class extends Migration
{
    private const EVENTS = ['reinsurance.recovery.billed', 'reinsurance.recovery.settled'];

    public function up(): void
    {
        Schema::table('reinsurance_treaties', fn (Blueprint $t) => $t->bigInteger('large_loss_threshold_minor')->nullable());

        Schema::create('reinsurance_recoveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('claim_id')->constrained();
            $t->foreignUuid('policy_id')->constrained();
            $t->string('source_type', 16);                 // TREATY | FACULTATIVE
            $t->string('source_key', 64);                  // treaty_version_id or facultative placement id
            $t->uuid('treaty_id')->nullable();
            $t->uuid('treaty_version_id')->nullable();
            $t->uuid('cession_id')->nullable();
            $t->string('treaty_type', 32);
            $t->string('currency', 3);
            $t->bigInteger('gross_incurred_minor');        // claim paid + outstanding reserve
            $t->bigInteger('gross_paid_minor');
            $t->bigInteger('subject_loss_minor');          // loss presented to this source (after prior cessions)
            $t->bigInteger('recoverable_minor');           // on incurred basis
            $t->bigInteger('recoverable_paid_minor');      // on paid basis
            $t->bigInteger('agreed_minor')->nullable();
            $t->bigInteger('billed_minor')->default(0);
            $t->bigInteger('settled_minor')->default(0);
            $t->bigInteger('reinstatement_premium_minor')->default(0);
            $t->jsonb('layers')->default('[]');
            $t->jsonb('basis')->default('{}');
            $t->string('status', 16)->default('ESTIMATED');
            $t->string('status_before_dispute', 16)->nullable();
            $t->timestampTz('large_loss_notified_at')->nullable();
            $t->timestampTz('notified_at')->nullable();
            $t->timestampTz('agreed_at')->nullable();
            $t->uuid('agreed_by')->nullable();
            $t->timestampTz('billed_at')->nullable();
            $t->timestampTz('settled_at')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->text('dispute_reason')->nullable();
            $t->text('close_reason')->nullable();
            $t->uuid('billed_journal_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['claim_id', 'source_key']);
            $t->index(['tenant_id', 'status']);
            $t->index(['treaty_version_id', 'status']);
        });

        Schema::create('reinsurance_recovery_shares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('recovery_id')->constrained('reinsurance_recoveries')->cascadeOnDelete();
            $t->foreignUuid('reinsurer_id')->constrained('reinsurers');
            $t->decimal('share_percent', 9, 4);
            $t->bigInteger('recoverable_minor');
            $t->bigInteger('billed_minor')->default(0);
            $t->bigInteger('settled_minor')->default(0);
            $t->bigInteger('reinstatement_premium_minor')->default(0);
            $t->uuid('financial_obligation_id')->nullable();
            $t->uuid('reinstatement_obligation_id')->nullable();
            $t->timestampsTz();
            $t->unique(['recovery_id', 'reinsurer_id']);
        });

        Schema::create('reinsurance_recovery_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('recovery_id')->constrained('reinsurance_recoveries')->cascadeOnDelete();
            $t->string('event_type', 32);
            $t->string('from_status', 16)->nullable();
            $t->string('to_status', 16);
            $t->bigInteger('amount_minor')->default(0);
            $t->uuid('reinsurer_id')->nullable();
            $t->string('reference', 128)->nullable();
            $t->uuid('journal_id')->nullable();
            $t->uuid('actor_id')->nullable();
            $t->jsonb('metadata')->nullable();
            $t->timestampTz('occurred_at');
            $t->index(['recovery_id', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE reinsurance_recoveries ADD CONSTRAINT rei_recovery_status_check CHECK (status IN ('ESTIMATED','NOTIFIED','AGREED','BILLED','SETTLED','CLOSED','DISPUTED'))");
            DB::statement("ALTER TABLE reinsurance_recoveries ADD CONSTRAINT rei_recovery_source_check CHECK (source_type IN ('TREATY','FACULTATIVE'))");
            DB::statement('ALTER TABLE reinsurance_recoveries ADD CONSTRAINT rei_recovery_amounts_check CHECK (recoverable_minor >= 0 AND billed_minor >= 0 AND settled_minor >= 0 AND settled_minor <= billed_minor)');
            DB::statement('ALTER TABLE reinsurance_treaties ADD CONSTRAINT rei_large_loss_threshold_check CHECK (large_loss_threshold_minor IS NULL OR large_loss_threshold_minor > 0)');
            DB::statement("CREATE UNIQUE INDEX rei_recovery_event_reference ON reinsurance_recovery_events (recovery_id, event_type, reinsurer_id, reference) WHERE reference IS NOT NULL");
        }

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
        Schema::dropIfExists('reinsurance_recovery_events');
        Schema::dropIfExists('reinsurance_recovery_shares');
        Schema::dropIfExists('reinsurance_recoveries');
        DB::statement('ALTER TABLE reinsurance_treaties DROP CONSTRAINT IF EXISTS rei_large_loss_threshold_check');
        Schema::table('reinsurance_treaties', fn (Blueprint $t) => $t->dropColumn('large_loss_threshold_minor'));
    }
};
