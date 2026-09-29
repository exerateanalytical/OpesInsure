<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S1 — partner developer portal (DEV-001..014). Additive only:
 *  - integration_client_developers: which web users act for which integration client (the partner's own team);
 *  - integration_request_logs: one row per partner API call (metadata only — never headers, bodies or tokens);
 *  - api_changelog_entries: API versions & changelog, derived from docs/api/openapi.json diffs (api:changelog) or written by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_client_developers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('integration_client_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16)->default('DEVELOPER'); // OWNER|DEVELOPER|VIEWER
            $t->string('status', 16)->default('ACTIVE'); // ACTIVE|REVOKED
            $t->foreignUuid('granted_by')->nullable()->constrained('users');
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampsTz();
            $t->unique(['integration_client_id', 'user_id'], 'icd_client_user_unique');
            $t->index(['user_id', 'status']);
        });

        Schema::create('integration_request_logs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('integration_client_id')->constrained()->cascadeOnDelete();
            $t->uuid('integration_client_key_id')->nullable();
            $t->string('environment', 16);
            $t->string('method', 8);
            $t->string('route', 255); // route template (no ids / personal data)
            $t->string('scope', 120);
            $t->unsignedSmallInteger('status_code');
            $t->string('outcome', 16); // allowed|denied|rate_limited
            $t->string('denial_reason', 48)->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('request_id', 80)->nullable();
            $t->timestampTz('created_at');
            $t->index(['integration_client_id', 'created_at']);
        });

        Schema::create('api_changelog_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('api_version', 16)->default('v1');
            $t->string('operation', 255); // "GET /api/v1/..."
            $t->string('change_type', 16); // BASELINE|ADDED|CHANGED|DEPRECATED|REMOVED
            $t->boolean('breaking')->default(false);
            $t->boolean('partner_facing')->default(false);
            $t->string('fingerprint', 64)->nullable();
            $t->jsonb('contract')->default('{}'); // {scopes, params} at detection time (breaking-change diff)
            $t->text('summary')->nullable();
            $t->string('source', 16)->default('OPENAPI_DIFF'); // OPENAPI_DIFF|MANUAL
            $t->timestampTz('detected_at');
            $t->timestampsTz();
            $t->index(['operation', 'detected_at']);
            $t->index(['change_type', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_changelog_entries');
        Schema::dropIfExists('integration_request_logs');
        Schema::dropIfExists('integration_client_developers');
    }
};
