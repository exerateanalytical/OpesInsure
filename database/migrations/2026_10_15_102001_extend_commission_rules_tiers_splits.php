<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-COM-002 (Batch 10-2): commission rules per carrier + agreement + product/line
 * + transaction type, with tiered / volume / hybrid calculation and an internal
 * split between intermediaries that must total 100 % (enforced at approval).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rule_versions', function (Blueprint $t): void {
            $t->foreignUuid('agreement_id')->nullable()->constrained('carrier_broker_agreements');
            $t->string('line_code', 32)->nullable();                 // NULL = any line
            $t->string('transaction_type', 24)->nullable();          // NULL = any transaction type
            $t->string('calculation_method', 16)->default('FLAT');   // FLAT | TIERED | VOLUME | HYBRID
            $t->string('volume_period', 12)->default('MONTH');       // MONTH | QUARTER | YEAR
            $t->index(['carrier_id', 'agreement_id', 'status']);
        });
        DB::statement("ALTER TABLE commission_rule_versions ADD CONSTRAINT commission_rule_method_valid CHECK (calculation_method IN ('FLAT','TIERED','VOLUME','HYBRID'))");
        DB::statement("ALTER TABLE commission_rule_versions ADD CONSTRAINT commission_rule_volume_period_valid CHECK (volume_period IN ('MONTH','QUARTER','YEAR'))");
        DB::statement("ALTER TABLE commission_rule_versions ADD CONSTRAINT commission_rule_transaction_type_valid CHECK (transaction_type IS NULL OR transaction_type IN ('NEW_BUSINESS','RENEWAL','ENDORSEMENT','CANCELLATION','REINSTATEMENT','ADJUSTMENT'))");

        Schema::create('commission_rule_tiers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('commission_rule_version_id')->constrained('commission_rule_versions')->cascadeOnDelete();
            $t->string('tier_basis', 16);                     // PREMIUM (per-transaction band) | VOLUME (period production)
            $t->bigInteger('threshold_from_minor');
            $t->bigInteger('threshold_to_minor')->nullable(); // exclusive upper bound, NULL = open
            $t->unsignedInteger('basis_points');
            $t->timestampsTz();
            $t->unique(['commission_rule_version_id', 'tier_basis', 'threshold_from_minor'], 'commission_rule_tiers_unique');
        });
        DB::statement("ALTER TABLE commission_rule_tiers ADD CONSTRAINT commission_rule_tiers_valid CHECK (tier_basis IN ('PREMIUM','VOLUME') AND basis_points <= 10000 AND threshold_from_minor >= 0 AND (threshold_to_minor IS NULL OR threshold_to_minor > threshold_from_minor))");

        Schema::create('commission_rule_splits', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('commission_rule_version_id')->constrained('commission_rule_versions')->cascadeOnDelete();
            $t->string('beneficiary_type', 16);          // BROKER | BRANCH | AGENT
            $t->uuid('beneficiary_id')->nullable();      // NULL = the accruing partner itself
            $t->unsignedInteger('share_basis_points');
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE commission_rule_splits ADD CONSTRAINT commission_rule_splits_valid CHECK (beneficiary_type IN ('BROKER','BRANCH','AGENT') AND share_basis_points BETWEEN 1 AND 10000)");
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rule_splits');
        Schema::dropIfExists('commission_rule_tiers');
        foreach (['commission_rule_method_valid', 'commission_rule_volume_period_valid', 'commission_rule_transaction_type_valid'] as $c) {
            DB::statement("ALTER TABLE commission_rule_versions DROP CONSTRAINT IF EXISTS $c");
        }
        Schema::table('commission_rule_versions', function (Blueprint $t): void {
            $t->dropIndex(['carrier_id', 'agreement_id', 'status']);
            $t->dropConstrainedForeignId('agreement_id');
            $t->dropColumn(['line_code', 'transaction_type', 'calculation_method', 'volume_period']);
        });
    }
};
