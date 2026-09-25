<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent B3 — REQ-API-006 (developer portal: sandbox keys, usage metering, per-client limits, consent),
 * REQ-API-007 (carrier connector framework) and REQ-IAM-004 support columns.
 *
 * Extends existing tables wherever possible: integration_clients (limits), carrier_exchange_messages
 * (retry/backoff + manual fallback queue — no separate queue table), external_record_mappings (conflict
 * resolution). claim_carrier_signing_keys stays the one carrier key table (reused for all carrier traffic).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_clients', function (Blueprint $t): void {
            $t->unsignedInteger('sandbox_rate_limit_per_minute')->default(30);
        });

        // Additional credentials of one integration client (sandbox keys, rotated production keys).
        // Each row is a real Passport client-credentials client.
        Schema::create('integration_client_keys', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('integration_client_id')->constrained()->cascadeOnDelete();
            $t->string('environment', 16); // sandbox|production
            $t->uuid('oauth_client_id')->unique();
            $t->string('label', 120)->nullable();
            $t->string('status', 16)->default('ACTIVE'); // ACTIVE|REVOKED
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampTz('last_used_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->foreignUuid('revoked_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->index(['integration_client_id', 'status']);
        });

        // Daily usage buckets per client / environment / scope (metering).
        Schema::create('integration_client_usage', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('integration_client_id')->constrained()->cascadeOnDelete();
            $t->date('usage_date');
            $t->string('environment', 16);
            $t->string('scope', 120);
            $t->unsignedBigInteger('allowed_count')->default(0);
            $t->unsignedBigInteger('denied_count')->default(0);
            $t->unsignedBigInteger('rate_limited_count')->default(0);
            $t->timestampsTz();
            $t->unique(['integration_client_id', 'usage_date', 'environment', 'scope'], 'icu_client_day_env_scope_unique');
        });

        // A tenant's consent for a partner client to act on its data, limited to a subset of the client's scopes.
        Schema::create('integration_client_consents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('integration_client_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('tenant_id')->constrained();
            $t->jsonb('scopes');
            $t->string('status', 16)->default('GRANTED'); // GRANTED|REVOKED
            $t->foreignUuid('granted_by')->constrained('users');
            $t->timestampTz('granted_at');
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->foreignUuid('revoked_by')->nullable()->constrained('users');
            $t->text('revocation_reason')->nullable();
            $t->timestampsTz();
            $t->index(['integration_client_id', 'tenant_id', 'status']);
        });

        Schema::create('carrier_connector_configs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->unique()->constrained();
            $t->foreignUuid('integration_client_id')->nullable()->constrained(); // for external_record_mappings
            $t->string('transport', 16); // API|MANUAL
            $t->text('base_url_encrypted')->nullable();
            $t->jsonb('endpoints')->default('{}'); // message_type => path
            $t->string('signing_key_id', 64)->nullable(); // claim_carrier_signing_keys.key_id
            $t->unsignedSmallInteger('timeout_seconds')->default(15);
            $t->unsignedSmallInteger('max_attempts')->default(5);
            $t->unsignedInteger('base_backoff_seconds')->default(60);
            $t->string('status', 16)->default('ACTIVE'); // ACTIVE|DISABLED
            $t->foreignUuid('updated_by')->nullable()->constrained('users');
            $t->timestampsTz();
        });

        Schema::table('carrier_exchange_messages', function (Blueprint $t): void {
            $t->timestampTz('last_attempted_at')->nullable();
            $t->unsignedSmallInteger('last_response_status')->nullable();
            $t->timestampTz('fallback_queued_at')->nullable();
            $t->text('fallback_reason')->nullable();
            $t->timestampTz('fallback_resolved_at')->nullable();
            $t->foreignUuid('fallback_resolved_by')->nullable()->constrained('users');
            $t->string('fallback_resolution', 32)->nullable(); // SENT_MANUALLY|REQUEUED|CANCELLED
            $t->text('fallback_note')->nullable();
            $t->index(['status', 'next_attempt_at']);
        });

        Schema::table('external_record_mappings', function (Blueprint $t): void {
            $t->timestampTz('conflict_detected_at')->nullable();
            $t->timestampTz('conflict_resolved_at')->nullable();
            $t->foreignUuid('conflict_resolved_by')->nullable()->constrained('users');
            $t->string('conflict_resolution', 32)->nullable(); // KEEP_OPESINSURE|ACCEPT_EXTERNAL
        });
    }

    public function down(): void
    {
        Schema::table('external_record_mappings', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('conflict_resolved_by');
            $t->dropColumn(['conflict_detected_at', 'conflict_resolved_at', 'conflict_resolution']);
        });
        Schema::table('carrier_exchange_messages', function (Blueprint $t): void {
            $t->dropIndex(['status', 'next_attempt_at']);
            $t->dropConstrainedForeignId('fallback_resolved_by');
            $t->dropColumn(['last_attempted_at', 'last_response_status', 'fallback_queued_at', 'fallback_reason', 'fallback_resolved_at', 'fallback_resolution', 'fallback_note']);
        });
        Schema::dropIfExists('carrier_connector_configs');
        Schema::dropIfExists('integration_client_consents');
        Schema::dropIfExists('integration_client_usage');
        Schema::dropIfExists('integration_client_keys');
        Schema::table('integration_clients', function (Blueprint $t): void {
            $t->dropColumn('sandbox_rate_limit_per_minute');
        });
    }
};
