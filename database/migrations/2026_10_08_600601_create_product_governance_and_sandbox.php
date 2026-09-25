<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 6A — REQ-PRD-007 / REQ-PRD-008 (PRE §74–84).
 *
 * product_governance        one row per product version: the PRE §74 review sub-status (governance_stage) layered on
 *                           top of the PRE §8 version lifecycle (insurance_products.status stays the single lifecycle),
 *                           plus governance attributes (owner, target / prohibited market, review date, scheduled publish).
 * product_governance_events immutable stage history (who moved which stage, decision, approval request).
 * product_test_cases        the version's test policy pack (facts + expected outcome).
 * product_test_runs         sandbox evidence: results of running the pack through rules + rating + documents
 *                           against a configuration hash. Governance evidence only — never business records.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_governance', function (Blueprint $t): void {
            $t->foreignUuid('insurance_product_id')->primary()->constrained('insurance_products')->cascadeOnDelete();
            $t->string('stage', 32)->default('DRAFT');
            $t->foreignUuid('owner_user_id')->nullable()->constrained('users');
            $t->jsonb('target_market')->default('[]');
            $t->jsonb('prohibited_market')->default('[]');
            $t->date('next_review_date')->nullable();
            $t->timestampTz('scheduled_publish_at')->nullable();
            $t->foreignUuid('scheduled_by')->nullable()->constrained('users');
            $t->uuid('approval_request_id')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE product_governance ADD CONSTRAINT product_governance_stage CHECK (stage IN ('DRAFT','CONFIGURATION','TECHNICAL_REVIEW','COMPLIANCE_REVIEW','BUSINESS_APPROVAL','READY','PUBLISHED','REJECTED'))");

        Schema::create('product_governance_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('insurance_product_id')->constrained('insurance_products')->cascadeOnDelete();
            $t->string('from_stage', 32)->nullable();
            $t->string('to_stage', 32);
            $t->string('decision', 24); // ADVANCED | REJECTED | SCHEDULED | PUBLISHED
            $t->text('notes')->nullable();
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->uuid('approval_request_id')->nullable();
            $t->timestampTz('occurred_at');
            $t->index(['insurance_product_id', 'occurred_at']);
        });
        DB::statement('ALTER TABLE product_governance_events ADD COLUMN seq BIGSERIAL'); // stable order when several steps share a timestamp

        Schema::create('product_test_cases', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('insurance_product_id')->constrained('insurance_products')->cascadeOnDelete();
            $t->string('code', 64);
            $t->string('name', 191);
            $t->jsonb('facts');
            $t->jsonb('expected')->default('{}'); // eligibility, premium_total_minor, premium_min_minor, premium_max_minor, rating_fails
            $t->date('reference_date')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->unique(['insurance_product_id', 'code']);
        });

        Schema::create('product_test_runs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('insurance_product_id')->constrained('insurance_products')->cascadeOnDelete();
            $t->char('configuration_hash', 64);
            $t->string('status', 16); // PASSED | FAILED
            $t->unsignedInteger('cases_total');
            $t->unsignedInteger('cases_failed');
            $t->jsonb('results');
            $t->foreignUuid('run_by')->nullable()->constrained('users');
            $t->timestampTz('ran_at');
            $t->index(['insurance_product_id', 'ran_at']);
        });
        DB::statement("ALTER TABLE product_test_runs ADD CONSTRAINT product_test_runs_status CHECK (status IN ('PASSED','FAILED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_test_runs');
        Schema::dropIfExists('product_test_cases');
        Schema::dropIfExists('product_governance_events');
        Schema::dropIfExists('product_governance');
    }
};
