<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 10-9 — REQ-ACC-004 technical accounting.
 *
 * - technical_actuarial_imports / technical_actuarial_values: IBNR and life actuarial values are NEVER computed by
 *   the platform; they are imported from carrier / actuarial engines as versioned batches that need a second person
 *   to approve (maker-checker). Approving a batch supersedes the previously approved batch for the same scope.
 * - technical_upr_postings: one row per tenant / period end / currency recording the computed UPR, the movement vs
 *   the previous posting and the journal written through FinancialPostingService (event technical.upr.movement /
 *   technical.upr.release).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technical_actuarial_imports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->string('kind', 24);                 // IBNR | LIFE_MATH_RESERVE | LIFE_OTHER
            $t->date('period_end');
            $t->unsignedInteger('version');
            $t->string('status', 16);               // PENDING_APPROVAL | APPROVED | REJECTED | SUPERSEDED
            $t->string('source', 120);              // actuarial engine / carrier file name
            $t->string('checksum', 64);
            $t->unsignedInteger('row_count');
            $t->text('notes')->nullable();
            $t->uuid('created_by');
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'kind', 'period_end', 'version']);
            $t->index(['tenant_id', 'kind', 'period_end', 'status']);
        });

        Schema::create('technical_actuarial_values', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('import_id');
            $t->uuid('carrier_id')->nullable();
            $t->string('line_code', 64)->nullable();
            $t->string('metric', 48);
            $t->bigInteger('amount_minor');
            $t->char('currency', 3);
            $t->timestampTz('created_at')->nullable();
            $t->foreign('import_id')->references('id')->on('technical_actuarial_imports')->cascadeOnDelete();
            $t->index(['import_id', 'carrier_id', 'line_code']);
        });

        Schema::create('technical_upr_postings', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->date('period_end');
            $t->char('currency', 3);
            $t->bigInteger('upr_minor');
            $t->bigInteger('previous_upr_minor');
            $t->bigInteger('movement_minor');
            $t->uuid('journal_id')->nullable();
            $t->uuid('posted_by');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'period_end', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technical_upr_postings');
        Schema::dropIfExists('technical_actuarial_values');
        Schema::dropIfExists('technical_actuarial_imports');
    }
};
