<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S12 monitoring: unhandled exceptions grouped by fingerprint (ErrorEventRecorder). Deliberately no request body,
 * headers, query string, IP or user id — only the route pattern, a keyed hash of the user id and a scrubbed message.
 * error_event_counts holds per-5-minute totals for the error-spike alert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('error_events')) {
            Schema::create('error_events', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->string('fingerprint', 64)->unique();
                $t->string('exception_class', 255);
                $t->string('message', 500)->nullable();
                $t->string('file', 255)->nullable();
                $t->unsignedInteger('line')->nullable();
                $t->string('route', 255)->nullable();
                $t->string('method', 8)->nullable();
                $t->string('user_id_hash', 64)->nullable();
                $t->string('first_release_id', 64)->nullable();
                $t->string('release_id', 64)->nullable();
                $t->unsignedBigInteger('occurrences')->default(1);
                $t->timestampTz('first_seen_at');
                $t->timestampTz('last_seen_at')->index();
                $t->string('status', 16)->default('OPEN')->index();
                $t->timestampTz('resolved_at')->nullable();
                $t->uuid('resolved_by')->nullable();
                $t->timestampsTz();
            });
        }

        if (! Schema::hasTable('error_event_counts')) {
            Schema::create('error_event_counts', function (Blueprint $t): void {
                $t->timestampTz('bucket_start')->primary();
                $t->unsignedInteger('total')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('error_event_counts');
        Schema::dropIfExists('error_events');
    }
};
