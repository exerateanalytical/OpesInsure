<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $t): void {
            $t->foreignUuid('assigned_to')->nullable()->constrained('users');
            $t->string('priority', 16)->default('NORMAL');
            $t->string('loss_location')->nullable();
            $t->bigInteger('current_reserve_minor')->default(0);
            $t->bigInteger('approved_amount_minor')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('reopened_at')->nullable();
            $t->foreignUuid('reopened_by')->nullable()->constrained('users');
            $t->text('closure_summary')->nullable();
            $t->index(['tenant_id', 'status', 'assigned_to']);
        });

        Schema::table('claim_documents', function (Blueprint $t): void {
            $t->uuid('id')->nullable()->unique();
            $t->string('evidence_hash', 64)->nullable();
            $t->foreignUuid('submitted_by')->nullable()->constrained('users');
            $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('verified_at')->nullable();
            $t->foreignUuid('verified_by')->nullable()->constrained('users');
            $t->text('rejection_reason')->nullable();
        });

        Schema::table('carrier_exchange_messages', function (Blueprint $t): void {
            $t->foreignUuid('claim_id')->nullable()->constrained('claims')->cascadeOnDelete();
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestampTz('next_attempt_at')->nullable();
            $t->index(['claim_id', 'status']);
        });

        Schema::create('claim_assignments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('assignee_id')->constrained('users');
            $t->foreignUuid('assigned_by')->constrained('users');
            $t->string('reason_code', 64);
            $t->timestampTz('assigned_at');
            $t->timestampTz('released_at')->nullable();
            $t->unique(['claim_id', 'assignee_id', 'assigned_at']);
        });

        Schema::create('claim_evidence_custody_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $t->string('event_type', 32);
            $t->foreignUuid('from_actor_id')->nullable()->constrained('users');
            $t->foreignUuid('to_actor_id')->nullable()->constrained('users');
            $t->string('content_hash', 64);
            $t->string('purpose', 96);
            $t->jsonb('metadata')->default('{}');
            $t->timestampTz('occurred_at');
            $t->index(['claim_id', 'document_id', 'occurred_at']);
        });

        Schema::create('claim_reserve_changes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $t->bigInteger('previous_amount_minor');
            $t->bigInteger('requested_amount_minor');
            $t->string('currency', 3);
            $t->string('status', 24)->default('PENDING_APPROVAL');
            $t->string('reason_code', 64);
            $t->text('notes')->nullable();
            $t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->index(['claim_id', 'status']);
        });

        Schema::create('claim_decisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $t->string('decision', 24);
            $t->bigInteger('approved_amount_minor')->default(0);
            $t->string('currency', 3);
            $t->string('reason_code', 64);
            $t->text('rationale');
            $t->jsonb('authority_snapshot')->default('{}');
            $t->string('status', 24)->default('PENDING_APPROVAL');
            $t->foreignUuid('proposed_by')->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->index(['claim_id', 'status']);
        });

        Schema::create('claim_disputes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 80)->unique();
            $t->string('status', 24)->default('OPEN');
            $t->string('reason_code', 64);
            $t->text('statement');
            $t->foreignUuid('opened_by')->constrained('users');
            $t->foreignUuid('resolved_by')->nullable()->constrained('users');
            $t->text('resolution')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestampsTz();
            $t->index(['claim_id', 'status']);
        });

        Schema::create('claim_payments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('claim_decision_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('payee_party_id')->constrained('parties');
            $t->bigInteger('amount_minor');
            $t->string('currency', 3);
            $t->string('status', 24)->default('PENDING_APPROVAL');
            $t->string('idempotency_key', 128);
            $t->string('external_reference')->nullable();
            $t->unsignedInteger('attempt_count')->default(0);
            $t->timestampTz('next_attempt_at')->nullable();
            $t->text('failure_reason')->nullable();
            $t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('paid_at')->nullable();
            $t->timestampTz('reversed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['claim_id', 'idempotency_key']);
            $t->index(['claim_id', 'status']);
        });

        Schema::create('claim_recoveries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $t->string('type', 24);
            $t->string('status', 24)->default('OPEN');
            $t->string('counterparty_name');
            $t->bigInteger('target_amount_minor');
            $t->bigInteger('recovered_amount_minor')->default(0);
            $t->string('currency', 3);
            $t->string('reference', 80)->unique();
            $t->text('notes')->nullable();
            $t->foreignUuid('opened_by')->constrained('users');
            $t->timestampTz('closed_at')->nullable();
            $t->timestampsTz();
        });

        Schema::create('claim_command_receipts', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('command_type', 64);
            $t->string('idempotency_key', 128);
            $t->uuid('aggregate_id');
            $t->string('request_hash', 64);
            $t->jsonb('response_snapshot');
            $t->timestampTz('created_at');
            $t->unique(['tenant_id', 'command_type', 'idempotency_key']);
        });

        DB::statement('ALTER TABLE claim_reserve_changes ADD CONSTRAINT claim_reserve_maker_checker CHECK (approved_by IS NULL OR approved_by <> requested_by)');
        DB::statement('ALTER TABLE claim_decisions ADD CONSTRAINT claim_decision_maker_checker CHECK (approved_by IS NULL OR approved_by <> proposed_by)');
        DB::statement('ALTER TABLE claim_payments ADD CONSTRAINT claim_payment_maker_checker CHECK (approved_by IS NULL OR approved_by <> requested_by)');
        DB::statement('ALTER TABLE claim_payments ADD CONSTRAINT claim_payment_positive CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE claim_recoveries ADD CONSTRAINT claim_recovery_amounts CHECK (target_amount_minor >= 0 AND recovered_amount_minor >= 0 AND recovered_amount_minor <= target_amount_minor)');
    }

    public function down(): void
    {
        foreach (['claim_command_receipts', 'claim_recoveries', 'claim_payments', 'claim_disputes', 'claim_decisions', 'claim_reserve_changes', 'claim_evidence_custody_events', 'claim_assignments'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('carrier_exchange_messages', function (Blueprint $t): void {
            $t->dropIndex(['claim_id', 'status']);
            $t->dropConstrainedForeignId('claim_id');
            $t->dropColumn(['attempt_count', 'next_attempt_at']);
        });
        Schema::table('claim_documents', function (Blueprint $t): void {
            $t->dropUnique(['id']);
            $t->dropConstrainedForeignId('submitted_by');
            $t->dropConstrainedForeignId('verified_by');
            $t->dropColumn(['id', 'evidence_hash', 'submitted_at', 'verified_at', 'rejection_reason']);
        });
        Schema::table('claims', function (Blueprint $t): void {
            $t->dropIndex(['tenant_id', 'status', 'assigned_to']);
            $t->dropConstrainedForeignId('assigned_to');
            $t->dropConstrainedForeignId('reopened_by');
            $t->dropColumn(['priority', 'loss_location', 'current_reserve_minor', 'approved_amount_minor', 'version', 'reopened_at', 'closure_summary']);
        });
    }
};
