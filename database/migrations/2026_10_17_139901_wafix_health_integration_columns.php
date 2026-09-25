<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WA-FIX — Wave A cross-agent contracts (health):
 *  - E4 → E3: a provider claim consumes the guarantee of payment it verified (health_preauthorizations.consumed_amount_minor).
 *  - E4 → E2/E5: each invoice line keeps the eligibility outcome, coverage and benefit code it was adjudicated against, so
 *    the benefit consumed at payable time is the benefit eligibility resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_preauthorizations', function (Blueprint $t): void {
            $t->bigInteger('consumed_amount_minor')->default(0);
        });
        Schema::table('health_provider_claims', function (Blueprint $t): void {
            $t->string('preauth_check', 48)->nullable();          // VERIFIED or the reason the GOP was not accepted
            $t->bigInteger('preauth_consumed_minor')->default(0);
        });
        Schema::table('health_provider_claim_lines', function (Blueprint $t): void {
            $t->string('eligibility_outcome', 32)->nullable();
            $t->string('coverage_code', 64)->nullable();
            $t->string('benefit_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('health_provider_claim_lines', fn (Blueprint $t) => $t->dropColumn(['eligibility_outcome', 'coverage_code', 'benefit_code']));
        Schema::table('health_provider_claims', fn (Blueprint $t) => $t->dropColumn(['preauth_check', 'preauth_consumed_minor']));
        Schema::table('health_preauthorizations', fn (Blueprint $t) => $t->dropColumn('consumed_amount_minor'));
    }
};
