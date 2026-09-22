<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('partner_statements', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->foreignUuid('partner_id')->constrained();
            $t->string('statement_number', 80)->unique(); $t->date('period_start'); $t->date('period_end'); $t->string('currency', 3);
            $t->string('status', 32)->default('DRAFT'); $t->bigInteger('opening_balance_minor')->default(0); $t->bigInteger('earned_minor')->default(0);
            $t->bigInteger('clawed_back_minor')->default(0); $t->bigInteger('paid_minor')->default(0); $t->bigInteger('closing_balance_minor')->default(0);
            $t->string('content_hash', 64); $t->string('idempotency_key', 100); $t->foreignUuid('prepared_by')->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users'); $t->timestampTz('approved_at')->nullable(); $t->timestampTz('published_at')->nullable(); $t->timestampsTz();
            $t->unique(['tenant_id', 'idempotency_key']); $t->unique(['tenant_id', 'partner_id', 'period_start', 'period_end', 'currency'], 'partner_statement_period_unique');
        });
        Schema::create('partner_statement_items', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->foreignUuid('partner_statement_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('commission_accrual_id')->nullable()->constrained(); $t->string('entry_type', 32); $t->string('reference_type', 64); $t->uuid('reference_id');
            $t->bigInteger('amount_minor'); $t->string('currency', 3); $t->dateTimeTz('occurred_at'); $t->jsonb('metadata')->default('{}');
            $t->unique(['partner_statement_id', 'reference_type', 'reference_id', 'entry_type'], 'partner_statement_item_unique');
        });
        Schema::create('partner_payout_attempts', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->foreignUuid('partner_payout_request_id')->constrained()->cascadeOnDelete(); $t->unsignedSmallInteger('attempt_number');
            $t->string('provider', 48); $t->string('provider_reference')->nullable(); $t->string('status', 32); $t->string('request_hash', 64);
            $t->string('failure_code', 80)->nullable(); $t->text('failure_message')->nullable(); $t->timestampTz('attempted_at'); $t->timestampsTz();
            $t->unique(['partner_payout_request_id', 'attempt_number']); $t->unique(['provider', 'provider_reference']);
        });
        Schema::create('financial_distribution_events', function (Blueprint $t): void {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->nullable()->constrained(); $t->string('aggregate_type', 64); $t->uuid('aggregate_id');
            $t->string('from_status', 32)->nullable(); $t->string('to_status', 32); $t->string('reason_code', 80); $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->jsonb('metadata')->default('{}'); $t->timestampTz('occurred_at'); $t->index(['aggregate_type', 'aggregate_id']);
        });
        Schema::table('commission_rule_versions', function (Blueprint $t): void {
            $t->foreignUuid('tenant_id')->nullable()->after('id')->constrained(); $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users'); $t->timestampTz('approved_at')->nullable();
        });
        Schema::table('commission_accruals', function (Blueprint $t): void {
            $t->foreignUuid('tenant_id')->nullable()->after('id')->constrained(); $t->string('source_type', 64)->default('POLICY'); $t->uuid('source_id')->nullable();
            $t->string('idempotency_key', 100)->nullable(); $t->timestampTz('available_at')->nullable();
            $t->unique(['tenant_id', 'idempotency_key']);
        });
        Schema::table('partner_payout_requests', function (Blueprint $t): void {
            $t->foreignUuid('tenant_id')->nullable()->after('id')->constrained(); $t->string('idempotency_key', 100)->nullable();
            $t->foreignUuid('partner_statement_id')->nullable()->constrained(); $t->text('rejection_reason')->nullable(); $t->string('failure_code', 80)->nullable();
            $t->timestampTz('processing_at')->nullable(); $t->foreignUuid('reversed_by')->nullable()->constrained('partner_payout_requests');
            $t->unique(['tenant_id', 'idempotency_key']);
        });
        Schema::table('settlement_batches', function (Blueprint $t): void {
            $t->foreignUuid('tenant_id')->nullable()->after('id')->constrained(); $t->string('settlement_number', 80)->nullable()->unique();
            $t->string('idempotency_key', 100)->nullable(); $t->string('content_hash', 64)->nullable(); $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('paid_at')->nullable(); $t->string('bank_reference', 120)->nullable(); $t->text('failure_reason')->nullable();
            $t->foreignUuid('reversed_by')->nullable()->constrained('settlement_batches'); $t->unique(['tenant_id', 'idempotency_key']);
        });
        Schema::table('bordereaux', function (Blueprint $t): void {
            $t->string('content_hash', 64)->nullable(); $t->string('idempotency_key', 100)->nullable(); $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable(); $t->timestampTz('acknowledged_at')->nullable(); $t->text('rejection_reason')->nullable();
            $t->unique(['tenant_id', 'idempotency_key']);
        });
        DB::statement("ALTER TABLE partner_statements ADD CONSTRAINT partner_statement_period_valid CHECK (period_end >= period_start)");
        DB::statement("ALTER TABLE partner_statements ADD CONSTRAINT partner_statement_maker_checker CHECK (approved_by IS NULL OR approved_by <> prepared_by)");
        DB::statement("ALTER TABLE commission_rule_versions ADD CONSTRAINT commission_rule_maker_checker CHECK (approved_by IS NULL OR approved_by <> created_by)");
        DB::statement("ALTER TABLE partner_payout_requests ADD CONSTRAINT payout_maker_checker CHECK (approved_by IS NULL OR approved_by <> requested_by)");
        DB::statement("ALTER TABLE bordereaux ADD CONSTRAINT bordereau_maker_checker CHECK (approved_by IS NULL OR approved_by <> prepared_by)");
    }

    public function down(): void
    {
        foreach (['bordereau_maker_checker', 'payout_maker_checker', 'commission_rule_maker_checker'] as $constraint) {
            $table = $constraint === 'bordereau_maker_checker' ? 'bordereaux' : ($constraint === 'payout_maker_checker' ? 'partner_payout_requests' : 'commission_rule_versions');
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        }
        Schema::table('bordereaux', function (Blueprint $t): void { $t->dropConstrainedForeignId('approved_by'); $t->dropUnique(['tenant_id','idempotency_key']); $t->dropColumn(['content_hash','idempotency_key','approved_at','acknowledged_at','rejection_reason']); });
        Schema::table('settlement_batches', function (Blueprint $t): void { $t->dropUnique(['settlement_number']); $t->dropUnique(['tenant_id','idempotency_key']); $t->dropConstrainedForeignId('tenant_id'); $t->dropConstrainedForeignId('reversed_by'); $t->dropColumn(['settlement_number','idempotency_key','content_hash','submitted_at','paid_at','bank_reference','failure_reason']); });
        Schema::table('partner_payout_requests', function (Blueprint $t): void { $t->dropUnique(['tenant_id','idempotency_key']); $t->dropConstrainedForeignId('tenant_id'); $t->dropConstrainedForeignId('partner_statement_id'); $t->dropConstrainedForeignId('reversed_by'); $t->dropColumn(['idempotency_key','rejection_reason','failure_code','processing_at']); });
        Schema::table('commission_accruals', function (Blueprint $t): void { $t->dropUnique(['tenant_id','idempotency_key']); $t->dropConstrainedForeignId('tenant_id'); $t->dropColumn(['source_type','source_id','idempotency_key','available_at']); });
        Schema::table('commission_rule_versions', function (Blueprint $t): void { $t->dropConstrainedForeignId('tenant_id'); $t->dropConstrainedForeignId('created_by'); $t->dropConstrainedForeignId('approved_by'); $t->dropColumn('approved_at'); });
        DB::statement('ALTER TABLE partner_statements DROP CONSTRAINT IF EXISTS partner_statement_period_valid');
        DB::statement('ALTER TABLE partner_statements DROP CONSTRAINT IF EXISTS partner_statement_maker_checker');
        Schema::dropIfExists('financial_distribution_events'); Schema::dropIfExists('partner_payout_attempts'); Schema::dropIfExists('partner_statement_items'); Schema::dropIfExists('partner_statements');
    }
};
