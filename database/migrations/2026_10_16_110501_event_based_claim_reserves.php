<?php

declare(strict_types=1);

use App\Application\Ledger\Posting\DefaultChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * REQ-CLM-008 (Batch 11 C5) — event-based reserves on the existing claim_reserve_changes table (no second table).
 *
 * Each row is a reserve movement for one head (INDEMNITY | EXPENSE) and optional coverage: stage INITIAL (first
 * approved reserve for that head/coverage), CURRENT (any later revision) or FINAL (closing reserve). movement_minor
 * is the signed delta once approved. Existing rows are backfilled as INDEMNITY/uncovered movements so
 * claims.current_reserve_minor (= sum of the latest approved per head/coverage) keeps its meaning.
 *
 * Also seeds the accounting event claim.reserve.changed (default mapping 601000 → 481500).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_reserve_changes', function (Blueprint $t): void {
            $t->string('reserve_head', 16)->default('INDEMNITY');
            $t->string('coverage_code', 64)->nullable();
            $t->string('reserve_stage', 16)->default('CURRENT');
            $t->bigInteger('movement_minor')->nullable();
            $t->integer('approval_seq')->nullable(); // order of approved movements within a claim
            $t->uuid('authority_check_id')->nullable();
            $t->uuid('referral_case_id')->nullable();
            $t->uuid('journal_id')->nullable();
            $t->string('limit_reservation_ref', 64)->nullable();
            $t->index(['claim_id', 'reserve_head', 'coverage_code', 'status']);
        });
        DB::statement("ALTER TABLE claim_reserve_changes ADD CONSTRAINT claim_reserve_head_valid CHECK (reserve_head IN ('INDEMNITY','EXPENSE'))");
        DB::statement("ALTER TABLE claim_reserve_changes ADD CONSTRAINT claim_reserve_stage_valid CHECK (reserve_stage IN ('INITIAL','CURRENT','FINAL'))");
        DB::statement("UPDATE claim_reserve_changes SET reserve_head = 'EXPENSE' WHERE reserve_type IN ('LEGAL','ADJUSTER')");
        DB::statement("UPDATE claim_reserve_changes SET movement_minor = requested_amount_minor - previous_amount_minor WHERE status = 'APPROVED'");
        DB::statement("UPDATE claim_reserve_changes SET reserve_stage = 'INITIAL' WHERE status = 'APPROVED' AND previous_amount_minor = 0");
        DB::statement("UPDATE claim_reserve_changes c SET approval_seq = s.n FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY claim_id ORDER BY approved_at, created_at, id) AS n FROM claim_reserve_changes WHERE status = 'APPROVED') s WHERE s.id = c.id");
        DB::statement('CREATE UNIQUE INDEX claim_reserve_changes_approval_seq_unique ON claim_reserve_changes (claim_id, approval_seq) WHERE approval_seq IS NOT NULL');

        $now = now();
        [$category, $debit, $credit, $description] = DefaultChartOfAccounts::EVENTS['claim.reserve.changed'];
        if (! DB::table('accounting_events')->where('code', 'claim.reserve.changed')->exists()) {
            DB::table('accounting_events')->insert(['code' => 'claim.reserve.changed', 'category' => $category, 'description' => $description, 'status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]);
        }
        if (! DB::table('accounting_event_mappings')->where('event_code', 'claim.reserve.changed')->whereNull('tenant_id')->exists()) {
            DB::table('accounting_event_mappings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'event_code' => 'claim.reserve.changed', 'version' => 1, 'debit_account_code' => $debit,
                'credit_account_code' => $credit, 'status' => 'ACTIVE', 'reason' => 'Default OHADA/CIMA chart', 'effective_from' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('accounting_event_mappings')->where('event_code', 'claim.reserve.changed')->delete();
        DB::table('accounting_events')->where('code', 'claim.reserve.changed')->delete();
        DB::statement('ALTER TABLE claim_reserve_changes DROP CONSTRAINT IF EXISTS claim_reserve_head_valid');
        DB::statement('ALTER TABLE claim_reserve_changes DROP CONSTRAINT IF EXISTS claim_reserve_stage_valid');
        DB::statement('DROP INDEX IF EXISTS claim_reserve_changes_approval_seq_unique');
        Schema::table('claim_reserve_changes', function (Blueprint $t): void {
            $t->dropIndex(['claim_id', 'reserve_head', 'coverage_code', 'status']);
            $t->dropColumn(['reserve_head', 'coverage_code', 'reserve_stage', 'movement_minor', 'approval_seq', 'authority_check_id', 'referral_case_id', 'journal_id', 'limit_reservation_ref']);
        });
    }
};
