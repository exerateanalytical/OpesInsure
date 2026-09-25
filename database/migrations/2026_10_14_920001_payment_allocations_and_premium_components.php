<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 9-2 — REQ-PAY-004 (payment allocations + versioned allocation-order rule) and
 * REQ-PAY-005 (premium components + premium status separate from payment status).
 *
 *  allocation_rule_versions   per-tenant versioned allocation order (strategy + component priority).
 *  premium_components         premium breakdown lines per policy (snapshotted, never edited in amount).
 *  payment_allocation_runs    one idempotent allocation of a payment (idempotency_key per tenant).
 *  payment_allocations        many-to-many payment ↔ component / financial obligation; append-only,
 *                             reversals are negative rows pointing at the row they reverse.
 * financial_obligation_id is a plain uuid (no FK): financial_obligations is owned by agent 9-1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocation_rule_versions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->unsignedInteger('version');
            $t->string('strategy', 24);
            $t->jsonb('priority');
            $t->string('status', 16)->default('ACTIVE');
            $t->timestampTz('effective_from');
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->string('reason')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'version']);
        });
        DB::statement("ALTER TABLE allocation_rule_versions ADD CONSTRAINT arv_strategy_allowed CHECK (strategy IN ('OLDEST_DUE_FIRST','PRIORITY_FIRST'))");
        DB::statement("ALTER TABLE allocation_rule_versions ADD CONSTRAINT arv_status_allowed CHECK (status IN ('ACTIVE','SUPERSEDED'))");

        Schema::create('premium_components', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained();
            $t->string('line_key', 64);
            $t->string('component', 24);
            $t->bigInteger('amount_minor');
            $t->string('currency', 3);
            $t->boolean('payable');
            $t->timestampTz('due_at')->nullable();
            $t->uuid('financial_obligation_id')->nullable();
            $t->string('source', 32);
            $t->jsonb('snapshot')->default('{}');
            $t->string('closure', 16)->nullable();
            $t->string('closure_reason')->nullable();
            $t->foreignUuid('closed_by')->nullable()->constrained('users');
            $t->timestampTz('closed_at')->nullable();
            $t->timestampsTz();
            $t->unique(['policy_id', 'line_key']);
            $t->index(['tenant_id', 'policy_id']);
        });
        DB::statement("ALTER TABLE premium_components ADD CONSTRAINT pc_component_allowed CHECK (component IN ('BASE_PREMIUM','COVERAGE_PREMIUM','RISK_LOADING','DISCOUNT','NET_PREMIUM','TAX','LEVY','STAMP_DUTY','SERVICE_FEE','OTHER_CHARGE','GROSS_PREMIUM'))");
        DB::statement("ALTER TABLE premium_components ADD CONSTRAINT pc_closure_allowed CHECK (closure IS NULL OR closure IN ('CANCELLED','WRITTEN_OFF'))");
        DB::statement('ALTER TABLE premium_components ADD CONSTRAINT pc_payable_positive CHECK (NOT payable OR amount_minor > 0)');

        Schema::create('payment_allocation_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('payment_intent_id')->constrained();
            $t->foreignUuid('policy_id')->nullable()->constrained();
            $t->string('idempotency_key', 128);
            $t->foreignUuid('allocation_rule_version_id')->nullable()->constrained();
            $t->unsignedInteger('rule_version');
            $t->jsonb('rule_snapshot');
            $t->bigInteger('allocated_minor');
            $t->string('currency', 3);
            $t->string('status', 16)->default('APPLIED');
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampTz('reversed_at')->nullable();
            $t->string('reversal_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'idempotency_key']);
        });
        DB::statement("ALTER TABLE payment_allocation_runs ADD CONSTRAINT par_status_allowed CHECK (status IN ('APPLIED','REVERSED'))");

        Schema::create('payment_allocations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('run_id')->constrained('payment_allocation_runs');
            $t->foreignUuid('payment_intent_id')->constrained();
            $t->foreignUuid('premium_component_id')->nullable()->constrained();
            $t->uuid('financial_obligation_id')->nullable();
            $t->string('target_category', 24);
            $t->string('kind', 16);
            $t->bigInteger('amount_minor');
            $t->string('currency', 3);
            $t->uuid('reverses_allocation_id')->nullable()->unique();
            $t->string('reason_code', 32)->nullable();
            $t->unsignedSmallInteger('sequence');
            $t->timestampTz('created_at');
            $t->index(['payment_intent_id']);
            $t->index(['financial_obligation_id']);
        });
        Schema::table('payment_allocations', function (Blueprint $t): void {
            $t->foreign('reverses_allocation_id')->references('id')->on('payment_allocations');
        });
        DB::statement("ALTER TABLE payment_allocations ADD CONSTRAINT pa_kind_allowed CHECK (kind IN ('ALLOCATION','REVERSAL'))");
        DB::statement("ALTER TABLE payment_allocations ADD CONSTRAINT pa_sign CHECK ((kind = 'ALLOCATION' AND amount_minor > 0 AND reverses_allocation_id IS NULL) OR (kind = 'REVERSAL' AND amount_minor < 0 AND reverses_allocation_id IS NOT NULL))");
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT pa_target CHECK (premium_component_id IS NOT NULL OR financial_obligation_id IS NOT NULL)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_allocations_append_only() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'payment_allocations is append-only; reverse instead'; END; $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER payment_allocations_no_mutation BEFORE UPDATE OR DELETE ON payment_allocations FOR EACH ROW EXECUTE FUNCTION payment_allocations_append_only()');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        DB::statement('DROP FUNCTION IF EXISTS payment_allocations_append_only() CASCADE');
        Schema::dropIfExists('payment_allocation_runs');
        Schema::dropIfExists('premium_components');
        Schema::dropIfExists('allocation_rule_versions');
    }
};
