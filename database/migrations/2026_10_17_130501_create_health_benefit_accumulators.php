<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 14 E5 — REQ-HLT-004 health benefit accumulator.
 *
 * Reuses the Phase 5 product model: coverage_limits (PER_YEAR / PER_POLICY_PERIOD / FIXED_AMOUNT = period
 * limit, PER_EVENT, AGGREGATE = family pool) and product_coverages.waiting_period_days are the fallback for
 * every term left NULL here. health_benefit_schedules only adds what the product model lacks: benefit
 * codes, the accumulation period, per-visit caps / visit counts, sub-limit parentage, individual vs
 * family pooling and copay.
 *
 * health_benefit_accumulators holds the running counters per subject (member or family) + schedule +
 * period; health_benefit_movements is the append-only ledger of every counter change
 * (RESERVE / RELEASE / CONSUME / REVERSE), same pattern as policy_limit_movements (Batch 11 C4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_benefit_schedules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();              // null = product-wide
            $t->foreignUuid('insurance_product_id')->constrained('insurance_products');
            $t->foreignUuid('product_plan_id')->nullable()->constrained('product_plans');
            $t->foreignUuid('coverage_definition_id')->nullable()->constrained('coverage_definitions');
            $t->string('benefit_code', 64);
            $t->jsonb('name')->default('{}');
            $t->uuid('parent_schedule_id')->nullable();                            // sub-limit of (FK below)
            $t->string('period_basis', 16)->default('POLICY_YEAR');             // POLICY_YEAR | CALENDAR_YEAR | LIFETIME
            $t->string('scope', 16)->default('INDIVIDUAL');                     // INDIVIDUAL | FAMILY (limit pooled)
            $t->bigInteger('period_limit_minor')->nullable();                   // null → coverage_limits fallback; none → unlimited
            $t->bigInteger('family_limit_minor')->nullable();                   // null → coverage_limits AGGREGATE fallback
            $t->bigInteger('per_event_limit_minor')->nullable();                // null → coverage_limits PER_EVENT fallback
            $t->bigInteger('per_visit_limit_minor')->nullable();
            $t->unsignedInteger('max_visits_per_period')->nullable();
            $t->unsignedInteger('copay_bp')->nullable();                        // member share, basis points
            $t->unsignedInteger('waiting_period_days')->nullable();             // null → product_coverages fallback
            $t->char('currency', 3)->default('XAF');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('status', 16)->default('ACTIVE');
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->index(['insurance_product_id', 'benefit_code']);
        });
        Schema::table('health_benefit_schedules', fn (Blueprint $t) => $t->foreign('parent_schedule_id')->references('id')->on('health_benefit_schedules'));
        DB::statement("ALTER TABLE health_benefit_schedules ADD CONSTRAINT health_benefit_schedules_enums CHECK (period_basis IN ('POLICY_YEAR','CALENDAR_YEAR','LIFETIME') AND scope IN ('INDIVIDUAL','FAMILY') AND status IN ('ACTIVE','RETIRED'))");
        DB::statement('ALTER TABLE health_benefit_schedules ADD CONSTRAINT health_benefit_schedules_amounts CHECK (COALESCE(period_limit_minor,0) >= 0 AND COALESCE(family_limit_minor,0) >= 0 AND COALESCE(per_event_limit_minor,0) >= 0 AND COALESCE(per_visit_limit_minor,0) >= 0 AND COALESCE(copay_bp,0) <= 10000)');

        Schema::create('health_benefit_accumulators', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('health_benefit_schedule_id')->constrained('health_benefit_schedules');
            $t->string('subject_type', 16);                                     // INDIVIDUAL | FAMILY
            $t->string('subject_ref', 128);                                     // member ref or family ref
            $t->foreignUuid('policy_id')->nullable()->constrained('policies');
            $t->string('period_key', 32);
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->bigInteger('limit_minor')->nullable();                          // snapshot; null = unlimited
            $t->bigInteger('reserved_minor')->default(0);
            $t->bigInteger('consumed_minor')->default(0);
            $t->char('currency', 3);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'health_benefit_schedule_id', 'subject_type', 'subject_ref', 'period_key'], 'health_benefit_accumulators_key');
        });
        DB::statement("ALTER TABLE health_benefit_accumulators ADD CONSTRAINT health_benefit_accumulators_valid CHECK (subject_type IN ('INDIVIDUAL','FAMILY') AND reserved_minor >= 0 AND consumed_minor >= 0)");

        Schema::create('health_benefit_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('health_benefit_accumulator_id')->constrained('health_benefit_accumulators');
            $t->uuid('group_id');
            $t->string('movement_type', 16);
            $t->string('member_ref', 128);
            $t->string('holder_ref', 191);                                      // who holds the reservation (claim / preauth / …)
            $t->foreignUuid('claim_id')->nullable()->constrained('claims');
            $t->string('event_ref', 128)->nullable();
            $t->string('visit_ref', 128)->nullable();
            $t->bigInteger('amount_minor');
            $t->bigInteger('reserved_delta_minor');
            $t->bigInteger('consumed_delta_minor');
            $t->bigInteger('reserved_after_minor');
            $t->bigInteger('consumed_after_minor');
            $t->boolean('overrun')->default(false);
            $t->char('currency', 3);
            $t->uuid('reverses_movement_id')->nullable();
            $t->string('reference_type', 64)->nullable();
            $t->string('reference_id', 128)->nullable();
            $t->string('idempotency_key', 191)->nullable();
            $t->string('reason', 500)->nullable();
            $t->uuid('actor_id')->nullable();
            $t->timestampTz('occurred_at');
            $t->timestampTz('created_at');
            $t->index(['health_benefit_accumulator_id', 'holder_ref']);
            $t->index('group_id');
            $t->index('claim_id');
            $t->unique(['health_benefit_accumulator_id', 'idempotency_key']);
            $t->unique('reverses_movement_id');
        });
        DB::statement("ALTER TABLE health_benefit_movements ADD CONSTRAINT health_benefit_movements_valid CHECK (movement_type IN ('RESERVE','RELEASE','CONSUME','REVERSE') AND amount_minor > 0 AND reserved_after_minor >= 0 AND consumed_after_minor >= 0)");

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION health_benefit_movements_append_only() RETURNS trigger AS $$
BEGIN RAISE EXCEPTION 'health_benefit_movements rows are append-only'; END; $$ LANGUAGE plpgsql;
CREATE TRIGGER health_benefit_movements_append_only BEFORE UPDATE OR DELETE ON health_benefit_movements FOR EACH ROW EXECUTE FUNCTION health_benefit_movements_append_only();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('health_benefit_movements');
        Schema::dropIfExists('health_benefit_accumulators');
        Schema::dropIfExists('health_benefit_schedules');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS health_benefit_movements_append_only() CASCADE;');
        }
    }
};
