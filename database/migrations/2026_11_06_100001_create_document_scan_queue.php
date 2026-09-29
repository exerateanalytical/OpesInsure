<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queue-based malware scanning (Q1). Additive only: documents.scan_status keeps its column and gains the values
 * PENDING_SCAN / SCAN_UNAVAILABLE (a plain string(24), no check constraint to change).
 *  - document_scan_queue: one row per uploaded document awaiting / having had a real scan (attempts, backoff, verdict).
 *  - document_pending_attachments: what the uploader already asked to do with a held file (e.g. attach as claim
 *    evidence), performed automatically once the file scans CLEAN, cancelled if it is INFECTED.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_scan_queue')) {
            Schema::create('document_scan_queue', function (Blueprint $t) {
                $t->uuid('document_id')->primary();
                $t->uuid('tenant_id')->index();
                $t->string('disk', 32)->default('local');
                $t->string('status', 24)->index();
                $t->unsignedInteger('attempts')->default(0);
                $t->timestampTz('last_attempt_at')->nullable();
                $t->timestampTz('next_attempt_at')->nullable()->index();
                $t->string('last_error', 500)->nullable();
                $t->string('verdict', 500)->nullable();
                $t->timestampTz('scanned_at')->nullable();
                $t->timestampTz('quarantined_at')->nullable();
                $t->timestampTz('notified_at')->nullable();
                $t->uuid('uploaded_by')->nullable();
                $t->timestampsTz();
            });
        }

        if (! Schema::hasTable('document_pending_attachments')) {
            Schema::create('document_pending_attachments', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id')->index();
                $t->uuid('document_id')->index();
                $t->string('target_type', 32);
                $t->uuid('target_id');
                $t->jsonb('payload')->default('{}');
                $t->uuid('requested_by');
                $t->string('status', 24)->default('PENDING')->index();
                $t->string('result_id', 64)->nullable();
                $t->string('error', 500)->nullable();
                $t->timestampTz('completed_at')->nullable();
                $t->timestampsTz();
                $t->unique(['document_id', 'target_type', 'target_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_pending_attachments');
        Schema::dropIfExists('document_scan_queue');
    }
};
