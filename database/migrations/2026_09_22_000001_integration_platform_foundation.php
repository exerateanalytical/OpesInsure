<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partner connection lifecycle + link to a real Passport client-credentials
        // OAuth client (registered by default in laravel/passport, previously unused
        // here). client_secret_hash is kept for backward compatibility but is no
        // longer the authentication path — Passport owns that now.
        Schema::table('integration_clients', function (Blueprint $t) {
            $t->uuid('oauth_client_id')->nullable()->after('client_id');
            $t->string('environment', 16)->default('sandbox')->after('rate_limit_per_minute');
            $t->timestampTz('certified_at')->nullable()->after('environment');
            $t->foreignUuid('certified_by')->nullable()->constrained('users')->after('certified_at');
            $t->timestampTz('activated_at')->nullable()->after('certified_by');
            $t->timestampTz('revoked_at')->nullable()->after('activated_at');
            $t->foreignUuid('revoked_by')->nullable()->constrained('users')->after('revoked_at');
            $t->text('revocation_reason')->nullable()->after('revoked_by');
            $t->index('oauth_client_id');
        });

        Schema::create('integration_client_status_history', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('integration_client_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->string('reason_code', 64)->nullable();
            $t->text('notes')->nullable();
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->timestampTz('occurred_at');
        });

        // The platform must be able to compute an HMAC over an outgoing webhook
        // payload on every delivery attempt, not just show the secret once. A
        // one-way hash (signing_secret_hash, already on this table) cannot do
        // that — only a reversibly-encrypted secret can.
        Schema::table('integration_webhook_subscriptions', function (Blueprint $t) {
            $t->text('signing_secret_encrypted')->nullable()->after('signing_secret_hash');
        });

        Schema::create('canonical_event_schemas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('event_name', 120);
            $t->unsignedSmallInteger('version')->default(1);
            $t->string('status', 16)->default('ACTIVE'); // DRAFT|ACTIVE|DEPRECATED
            $t->text('description')->nullable();
            $t->jsonb('json_schema');
            $t->jsonb('example_payload')->default('{}');
            $t->string('privacy_classification', 24)->default('INTERNAL'); // PUBLIC|INTERNAL|RESTRICTED
            $t->timestampTz('deprecated_at')->nullable();
            $t->timestampsTz();
            $t->unique(['event_name', 'version']);
        });

        Schema::create('external_record_mappings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->foreignUuid('integration_client_id')->constrained();
            $t->string('record_type', 64);
            $t->string('external_record_id', 190);
            $t->uuid('opesinsure_record_id');
            $t->string('source_of_truth', 24)->default('OPESINSURE'); // OPESINSURE|PARTNER
            $t->string('external_version', 64)->nullable();
            $t->unsignedInteger('opesinsure_version')->default(1);
            $t->timestampTz('last_external_modified_at')->nullable();
            $t->timestampTz('last_synchronized_at')->nullable();
            $t->string('synchronization_status', 24)->default('SYNCED'); // SYNCED|PENDING|CONFLICT|FAILED
            $t->string('conflict_status', 24)->nullable();
            $t->jsonb('metadata')->default('{}');
            $t->timestampsTz();
            $t->unique(['integration_client_id', 'record_type', 'external_record_id'], 'erm_client_type_external_unique');
            $t->index(['record_type', 'opesinsure_record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_record_mappings');
        Schema::dropIfExists('canonical_event_schemas');
        Schema::table('integration_webhook_subscriptions', function (Blueprint $t) {
            $t->dropColumn('signing_secret_encrypted');
        });
        Schema::dropIfExists('integration_client_status_history');
        Schema::table('integration_clients', function (Blueprint $t) {
            $t->dropConstrainedForeignId('certified_by');
            $t->dropConstrainedForeignId('revoked_by');
            $t->dropColumn(['oauth_client_id', 'environment', 'certified_at', 'activated_at', 'revoked_at', 'revocation_reason']);
        });
    }
};
