<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Agent C16 — REQ-FRD-001 claim fraud indicators (App\Application\Fraud).
 |  - claims.fraud_flag: REVIEW_REQUIRED (set by the rules) | CLEARED | MONITOR | FRAUD_CONFIRMED (human outcome only).
 |    LOCK-010 is enforced in the database too: FRAUD_CONFIRMED requires a human (fraud_flag_set_by).
 |  - risk_alerts.case_id links a claim fraud review alert to its CLAIM_INVESTIGATION case (WF-089).
 |  - one open CLAIM_FRAUD_REVIEW alert per claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $t): void {
            $t->string('fraud_flag', 24)->nullable();
            $t->foreignUuid('fraud_flag_set_by')->nullable()->constrained('users');
            $t->timestampTz('fraud_flag_set_at')->nullable();
        });
        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_fraud_flag CHECK (fraud_flag IS NULL OR fraud_flag IN ('REVIEW_REQUIRED','CLEARED','MONITOR','FRAUD_CONFIRMED'))");
        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_fraud_confirmed_by_human CHECK (fraud_flag IS DISTINCT FROM 'FRAUD_CONFIRMED' OR fraud_flag_set_by IS NOT NULL)");

        Schema::table('risk_alerts', function (Blueprint $t): void {
            $t->uuid('case_id')->nullable()->index();
        });
        DB::statement("CREATE UNIQUE INDEX risk_alerts_one_open_claim_review ON risk_alerts (subject_id) WHERE alert_type = 'CLAIM_FRAUD_REVIEW' AND status IN ('OPEN','UNDER_REVIEW')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS risk_alerts_one_open_claim_review');
        Schema::table('risk_alerts', fn (Blueprint $t) => $t->dropColumn('case_id'));
        DB::statement('ALTER TABLE claims DROP CONSTRAINT IF EXISTS claims_fraud_confirmed_by_human');
        DB::statement('ALTER TABLE claims DROP CONSTRAINT IF EXISTS claims_fraud_flag');
        Schema::table('claims', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('fraud_flag_set_by');
            $t->dropColumn(['fraud_flag', 'fraud_flag_set_at']);
        });
    }
};
