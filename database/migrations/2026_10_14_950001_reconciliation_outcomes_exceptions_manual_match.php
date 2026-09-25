<?php

declare(strict_types=1);

use App\Application\Cases\CaseTypeCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Batch 9-5 — REQ-PAY-007 reconciliation outcomes, exceptions queue, unmatched-items workspace.
 |  - reconciliation_items gains outcome (MATCHED|PARTIAL|UNMATCHED|DUPLICATE|OVER|UNDER), expected/variance,
 |    the exception case link and the refund-candidate flag (WF-026/027/086 duplicate payments).
 |  - reconciliation_manual_matches: maker-checker manual match requests.
 |  - case type RECONCILIATION_EXCEPTION (case engine exceptions queue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_items', function (Blueprint $t) {
            $t->string('outcome', 16)->nullable();
            $t->bigInteger('expected_minor')->nullable();
            $t->bigInteger('variance_minor')->nullable();
            $t->uuid('case_id')->nullable();
            $t->boolean('refund_candidate')->default(false);
            $t->string('refund_reason', 40)->nullable();
            $t->index(['outcome', 'status']);
            $t->index(['matched_type', 'matched_id']);
        });
        DB::statement("ALTER TABLE reconciliation_items ADD CONSTRAINT reconciliation_items_outcome CHECK (outcome IS NULL OR outcome IN ('MATCHED','PARTIAL','UNMATCHED','DUPLICATE','OVER','UNDER'))");

        Schema::create('reconciliation_manual_matches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->nullable()->index();
            $t->foreignUuid('reconciliation_item_id')->constrained()->cascadeOnDelete();
            $t->string('matched_type', 64);
            $t->uuid('matched_id');
            $t->text('notes');
            $t->string('status', 16)->default('PENDING');
            $t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE reconciliation_manual_matches ADD CONSTRAINT reconciliation_manual_matches_status CHECK (status IN ('PENDING','APPROVED','REJECTED'))");
        DB::statement('ALTER TABLE reconciliation_manual_matches ADD CONSTRAINT reconciliation_manual_matches_separation CHECK (decided_by IS NULL OR decided_by <> requested_by)');
        DB::statement("CREATE UNIQUE INDEX reconciliation_manual_matches_one_pending ON reconciliation_manual_matches (reconciliation_item_id) WHERE status = 'PENDING'");

        if (! DB::table('case_types')->where('code', 'RECONCILIATION_EXCEPTION')->exists()) {
            $def = CaseTypeCatalogue::genericLifecycle(false);
            $family = DB::table('case_families')->where('code', 'FINANCE_EXCEPTION')->exists() ? 'FINANCE_EXCEPTION'
                : (DB::table('case_families')->where('code', 'OPERATIONS')->exists() ? 'OPERATIONS' : null);
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => 'RECONCILIATION_EXCEPTION', 'version' => 1, 'family_code' => $family,
                'name' => 'Payment reconciliation exception', 'status' => 'EFFECTIVE', 'valid_from' => '2026-01-01', 'valid_to' => null,
                'states' => json_encode($def['states']), 'transitions' => json_encode($def['transitions']),
                'sla_policies' => '[]', 'auto_tasks' => '[]', 'default_confidentiality' => 'NORMAL', 'regulated' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('case_types')->where('code', 'RECONCILIATION_EXCEPTION')->whereNotExists(fn ($q) => $q->from('cases')->whereColumn('cases.case_type_id', 'case_types.id'))->delete();
        Schema::dropIfExists('reconciliation_manual_matches');
        DB::statement('ALTER TABLE reconciliation_items DROP CONSTRAINT IF EXISTS reconciliation_items_outcome');
        Schema::table('reconciliation_items', function (Blueprint $t) {
            $t->dropIndex(['outcome', 'status']);
            $t->dropIndex(['matched_type', 'matched_id']);
            $t->dropColumn(['outcome', 'expected_minor', 'variance_minor', 'case_id', 'refund_candidate', 'refund_reason']);
        });
    }
};
