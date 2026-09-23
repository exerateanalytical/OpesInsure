<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs POST /mobile/issue-reports: a free-text "something is broken on
 * this screen" report the app user files in the moment, from wherever they
 * are in the app (including pre-auth screens — the guard reported the
 * sign-up flow missing entirely before it existed). Unlike telemetry_events
 * this deliberately accepts free text, since describing the problem is the
 * point; it is a separate table from telemetry_events precisely so that
 * pipe's allowlisted, non-PII contract is never diluted by this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_issue_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('route', 255);
            $t->string('platform', 32)->nullable();
            $t->string('app_version', 32)->nullable();
            $t->text('note');
            $t->string('status', 16)->default('OPEN');
            $t->string('ip_hash', 64)->nullable();
            $t->timestampsTz();
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_issue_reports');
    }
};
