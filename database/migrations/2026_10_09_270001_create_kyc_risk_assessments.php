<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Owner decision 27 (2026-09-25) — risk-based KYC. kyc_risk_assessments is append-only: every (re)assessment of a
 | KYC submission is a new row (customer / country / product / channel risk, PEP / sanctions, beneficial ownership,
 | EDD triggers, source of funds / wealth, next refresh / rescreen). screening_checks gains the rescreening round.
 | Additive only; existing screening rows keep provider "MANUAL" (read as MANUAL_AUDITED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_risk_assessments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('kyc_submission_id')->constrained('kyc_submissions');
            $t->foreignUuid('party_id')->constrained();
            $t->unsignedInteger('version');
            $t->string('rating', 16);                           // LOW | MEDIUM | HIGH | UNRATED
            $t->decimal('score', 10, 4)->nullable();
            $t->jsonb('inputs')->default('{}');                 // customer_type, country_code, product_codes, channel, declared factors
            $t->jsonb('factors')->default('[]');                // per-factor value / score / weight / status
            $t->jsonb('triggers')->default('[]');               // hard + EDD triggers that fired
            $t->jsonb('beneficial_ownership')->nullable();      // UBO outcome (corporate)
            $t->boolean('edd_required')->default(false);
            $t->boolean('source_of_funds_required')->default(false);
            $t->boolean('source_of_wealth_required')->default(false);
            $t->jsonb('source_of_funds')->nullable();
            $t->jsonb('source_of_wealth')->nullable();
            $t->string('screening_mode', 32);
            $t->jsonb('configuration_gaps')->default('[]');     // UNVERIFIED settings that left the rating UNRATED
            $t->unsignedSmallInteger('refresh_months')->nullable();
            $t->timestampTz('next_rescreen_at')->nullable();
            $t->string('reason', 500)->nullable();
            $t->uuid('assessed_by')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['kyc_submission_id', 'version']);
            $t->index(['tenant_id', 'rating']);
            $t->index('next_rescreen_at');
        });

        Schema::table('screening_checks', function (Blueprint $t) {
            $t->unsignedSmallInteger('screening_round')->default(1);
            $t->string('trigger', 32)->default('ONBOARDING');   // ONBOARDING | PERIODIC_RESCREEN | MANUAL_RESCREEN
        });
    }

    public function down(): void
    {
        Schema::table('screening_checks', function (Blueprint $t) {
            $t->dropColumn(['screening_round', 'trigger']);
        });
        Schema::dropIfExists('kyc_risk_assessments');
    }
};
