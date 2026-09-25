<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-CLM-014 (agent C14) — claim closure + reopening (WF-060/061).
 *  - claim_closures: one append-only row per closure (reason, checklist snapshot, manual/auto). Never updated.
 *  - claim_reopen_requests: maker-checker reopening with reason, authority snapshot and the reserve
 *    restored at reopen (as a NEW claim_reserve_changes movement — history is never edited).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_closures', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $t->string('reason_code', 48);
            $t->text('summary')->nullable();
            $t->jsonb('checklist')->default('[]');
            $t->boolean('automatic')->default(false);
            $t->string('from_status', 32);
            $t->foreignUuid('closed_by')->nullable()->constrained('users');
            $t->timestampTz('closed_at');
            $t->timestampsTz();
            $t->index(['claim_id', 'closed_at']);
        });

        Schema::create('claim_reopen_requests', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('claim_closure_id')->nullable()->constrained('claim_closures');
            $t->string('reason_code', 48);
            $t->text('justification');
            $t->bigInteger('restore_reserve_minor')->default(0);
            $t->string('status', 24)->default('PENDING_APPROVAL');
            $t->jsonb('authority_snapshot')->default('{}');
            $t->foreignUuid('reserve_change_id')->nullable()->constrained('claim_reserve_changes');
            $t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestampsTz();
            $t->index(['claim_id', 'status']);
        });
        DB::statement('ALTER TABLE claim_reopen_requests ADD CONSTRAINT claim_reopen_maker_checker CHECK (decided_by IS NULL OR decided_by <> requested_by)');
        DB::statement("ALTER TABLE claim_reopen_requests ADD CONSTRAINT claim_reopen_status_allowed CHECK (status IN ('PENDING_APPROVAL','APPROVED','REJECTED'))");
        DB::statement('ALTER TABLE claim_reopen_requests ADD CONSTRAINT claim_reopen_restore_non_negative CHECK (restore_reserve_minor >= 0)');
        DB::statement("CREATE UNIQUE INDEX claim_reopen_one_pending ON claim_reopen_requests (claim_id) WHERE status = 'PENDING_APPROVAL'");
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_reopen_requests');
        Schema::dropIfExists('claim_closures');
    }
};
