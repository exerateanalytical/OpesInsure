<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 11 C4 — REQ-CLM-004: append-only limit movement ledger over policy_limits
 * (Batch 7C). policy_limits.reserved_minor / consumed_minor are the running
 * counters; every change to them is one row here (RESERVE, RELEASE, CONSUME, REVERSE)
 * carrying signed deltas and the post-movement counters. AGGREGATE limits may never
 * be over-committed (remaining = amount − consumed − reserved ≥ 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_limit_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained('policies');
            $t->foreignUuid('policy_limit_id')->constrained('policy_limits');
            $t->foreignUuid('claim_id')->nullable()->constrained('claims');
            $t->uuid('group_id');
            $t->string('movement_type', 16);
            $t->bigInteger('amount_minor');
            $t->bigInteger('reserved_delta_minor');
            $t->bigInteger('consumed_delta_minor');
            $t->bigInteger('reserved_after_minor');
            $t->bigInteger('consumed_after_minor');
            $t->string('currency', 3);
            $t->uuid('reverses_movement_id')->nullable();
            $t->string('reference_type', 64)->nullable();
            $t->uuid('reference_id')->nullable();
            $t->string('idempotency_key', 128)->nullable();
            $t->string('reason', 500)->nullable();
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->timestampTz('occurred_at');
            $t->timestampTz('created_at')->nullable();
            $t->index(['policy_limit_id', 'claim_id']);
            $t->index(['claim_id', 'occurred_at']);
            $t->index('group_id');
            $t->unique(['policy_limit_id', 'idempotency_key']);
            $t->unique('reverses_movement_id');
        });
        DB::statement("ALTER TABLE policy_limit_movements ADD CONSTRAINT policy_limit_movements_type_allowed CHECK (movement_type IN ('RESERVE','RELEASE','CONSUME','REVERSE'))");
        DB::statement('ALTER TABLE policy_limit_movements ADD CONSTRAINT policy_limit_movements_amounts_valid CHECK (amount_minor > 0 AND reserved_after_minor >= 0 AND consumed_after_minor >= 0)');
        DB::statement("ALTER TABLE policy_limits ADD CONSTRAINT policy_limits_aggregate_not_exceeded CHECK (limit_type <> 'AGGREGATE' OR consumed_minor + reserved_minor <= amount_minor)");

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION policy_limit_movements_append_only() RETURNS trigger AS $$
BEGIN RAISE EXCEPTION 'policy_limit_movements rows are append-only'; END; $$ LANGUAGE plpgsql;
CREATE TRIGGER policy_limit_movements_append_only BEFORE UPDATE OR DELETE ON policy_limit_movements FOR EACH ROW EXECUTE FUNCTION policy_limit_movements_append_only();
SQL);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE policy_limits DROP CONSTRAINT IF EXISTS policy_limits_aggregate_not_exceeded');
        Schema::dropIfExists('policy_limit_movements');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS policy_limit_movements_append_only() CASCADE;');
        }
    }
};
