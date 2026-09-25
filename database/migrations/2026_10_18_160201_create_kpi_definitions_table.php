<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent B2 — REQ-RPT-003 KPI governance catalogue (MPS §88): versioned, maker-checker KPI definitions.
 * A KPI never carries SQL: it binds to a code-registered query (App\Application\Reporting\Kpi\KpiQueryRegistry)
 * by `query_key` and may only narrow it with that query's whitelisted filters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_definitions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->string('code', 80);
            $t->unsignedInteger('version');
            $t->string('name');
            $t->text('definition');
            $t->text('formula');
            $t->string('query_key', 80);
            $t->jsonb('sources');
            $t->string('date_basis', 64);
            $t->jsonb('filters')->default('{}');
            $t->string('currency', 3)->nullable();
            $t->string('owner', 120);
            $t->string('unit', 16);
            $t->string('status', 24)->default('DRAFT');
            $t->foreignUuid('created_by')->constrained('users');
            $t->timestampTz('submitted_at')->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('approved_at')->nullable();
            $t->foreignUuid('rejected_by')->nullable()->constrained('users');
            $t->text('rejection_reason')->nullable();
            $t->timestampTz('retired_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code', 'version']);
            $t->index(['tenant_id', 'code', 'status']);
        });
        DB::statement("ALTER TABLE kpi_definitions ADD CONSTRAINT kpi_definitions_status_valid CHECK (status IN ('DRAFT','PENDING_APPROVAL','ACTIVE','REJECTED','RETIRED'))");
        DB::statement('ALTER TABLE kpi_definitions ADD CONSTRAINT kpi_definitions_checker CHECK (approved_by IS NULL OR approved_by <> created_by)');
        DB::statement("CREATE UNIQUE INDEX kpi_definitions_one_active ON kpi_definitions (tenant_id, code) WHERE status = 'ACTIVE'");
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_definitions');
    }
};
