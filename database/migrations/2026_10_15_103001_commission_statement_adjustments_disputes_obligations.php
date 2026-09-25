<?php

declare(strict_types=1);

use App\Application\Cases\CaseTypeCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Batch 10-3 — REQ-COM-003 commission statements, adjustments (maker-checker), disputes, payment.
 | Extends the Wave 6 tables only (no second statement / payout table):
 |  - partner_statements: adjustments_minor, revision, the PAYABLE/COMMISSION obligation, the dispute case link.
 |  - partner_statement_items: ADJUSTMENT lines carry proposal/decision (maker <> checker enforced by CHECK).
 |  - partner_payout_requests: the commission.paid journal.
 |  - case type COMMISSION_DISPUTE (generic lifecycle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_statements', function (Blueprint $t): void {
            $t->bigInteger('adjustments_minor')->default(0);
            $t->unsignedInteger('revision')->default(1);
            $t->uuid('obligation_id')->nullable();
            $t->uuid('dispute_case_id')->nullable();
            $t->text('dispute_reason')->nullable();
            $t->timestampTz('disputed_at')->nullable();
        });
        Schema::table('partner_statement_items', function (Blueprint $t): void {
            $t->string('adjustment_status', 16)->nullable();
            $t->text('reason')->nullable();
            $t->foreignUuid('proposed_by')->nullable()->constrained('users');
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
        });
        Schema::table('partner_payout_requests', function (Blueprint $t): void {
            $t->uuid('journal_id')->nullable();
        });
        DB::statement("ALTER TABLE partner_statement_items ADD CONSTRAINT partner_statement_adjustment_maker_checker CHECK (decided_by IS NULL OR decided_by <> proposed_by)");
        DB::statement("ALTER TABLE partner_statement_items ADD CONSTRAINT partner_statement_adjustment_status CHECK (adjustment_status IS NULL OR adjustment_status IN ('PROPOSED','APPROVED','REJECTED'))");

        if (DB::getSchemaBuilder()->hasTable('case_types') && ! DB::table('case_types')->where('code', 'COMMISSION_DISPUTE')->exists()) {
            $def = CaseTypeCatalogue::genericLifecycle(false);
            $family = DB::table('case_families')->where('code', 'FINANCE_EXCEPTION')->exists() ? 'FINANCE_EXCEPTION'
                : (DB::table('case_families')->where('code', 'OPERATIONS')->exists() ? 'OPERATIONS' : null);
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => 'COMMISSION_DISPUTE', 'version' => 1, 'family_code' => $family,
                'name' => 'Commission statement dispute', 'status' => 'EFFECTIVE', 'valid_from' => '2026-01-01', 'valid_to' => null,
                'states' => json_encode($def['states']), 'transitions' => json_encode($def['transitions']),
                'sla_policies' => '[]', 'auto_tasks' => '[]', 'default_confidentiality' => 'NORMAL', 'regulated' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('case_types')->where('code', 'COMMISSION_DISPUTE')->whereNotExists(fn ($q) => $q->from('cases')->whereColumn('cases.case_type_id', 'case_types.id'))->delete();
        DB::statement('ALTER TABLE partner_statement_items DROP CONSTRAINT IF EXISTS partner_statement_adjustment_maker_checker');
        DB::statement('ALTER TABLE partner_statement_items DROP CONSTRAINT IF EXISTS partner_statement_adjustment_status');
        Schema::table('partner_payout_requests', fn (Blueprint $t) => $t->dropColumn('journal_id'));
        Schema::table('partner_statement_items', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('proposed_by');
            $t->dropConstrainedForeignId('decided_by');
            $t->dropColumn(['adjustment_status', 'reason', 'decided_at']);
        });
        Schema::table('partner_statements', fn (Blueprint $t) => $t->dropColumn(['adjustments_minor', 'revision', 'obligation_id', 'dispute_case_id', 'dispute_reason', 'disputed_at']));
    }
};
