<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 8 — REQ-POL-008 (in-force side) / REQ-POL-010 (lapse, grace, recovery — WF-083).
 *
 *  policy_premium_instalments  due premiums per policy; the premium-cover sweep (policies:premium-cover-sweep)
 *                              moves them DUE → OVERDUE → GRACE → DEFAULTED → LAPSED using premium_cover_rules.
 *  premium_cover_rules.lapse_after_days  days after a default-suspension before the instalment lapses
 *                              (NULL = never lapses automatically; nothing is assumed).
 *  policy_recovery_cases       maker-checker recovery of SUSPENDED / EXPIRED / LAPSED policies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('premium_cover_rules', function (Blueprint $t): void {
            $t->unsignedSmallInteger('lapse_after_days')->nullable();
        });

        Schema::create('policy_premium_instalments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained();
            $t->unsignedSmallInteger('sequence');
            $t->date('due_date');
            $t->bigInteger('amount_minor');
            $t->bigInteger('paid_minor')->default(0);
            $t->string('currency', 3);
            $t->string('status', 16)->default('DUE');
            $t->date('grace_ends_on')->nullable();
            $t->timestampTz('defaulted_at')->nullable();
            $t->timestampTz('lapsed_at')->nullable();
            $t->timestampTz('settled_at')->nullable();
            $t->uuid('premium_cover_rule_id')->nullable();
            $t->string('last_outcome', 32)->nullable();
            $t->jsonb('last_evaluation')->nullable();
            $t->timestampTz('last_evaluated_at')->nullable();
            $t->uuid('payment_intent_id')->nullable();
            $t->timestampsTz();
            $t->unique(['policy_id', 'sequence']);
            $t->index(['status', 'due_date']);
        });
        DB::statement("ALTER TABLE policy_premium_instalments ADD CONSTRAINT ppi_status_allowed CHECK (status IN ('DUE','OVERDUE','GRACE','DEFAULTED','LAPSED','PAID','WAIVED'))");
        DB::statement('ALTER TABLE policy_premium_instalments ADD CONSTRAINT ppi_amounts CHECK (amount_minor > 0 AND paid_minor >= 0)');

        Schema::create('policy_recovery_cases', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('policy_id')->constrained();
            $t->string('case_number', 32)->unique();
            $t->string('status', 16)->default('OPEN');
            $t->string('policy_status_at_open', 32);
            $t->string('reason_code', 64);
            $t->bigInteger('arrears_minor')->default(0);
            $t->string('currency', 3);
            $t->timestampTz('new_coverage_ends_at')->nullable();
            $t->text('notes')->nullable();
            $t->foreignUuid('requested_by')->constrained('users');
            $t->foreignUuid('decided_by')->nullable()->constrained('users');
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE policy_recovery_cases ADD CONSTRAINT prc_status_allowed CHECK (status IN ('OPEN','APPROVED','REJECTED'))");
        DB::statement("CREATE UNIQUE INDEX prc_one_open_per_policy ON policy_recovery_cases (policy_id) WHERE status = 'OPEN'");
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_recovery_cases');
        Schema::dropIfExists('policy_premium_instalments');
        Schema::table('premium_cover_rules', fn (Blueprint $t) => $t->dropColumn('lapse_after_days'));
    }
};
