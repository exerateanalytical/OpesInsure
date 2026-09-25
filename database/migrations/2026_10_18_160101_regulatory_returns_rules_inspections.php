<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent B1 — REQ-RPT-001 regulatory returns with data lineage (extends regulatory_report_definitions / _runs),
 * REQ-RPT-002 regulatory change engine (regulatory_rules DRAFT→REVIEWED→APPROVED→EFFECTIVE→SUPERSEDED + impact),
 * REQ-RPT-006 regulatory inspection workspace (time-boxed privileged_access_grants + export log).
 * No CIMA return format is seeded: definitions are configured data (owner question).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regulatory_report_definitions', function (Blueprint $t): void {
            $t->string('regime', 16)->nullable();
            $t->string('regulatory_category_kind', 40)->nullable();   // e.g. ART_411_CATEGORY / ART_557_MEASURE (regulatory_reporting_categories.kind)
            $t->string('regulatory_category_code', 64)->nullable();
            $t->text('description')->nullable();
        });
        Schema::table('regulatory_report_runs', function (Blueprint $t): void {
            $t->date('period_from')->nullable();
            $t->date('period_to')->nullable();
            $t->text('source_query')->nullable();
            $t->string('source_version', 160)->nullable();
            $t->unsignedInteger('row_count')->nullable();
            $t->timestampTz('generated_at')->nullable();
        });
        Schema::create('regulatory_report_run_lineage', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('run_id')->constrained('regulatory_report_runs')->cascadeOnDelete();
            $t->unsignedInteger('row_index');
            $t->string('row_hash', 64);
            $t->string('source_table', 64);
            $t->uuid('source_id');
            $t->string('source_hash', 64);
            $t->timestampTz('created_at');
            $t->index(['run_id', 'row_index']);
            $t->index(['source_table', 'source_id']);
        });

        Schema::create('regulatory_rules', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('jurisdiction', 8)->default('CM');
            $t->string('code', 80);
            $t->unsignedInteger('version');
            $t->string('title', 255);
            $t->string('rule_type', 48);
            $t->string('status', 24)->default('DRAFT');
            $t->string('legal_reference', 255)->nullable();
            $t->string('reference_set_code', 80)->nullable();
            $t->jsonb('scope')->default('{}');
            $t->jsonb('content')->default('{}');
            $t->string('content_hash', 64);
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->jsonb('impact')->nullable();
            $t->string('impact_hash', 64)->nullable();
            $t->uuid('supersedes_id')->nullable();
            $t->foreignUuid('created_by')->constrained('users');
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $t->timestampTz('reviewed_at')->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('effective_at')->nullable();
            $t->timestampTz('superseded_at')->nullable();
            $t->timestampsTz();
            $t->unique(['jurisdiction', 'code', 'version']);
        });
        DB::statement('ALTER TABLE regulatory_rules ADD CONSTRAINT regulatory_rules_supersedes_fk FOREIGN KEY (supersedes_id) REFERENCES regulatory_rules (id)');
        DB::statement("ALTER TABLE regulatory_rules ADD CONSTRAINT regulatory_rules_status CHECK (status IN ('DRAFT','REVIEWED','APPROVED','EFFECTIVE','SUPERSEDED'))");
        DB::statement('ALTER TABLE regulatory_rules ADD CONSTRAINT regulatory_rules_reviewer CHECK (reviewed_by IS NULL OR reviewed_by <> created_by)');
        DB::statement('ALTER TABLE regulatory_rules ADD CONSTRAINT regulatory_rules_approver CHECK (approved_by IS NULL OR approved_by <> created_by)');
        DB::statement("CREATE UNIQUE INDEX regulatory_rules_one_effective ON regulatory_rules (jurisdiction, code) WHERE status = 'EFFECTIVE'");

        Schema::create('regulatory_inspections', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('privileged_access_grant_id')->unique()->constrained('privileged_access_grants');
            $t->foreignUuid('inspector_user_id')->constrained('users');
            $t->string('authority', 120);
            $t->string('reference', 120)->nullable();
            $t->jsonb('resources');
            $t->string('status', 24)->default('REQUESTED');
            $t->foreignUuid('opened_by')->constrained('users');
            $t->foreignUuid('closed_by')->nullable()->constrained('users');
            $t->timestampTz('closed_at')->nullable();
            $t->timestampsTz();
        });
        Schema::create('regulatory_inspection_exports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('inspection_id')->constrained('regulatory_inspections')->cascadeOnDelete();
            $t->string('resource', 32);
            $t->string('format', 8);
            $t->jsonb('filters')->default('{}');
            $t->unsignedInteger('row_count');
            $t->string('content_hash', 64);
            $t->foreignUuid('exported_by')->constrained('users');
            $t->timestampTz('exported_at');
        });
    }

    public function down(): void
    {
        foreach (['regulatory_inspection_exports', 'regulatory_inspections', 'regulatory_rules', 'regulatory_report_run_lineage'] as $x) {
            Schema::dropIfExists($x);
        }
        Schema::table('regulatory_report_runs', fn (Blueprint $t) => $t->dropColumn(['period_from', 'period_to', 'source_query', 'source_version', 'row_count', 'generated_at']));
        Schema::table('regulatory_report_definitions', fn (Blueprint $t) => $t->dropColumn(['regime', 'regulatory_category_kind', 'regulatory_category_code', 'description']));
    }
};
