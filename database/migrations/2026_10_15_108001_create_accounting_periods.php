<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** REQ-ACC-003 / ESR FIN-023: monthly accounting periods per tenant, close + privileged reopen. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_period_configs', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->unique()->constrained();
            $t->unsignedTinyInteger('fiscal_year_start_month')->default(1); $t->timestampsTz();
        });
        Schema::create('accounting_periods', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained();
            $t->unsignedSmallInteger('fiscal_year'); $t->unsignedTinyInteger('period_number');
            $t->date('starts_on'); $t->date('ends_on'); $t->string('status', 16)->default('OPEN');
            $t->jsonb('checklist')->nullable();
            $t->foreignUuid('closing_started_by')->nullable()->constrained('users'); $t->timestampTz('closing_started_at')->nullable();
            $t->foreignUuid('closed_by')->nullable()->constrained('users'); $t->timestampTz('closed_at')->nullable();
            $t->string('reopen_status', 16)->nullable(); $t->text('reopen_reason')->nullable();
            $t->foreignUuid('reopen_requested_by')->nullable()->constrained('users'); $t->timestampTz('reopen_requested_at')->nullable();
            $t->foreignUuid('reopen_approved_by')->nullable()->constrained('users'); $t->timestampTz('reopened_at')->nullable();
            $t->unsignedInteger('reopen_count')->default(0); $t->timestampsTz();
            $t->unique(['tenant_id', 'starts_on']); $t->unique(['tenant_id', 'fiscal_year', 'period_number']); $t->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_status CHECK (status IN ('OPEN','CLOSING','CLOSED','REOPENED'))");
        DB::statement('ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_range CHECK (ends_on >= starts_on)');
        DB::statement('ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_reopen_sod CHECK (reopen_approved_by IS NULL OR reopen_approved_by <> reopen_requested_by)');
        Schema::table('journals', function (Blueprint $t) { $t->date('accounting_date')->nullable()->index(); });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $t) { $t->dropIndex(['accounting_date']); $t->dropColumn('accounting_date'); });
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('accounting_period_configs');
    }
};
