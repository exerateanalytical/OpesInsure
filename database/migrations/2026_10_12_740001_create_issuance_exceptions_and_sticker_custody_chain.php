<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 7D — issuance operations.
 *
 * REQ-POL-004: issuance_exceptions is the failed / paid-not-issued queue (WF-028/029/084). A reconciled payment
 * whose automatic issuance request could not be opened (or that sat without a policy past the SLA) gets one OPEN
 * row per proposal; ops list / retry / escalate / resolve it. issuance_exception_events is its append-only trail.
 *
 * REQ-POL-007: the motor sticker custody chain carrier → broker → branch → agent → policy. sticker_stock gains
 * the custody level and the branch / agent custodian; sticker_handovers (+ items) move stickers one level with
 * receiver acknowledgement; sticker_reconciliations record physical counts against the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_exceptions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('proposal_id')->constrained();
            $t->foreignUuid('payment_intent_id')->constrained('payment_intents');
            $t->foreignUuid('policy_issuance_request_id')->nullable()->constrained('policy_issuance_requests');
            $t->string('kind', 32);            // ISSUANCE_REQUEST_FAILED | ISSUANCE_BLOCKED | PAID_NOT_ISSUED
            $t->string('status', 24)->default('OPEN'); // OPEN | ESCALATED | RESOLVED
            $t->string('reason_code', 64);
            $t->jsonb('blockers')->default('[]');
            $t->text('error_message')->nullable();
            $t->string('territory', 32)->nullable();
            $t->jsonb('premium_cover')->nullable();
            $t->unsignedInteger('attempts')->default(1);
            $t->timestampTz('last_attempt_at')->nullable();
            $t->foreignUuid('escalated_to')->nullable()->constrained('users');
            $t->timestampTz('escalated_at')->nullable();
            $t->string('resolution', 32)->nullable(); // ISSUANCE_REQUESTED | POLICY_ISSUED | REFUND_REQUESTED | RESOLVED_MANUALLY
            $t->text('resolution_notes')->nullable();
            $t->foreignUuid('resolved_by')->nullable()->constrained('users');
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampTz('customer_notified_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE issuance_exceptions ADD CONSTRAINT issuance_exception_status_allowed CHECK (status IN ('OPEN','ESCALATED','RESOLVED'))");
        DB::statement("CREATE UNIQUE INDEX issuance_exceptions_one_open_per_proposal ON issuance_exceptions (proposal_id) WHERE status <> 'RESOLVED'");

        Schema::create('issuance_exception_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('issuance_exception_id')->constrained()->cascadeOnDelete();
            $t->string('action', 32);
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->jsonb('metadata')->default('{}');
            $t->timestampTz('occurred_at');
        });

        Schema::table('sticker_stock', function (Blueprint $t): void {
            $t->string('custody_level', 16)->default('CARRIER');
            $t->foreignUuid('custodian_branch_id')->nullable()->constrained('tenant_branches');
            $t->foreignUuid('custodian_user_id')->nullable()->constrained('users');
            $t->index(['carrier_id', 'custody_level', 'status']);
        });
        DB::statement("UPDATE sticker_stock SET custody_level = CASE WHEN status = 'ASSIGNED' THEN 'POLICY' WHEN custodian_tenant_id IS NOT NULL THEN 'BROKER' ELSE 'CARRIER' END");
        DB::statement("ALTER TABLE sticker_stock ADD CONSTRAINT sticker_custody_level_allowed CHECK (custody_level IN ('CARRIER','BROKER','BRANCH','AGENT','POLICY'))");

        Schema::table('sticker_custody_events', function (Blueprint $t): void {
            $t->string('from_level', 16)->nullable();
            $t->string('to_level', 16)->nullable();
            $t->foreignUuid('from_branch_id')->nullable()->constrained('tenant_branches');
            $t->foreignUuid('to_branch_id')->nullable()->constrained('tenant_branches');
            $t->foreignUuid('from_user_id')->nullable()->constrained('users');
            $t->foreignUuid('to_user_id')->nullable()->constrained('users');
            $t->foreignUuid('policy_id')->nullable()->constrained('policies');
            $t->uuid('sticker_handover_id')->nullable()->index();
            $t->uuid('sticker_reconciliation_id')->nullable()->index();
        });

        Schema::create('sticker_handovers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->constrained();
            $t->string('from_level', 16);
            $t->foreignUuid('from_tenant_id')->nullable()->constrained('tenants');
            $t->foreignUuid('from_branch_id')->nullable()->constrained('tenant_branches');
            $t->foreignUuid('from_user_id')->nullable()->constrained('users');
            $t->string('to_level', 16);
            $t->foreignUuid('to_tenant_id')->nullable()->constrained('tenants');
            $t->foreignUuid('to_branch_id')->nullable()->constrained('tenant_branches');
            $t->foreignUuid('to_user_id')->nullable()->constrained('users');
            $t->string('direction', 8); // ALLOCATE (down the chain) | RETURN (up the chain)
            $t->string('status', 16)->default('PENDING'); // PENDING | ACCEPTED | REJECTED | CANCELLED
            $t->unsignedInteger('quantity');
            $t->text('notes')->nullable();
            $t->foreignUuid('initiated_by')->constrained('users');
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE sticker_handovers ADD CONSTRAINT sticker_handover_status_allowed CHECK (status IN ('PENDING','ACCEPTED','REJECTED','CANCELLED'))");
        DB::statement("ALTER TABLE sticker_handovers ADD CONSTRAINT sticker_handover_sod CHECK (status <> 'ACCEPTED' OR decided_by <> initiated_by)");

        Schema::create('sticker_handover_items', function (Blueprint $t): void {
            $t->foreignUuid('sticker_handover_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('sticker_stock_id')->constrained('sticker_stock');
            $t->primary(['sticker_handover_id', 'sticker_stock_id']);
        });

        Schema::create('sticker_reconciliations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->constrained();
            $t->string('level', 16);
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->foreignUuid('branch_id')->nullable()->constrained('tenant_branches');
            $t->foreignUuid('user_id')->nullable()->constrained('users');
            $t->unsignedInteger('expected_count');
            $t->unsignedInteger('counted_count');
            $t->jsonb('missing_serials')->default('[]');
            $t->jsonb('unexpected_serials')->default('[]');
            $t->jsonb('damaged_serials')->default('[]');
            $t->string('status', 16); // BALANCED | DISCREPANCY
            $t->text('notes')->nullable();
            $t->foreignUuid('performed_by')->constrained('users');
            $t->timestampTz('performed_at');
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sticker_reconciliations');
        Schema::dropIfExists('sticker_handover_items');
        Schema::dropIfExists('sticker_handovers');
        Schema::table('sticker_custody_events', function (Blueprint $t): void {
            foreach (['from_branch_id', 'to_branch_id', 'from_user_id', 'to_user_id', 'policy_id'] as $c) {
                $t->dropConstrainedForeignId($c);
            }
            $t->dropColumn(['from_level', 'to_level', 'sticker_handover_id', 'sticker_reconciliation_id']);
        });
        DB::statement('ALTER TABLE sticker_stock DROP CONSTRAINT IF EXISTS sticker_custody_level_allowed');
        Schema::table('sticker_stock', function (Blueprint $t): void {
            $t->dropIndex(['carrier_id', 'custody_level', 'status']);
            $t->dropConstrainedForeignId('custodian_branch_id');
            $t->dropConstrainedForeignId('custodian_user_id');
            $t->dropColumn('custody_level');
        });
        Schema::dropIfExists('issuance_exception_events');
        Schema::dropIfExists('issuance_exceptions');
    }
};
