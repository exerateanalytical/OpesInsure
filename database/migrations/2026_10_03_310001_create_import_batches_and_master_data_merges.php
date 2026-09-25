<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-IMP-001 generic import pipeline (upload → map → validate → duplicates → preview → approve → import → audit)
 * and REQ-MDM-007 maker-checker merge requests. Additive only: master_data_imports stays as the legacy history
 * of the first, master-data-only importer (read-only from now on).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->nullable();
            $t->string('target', 64);                  // ImportTarget::key(): master_data_values, vehicle_generations, vehicle_variants, ...
            $t->jsonb('target_params')->nullable();    // e.g. {"domain":"industries","list":"activity"}
            $t->string('format', 8);                   // csv, xlsx, json
            $t->string('filename')->nullable();
            $t->string('file_sha256', 64)->nullable();
            // UPLOADED → VALIDATED | FAILED → PENDING_APPROVAL → IMPORTED | REJECTED | CANCELLED
            $t->string('status', 24)->default('UPLOADED');
            $t->jsonb('source_columns')->nullable();
            $t->jsonb('mapping')->nullable();          // target field => source column
            $t->jsonb('raw_rows')->nullable();
            $t->jsonb('rows')->nullable();             // mapped rows
            $t->jsonb('report')->nullable();           // {valid, new, errors[], duplicates[], preview[]}
            $t->uuid('approval_request_id')->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->timestampTz('imported_at')->nullable();
            $t->unsignedInteger('imported_count')->default(0);
            $t->jsonb('result')->nullable();           // created ids / per-row outcome
            $t->timestampsTz();
            $t->index(['target', 'status']);
            $t->index(['tenant_id', 'created_at']);
        });

        Schema::create('master_data_merge_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('from_value_id');
            $t->uuid('into_value_id');
            $t->string('domain_code', 64);
            $t->string('list_code', 64);
            $t->string('status', 16)->default('PENDING'); // PENDING, MERGED, REJECTED, CANCELLED
            $t->text('reason')->nullable();
            $t->uuid('approval_request_id')->nullable();
            $t->uuid('requested_by')->nullable();
            $t->uuid('decided_by')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestampsTz();
            $t->foreign('from_value_id')->references('id')->on('master_data_values');
            $t->foreign('into_value_id')->references('id')->on('master_data_values');
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_data_merge_requests');
        Schema::dropIfExists('import_batches');
    }
};
