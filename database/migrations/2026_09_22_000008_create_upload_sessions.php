<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the generic resumable/chunked upload mechanism (Wave 12 offline-sync
 * batch — see App\Application\Uploads\ResumableUploadService): a client
 * claims an upload_sessions row up front (declaring total chunk count,
 * total byte size and mime type), PUTs chunks one at a time — safe to
 * resume after a dropped connection, since each chunk write is an upsert
 * keyed by (upload_session_id, chunk_index) — then finalizes once every
 * index has arrived. storage_key on a COMPLETED session is meant to be
 * handed to a domain-specific registration endpoint (e.g.
 * DocumentController::register()) by whichever batch (Documents/Support/
 * Claims) actually needs the finished file; this table only tracks the
 * transfer itself, never what the file means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('user_id')->constrained();
            $t->string('resource_type', 64);
            $t->string('mime_type', 100);
            $t->unsignedInteger('total_chunks');
            $t->unsignedBigInteger('total_size_bytes');
            $t->string('status', 16)->default('IN_PROGRESS');
            $t->string('expected_sha256', 64)->nullable();
            $t->string('storage_key', 500)->nullable();
            $t->timestampTz('expires_at');
            $t->timestampsTz();
            $t->index(['tenant_id', 'user_id']);
        });

        Schema::create('upload_chunks', function (Blueprint $t) {
            $t->foreignUuid('upload_session_id')->constrained('upload_sessions')->cascadeOnDelete();
            $t->unsignedInteger('chunk_index');
            $t->unsignedBigInteger('size_bytes');
            $t->timestampTz('received_at');
            $t->primary(['upload_session_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_chunks');
        Schema::dropIfExists('upload_sessions');
    }
};
