<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Batch 14C — REQ-REI-003 facultative placements (App\Application\Reinsurance\Facultative).
 | A placement is a slip (risk, sum insured, premium, terms, period) for one policy, with participants carrying
 | offered / written / signed lines (percent of the placed share; signed lines total exactly 100%).
 | Binding writes ONE row into the existing reinsurance_cessions table (source = FACULTATIVE, no treaty) so a
 | policy's cession totals are treaty + facultative; no second cession table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facultative_placements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained();
            $t->string('reference', 64);
            $t->string('status', 16)->default('DRAFT');   // DRAFT | SUBMITTED | BOUND | REJECTED | CANCELLED
            $t->string('risk_description', 1000);
            $t->string('currency', 3);
            $t->bigInteger('sum_insured_minor');             // 100% of the risk
            $t->bigInteger('premium_minor');                 // 100% original gross premium of the risk
            $t->decimal('placed_share_percent', 9, 4);       // share of the risk placed facultatively
            $t->decimal('commission_percent', 9, 4)->default(0);
            $t->decimal('brokerage_percent', 9, 4)->default(0);
            $t->decimal('tax_percent', 9, 4)->default(0);
            $t->jsonb('terms')->default('{}');              // free slip conditions (deductibles, clauses, ...)
            $t->date('period_from');
            $t->date('period_to');
            $t->foreignUuid('broker_id')->nullable()->constrained('reinsurers');
            $t->uuid('created_by')->nullable();
            $t->uuid('submitted_by')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_reason', 500)->nullable();
            $t->uuid('authority_check_id')->nullable();
            $t->uuid('journal_id')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'reference']);
            $t->index(['tenant_id', 'policy_id', 'status']);
        });

        Schema::create('facultative_participants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('placement_id')->constrained('facultative_placements')->cascadeOnDelete();
            $t->foreignUuid('reinsurer_id')->constrained('reinsurers');
            $t->decimal('offered_percent', 9, 4);
            $t->decimal('written_percent', 9, 4)->nullable();
            $t->decimal('signed_percent', 9, 4)->nullable();
            $t->boolean('is_lead')->default(false);
            $t->timestampsTz();
            $t->unique(['placement_id', 'reinsurer_id']);
        });

        Schema::table('reinsurance_cessions', function (Blueprint $t) {
            $t->string('source', 16)->default('TREATY');     // TREATY | FACULTATIVE
            $t->foreignUuid('facultative_placement_id')->nullable()->constrained('facultative_placements');
            $t->uuid('treaty_id')->nullable()->change();
            $t->uuid('treaty_version_id')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE facultative_placements ADD CONSTRAINT fac_status_check CHECK (status IN ('DRAFT','SUBMITTED','BOUND','REJECTED','CANCELLED'))");
            DB::statement('ALTER TABLE facultative_placements ADD CONSTRAINT fac_share_check CHECK (placed_share_percent > 0 AND placed_share_percent <= 100)');
            DB::statement('ALTER TABLE facultative_placements ADD CONSTRAINT fac_period_check CHECK (period_to >= period_from)');
            DB::statement('ALTER TABLE facultative_placements ADD CONSTRAINT fac_maker_checker CHECK (decided_by IS NULL OR created_by IS NULL OR decided_by <> created_by)');
            DB::statement('ALTER TABLE facultative_participants ADD CONSTRAINT fac_lines_check CHECK (offered_percent > 0 AND offered_percent <= 100 AND (written_percent IS NULL OR (written_percent >= 0 AND written_percent <= 100)) AND (signed_percent IS NULL OR (signed_percent >= 0 AND signed_percent <= 100)))');
            DB::statement("ALTER TABLE reinsurance_cessions ADD CONSTRAINT rei_cession_source_check CHECK ((source = 'TREATY' AND treaty_id IS NOT NULL AND treaty_version_id IS NOT NULL) OR (source = 'FACULTATIVE' AND facultative_placement_id IS NOT NULL))");
            DB::statement("CREATE UNIQUE INDEX rei_cession_one_per_fac ON reinsurance_cessions (facultative_placement_id) WHERE source = 'FACULTATIVE'");
        }

        // reinsurance.facultative.bound default mapping (DefaultChartOfAccounts::EVENTS): Dr Reinsurance ceded premium / Cr Reinsurance premium payable.
        $now = now();
        if (! DB::table('accounting_events')->where('code', 'reinsurance.facultative.bound')->exists()) {
            DB::table('accounting_events')->insert(['code' => 'reinsurance.facultative.bound', 'category' => 'REINSURANCE', 'description' => 'Facultative premium ceded on binding.', 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('accounting_event_mappings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'event_code' => 'reinsurance.facultative.bound', 'version' => 1, 'debit_account_code' => '602000',
                'credit_account_code' => '401200', 'status' => 'ACTIVE', 'reason' => 'Default OHADA/CIMA chart', 'effective_from' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('accounting_event_mappings')->whereNull('tenant_id')->where('event_code', 'reinsurance.facultative.bound')->delete();
        DB::table('accounting_events')->where('code', 'reinsurance.facultative.bound')->delete();
        DB::table('reinsurance_cessions')->where('source', 'FACULTATIVE')->delete();
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS rei_cession_one_per_fac');
            DB::statement('ALTER TABLE reinsurance_cessions DROP CONSTRAINT IF EXISTS rei_cession_source_check');
        }
        Schema::table('reinsurance_cessions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('facultative_placement_id');
            $t->dropColumn('source');
            $t->uuid('treaty_id')->nullable(false)->change();
            $t->uuid('treaty_version_id')->nullable(false)->change();
        });
        Schema::dropIfExists('facultative_participants');
        Schema::dropIfExists('facultative_placements');
    }
};
