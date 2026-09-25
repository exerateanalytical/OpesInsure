<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 8 — REQ-REN-001 renewal machine (WF-039..043, WF-087).
 *
 * renewal_cases gains the reached reminder window (90/60/30/15/7), the policy version it was re-rated on, the open
 * issuance exception when a paid renewal failed to issue, and a closing reason. renewal_case_events is the
 * append-only trail; one WINDOW_REACHED row per (case, window) makes the daily sweep idempotent.
 * issuance_exceptions.renewal_case_id tags a paid-renewal issuance failure in the Batch 7D queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('renewal_cases', function (Blueprint $t): void {
            $t->unsignedSmallInteger('window_days')->nullable();
            $t->foreignUuid('rated_policy_version_id')->nullable()->constrained('policy_versions');
            $t->foreignUuid('issuance_exception_id')->nullable()->constrained('issuance_exceptions');
            $t->string('closed_reason', 64)->nullable();
            $t->index(['tenant_id', 'status', 'due_on']);
        });
        DB::statement("ALTER TABLE renewal_cases ADD CONSTRAINT renewal_case_status_allowed CHECK (status IN ('DUE','CONTACTED','QUOTED','ISSUANCE_FAILED','RENEWED','LAPSED','DECLINED')) NOT VALID");

        Schema::create('renewal_case_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('renewal_case_id')->constrained()->cascadeOnDelete();
            $t->string('action', 32);
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->unsignedSmallInteger('window_days')->nullable();
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->jsonb('metadata')->default('{}');
            $t->timestampTz('occurred_at');
            $t->index(['renewal_case_id', 'occurred_at']);
        });
        DB::statement("CREATE UNIQUE INDEX renewal_case_events_one_window ON renewal_case_events (renewal_case_id, window_days) WHERE action = 'WINDOW_REACHED'");

        Schema::table('issuance_exceptions', function (Blueprint $t): void {
            $t->foreignUuid('renewal_case_id')->nullable()->constrained('renewal_cases');
        });
    }

    public function down(): void
    {
        Schema::table('issuance_exceptions', fn (Blueprint $t) => $t->dropConstrainedForeignId('renewal_case_id'));
        Schema::dropIfExists('renewal_case_events');
        DB::statement('ALTER TABLE renewal_cases DROP CONSTRAINT IF EXISTS renewal_case_status_allowed');
        Schema::table('renewal_cases', function (Blueprint $t): void {
            $t->dropIndex(['tenant_id', 'status', 'due_on']);
            $t->dropConstrainedForeignId('rated_policy_version_id');
            $t->dropConstrainedForeignId('issuance_exception_id');
            $t->dropColumn(['window_days', 'closed_reason']);
        });
    }
};
