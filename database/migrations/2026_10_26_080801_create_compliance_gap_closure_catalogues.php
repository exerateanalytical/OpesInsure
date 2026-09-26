<?php

declare(strict_types=1);

use App\Application\Compliance\Catalogue\ComplianceCatalogueSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Gap Closure Pack 08 (KYC / AML / PEP / sanctions / fraud) and 09 (regulatory reporting, AML & ICT controls).
 | Additive only. Reuses: screening_list_sources/versions (PEP & sanctions import, maker-checker), kyc_level_requirements,
 | fraud_rule_versions + risk_alerts (fraud review), regulatory_report_definitions (report headers),
 | governance_ict_* (ICT registers), compliance_findings (remediation). New here: the catalogues those modules lacked.
 | Values that the pack marks CONFIG_REQUIRED / PENDING_* are created empty with their status, never invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_document_matrix', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('customer_type', 32);
            $t->string('document_code', 64);
            $t->string('requirement_type', 16)->default('REQUIRED');
            $t->string('data_status', 24);                 // DataStatus vocabulary
            $t->string('pack_status', 80)->nullable();     // status as written in the gap-closure pack
            $t->string('source', 64);
            $t->string('source_reference', 500)->nullable();
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->timestampTz('admin_modified_at')->nullable();
            $t->timestampsTz();
            $t->unique(['customer_type', 'document_code', 'effective_from']);
        });

        Schema::create('kyc_refresh_policies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();   // NULL = platform placeholder row
            $t->string('risk_level', 32);
            $t->unsignedSmallInteger('refresh_months')->nullable();
            $t->jsonb('trigger_events')->default('[]');
            $t->string('source_policy_id', 128)->nullable();
            $t->string('data_status', 24);
            $t->string('source', 64);
            $t->date('effective_from')->nullable();
            $t->date('effective_until')->nullable();
            $t->foreignUuid('configured_by')->nullable()->constrained('users');
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'risk_level']);
        });

        Schema::create('country_risk_ratings', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->string('country_code', 2);
            $t->string('risk_level', 32);
            $t->text('basis')->nullable();
            $t->string('sanctions_status', 64)->nullable();
            $t->string('fatf_status', 64)->nullable();
            $t->text('internal_notes')->nullable();
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('source', 500);
            $t->string('data_status', 24);
            $t->uuid('import_batch_id')->nullable();
            $t->timestampsTz();
            $t->index(['country_code', 'effective_from']);
        });

        Schema::create('fraud_indicators', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('code', 64)->unique();
            $t->string('category', 32);
            $t->string('severity', 16);
            $t->string('outcome', 32)->default('REVIEW_REQUIRED');
            $t->boolean('is_determination')->default(false);
            $t->string('linked_rule_code', 80)->nullable();   // fraud_rule_versions.code that detects it, when configured
            $t->string('data_status', 24);
            $t->string('pack_status', 80)->nullable();
            $t->string('source', 64);
            $t->timestampTz('admin_modified_at')->nullable();
            $t->timestampsTz();
        });

        Schema::create('regulatory_report_dictionary_lines', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('report_id', 80)->nullable();
            $t->string('regulator', 64);
            $t->string('report_code', 80);
            $t->string('report_name', 255);
            $t->string('periodicity', 16);
            $t->string('submission_deadline_rule', 500)->nullable();
            $t->string('line_code', 80);
            $t->string('line_label', 500);
            $t->string('data_type', 32);
            $t->string('currency', 3)->nullable();
            $t->text('formula')->nullable();
            $t->string('source_entity', 128)->nullable();
            $t->string('source_field', 128)->nullable();
            $t->jsonb('filters')->default('{}');
            $t->jsonb('validation_rules')->default('[]');
            $t->jsonb('signatory_roles')->default('[]');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->string('source_document_id', 255);
            $t->string('data_status', 24);
            $t->uuid('import_batch_id')->nullable();
            $t->timestampsTz();
            $t->unique(['report_code', 'line_code', 'effective_from']);
        });

        Schema::create('compliance_controls', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('framework', 16);                   // AML | ICT
            $t->string('control_code', 64);
            $t->string('domain', 64)->nullable();
            $t->text('requirement_summary')->nullable();
            $t->string('owner_role', 64)->nullable();
            $t->string('frequency', 32)->nullable();
            $t->jsonb('evidence_types')->default('[]');
            $t->string('automation_level', 32)->nullable();
            $t->text('kpi')->nullable();
            $t->text('kri')->nullable();
            $t->text('test_procedure')->nullable();
            $t->string('remediation_sla', 64)->nullable();
            $t->string('failure_severity', 16)->nullable();
            $t->string('source_reference', 500)->nullable();
            $t->date('effective_from')->nullable();
            $t->string('data_status', 24);
            $t->string('pack_status', 80)->nullable();
            $t->string('source', 64);
            $t->uuid('import_batch_id')->nullable();
            $t->timestampsTz();
            $t->unique(['framework', 'control_code']);
        });

        Schema::create('compliance_control_assessments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('control_id')->constrained('compliance_controls');
            $t->string('status', 24);
            $t->jsonb('evidence')->default('[]');
            $t->text('notes')->nullable();
            $t->uuid('compliance_finding_id')->nullable();
            $t->foreignUuid('assessed_by')->constrained('users');
            $t->timestampTz('assessed_at');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['tenant_id', 'control_id', 'assessed_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // An indicator can never be a fraud determination (pack 08 fraud_rule).
            DB::statement('ALTER TABLE fraud_indicators ADD CONSTRAINT fraud_indicators_not_determination CHECK (is_determination = false)');
        }

        app(ComplianceCatalogueSeeder::class)->run();
    }

    public function down(): void
    {
        foreach (['compliance_control_assessments', 'compliance_controls', 'regulatory_report_dictionary_lines', 'fraud_indicators',
            'country_risk_ratings', 'kyc_refresh_policies', 'kyc_document_matrix'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
