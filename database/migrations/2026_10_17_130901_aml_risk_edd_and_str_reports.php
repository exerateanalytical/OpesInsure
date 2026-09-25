<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | Agent E9 — REQ-AML-002 / REQ-AML-003.
 |  - aml_risk_reviews: explainable AML rating on top of kyc_risk_assessments (append-only), EDD case link.
 |  - aml_str_reports: suspicious transaction reports (case engine STR, STR_RESTRICTED).
 |  - case type AML_EDD (RESTRICTED, regulated, decision required).
 | Transaction monitoring rules reuse fraud_rule_versions (scope AML_TRANSACTION); NONE are seeded (OQ-5.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aml_risk_reviews', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('party_id')->index();
            $t->uuid('kyc_submission_id');
            $t->uuid('kyc_risk_assessment_id');
            $t->string('band', 16);
            $t->decimal('score', 10, 4)->nullable();
            $t->jsonb('explanation');
            $t->string('screening_source', 40);
            $t->boolean('edd_required')->default(false);
            $t->uuid('edd_case_id')->nullable();
            $t->timestampTz('next_rescreen_at')->nullable();
            $t->text('reason')->nullable();
            $t->foreignUuid('rated_by')->nullable()->constrained('users');
            $t->timestampTz('created_at');
        });
        DB::statement("ALTER TABLE aml_risk_reviews ADD CONSTRAINT aml_risk_reviews_band CHECK (band IN ('LOW','MEDIUM','HIGH','UNRATED'))");

        Schema::create('aml_str_reports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->uuid('case_id')->unique();
            $t->uuid('party_id')->nullable()->index();
            $t->string('status', 16)->default('DRAFT');
            $t->text('grounds');
            $t->jsonb('related_alert_ids');
            $t->string('regulator_reference', 120)->nullable();
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('submitted_by')->nullable()->constrained('users');
            $t->timestampTz('submitted_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE aml_str_reports ADD CONSTRAINT aml_str_reports_status CHECK (status IN ('DRAFT','SUBMITTED'))");
        DB::statement('ALTER TABLE aml_str_reports ADD CONSTRAINT aml_str_reports_four_eyes CHECK (submitted_by IS NULL OR submitted_by <> created_by)');

        if (! DB::table('case_types')->where('code', 'AML_EDD')->exists()) {
            $def = \App\Application\Cases\CaseTypeCatalogue::genericLifecycle(true);
            DB::table('case_types')->insert([
                'id' => (string) Str::uuid(), 'code' => 'AML_EDD', 'version' => 1,
                'family_code' => DB::table('case_families')->where('code', 'KYC')->exists() ? 'KYC' : null,
                'name' => 'AML enhanced due diligence', 'status' => 'EFFECTIVE', 'valid_from' => '2026-01-01', 'valid_to' => null,
                'states' => json_encode($def['states']), 'transitions' => json_encode($def['transitions']),
                'sla_policies' => '[]', 'auto_tasks' => '[]', 'default_confidentiality' => 'RESTRICTED', 'regulated' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('case_types')->where('code', 'AML_EDD')->whereNotExists(fn ($q) => $q->from('cases')->whereColumn('cases.case_type_id', 'case_types.id'))->delete();
        Schema::dropIfExists('aml_str_reports');
        Schema::dropIfExists('aml_risk_reviews');
    }
};
