<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carrier API connectors (first: Activa Assurances Cameroun over Activa's Azure API Management gateway).
 * Additive only: four new tables and one nullable, indexed column on policies.
 *
 *  - carrier_api_connections: one row per carrier / provider / environment. Secrets (subscription key and the
 *    per-service logins) are stored with Laravel's encrypted casts, written from the admin form and never read back.
 *  - carrier_api_calls: one row per outbound HTTP call (service, operation, status, duration, correlation id).
 *    No bodies, no headers, no personal data, no secrets.
 *  - carrier_api_sync_records: what has to reach the carrier for one of our records (policy, payment) and where
 *    it stands (SYNCED / RETRY_PENDING / FAILED …), with the carrier's own identifiers (idctr, policy id).
 *  - carrier_reference_data: the carrier's referential data (ReferentialData), idempotent by content hash.
 *  - policies.carrier_contract_reference: the carrier's contract number (Activa idctr / travel policy number).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('carrier_api_connections')) {
            Schema::create('carrier_api_connections', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->foreignUuid('carrier_id')->constrained('carriers');
                $t->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $t->string('provider', 32);
                $t->string('environment', 16)->default('SANDBOX');
                $t->string('status', 24)->default('CONFIG_REQUIRED');
                $t->text('subscription_key')->nullable();
                $t->text('service_credentials')->nullable();
                $t->jsonb('settings')->default('{}');
                $t->jsonb('service_health')->default('{}');
                $t->string('last_error_code', 64)->nullable();
                $t->timestampTz('last_tested_at')->nullable();
                $t->timestampTz('last_success_at')->nullable();
                $t->timestampTz('last_reference_sync_at')->nullable();
                $t->timestampTz('last_reconciled_at')->nullable();
                $t->uuid('updated_by')->nullable();
                $t->timestampsTz();
                $t->unique(['carrier_id', 'provider', 'environment']);
                $t->index(['provider', 'status']);
            });
        }

        if (! Schema::hasTable('carrier_api_calls')) {
            Schema::create('carrier_api_calls', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('connection_id')->index();
                $t->uuid('carrier_id');
                $t->string('service', 32);
                $t->string('operation', 96);
                $t->string('method', 8);
                $t->string('path', 255);
                $t->unsignedSmallInteger('http_status')->nullable();
                $t->string('outcome', 24);
                $t->unsignedSmallInteger('attempts')->default(1);
                $t->unsignedInteger('duration_ms')->default(0);
                $t->string('correlation_id', 64)->index();
                $t->string('subject_type', 48)->nullable();
                $t->uuid('subject_id')->nullable();
                $t->string('error_code', 64)->nullable();
                $t->timestampTz('created_at')->useCurrent();
                $t->index(['carrier_id', 'service', 'created_at']);
            });
        }

        if (! Schema::hasTable('carrier_api_sync_records')) {
            Schema::create('carrier_api_sync_records', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('connection_id')->nullable();
                $t->foreignUuid('carrier_id')->constrained('carriers');
                $t->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $t->string('subject_type', 48);
                $t->uuid('subject_id');
                $t->uuid('policy_id')->nullable()->index();
                $t->string('operation', 48);
                $t->string('status', 24)->default('PENDING');
                $t->unsignedInteger('attempts')->default(0);
                $t->timestampTz('next_attempt_at')->nullable();
                $t->timestampTz('last_attempt_at')->nullable();
                $t->string('last_error_code', 64)->nullable();
                $t->text('last_error')->nullable();
                $t->string('external_reference', 128)->nullable()->index();
                $t->string('external_policy_number', 128)->nullable();
                $t->jsonb('external_data')->default('{}');
                $t->uuid('document_id')->nullable();
                $t->timestampTz('synced_at')->nullable();
                $t->timestampsTz();
                $t->unique(['carrier_id', 'subject_type', 'subject_id', 'operation']);
                $t->index(['status', 'next_attempt_at']);
            });
        }

        if (! Schema::hasTable('carrier_reference_data')) {
            Schema::create('carrier_reference_data', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->foreignUuid('carrier_id')->constrained('carriers');
                $t->string('provider', 32);
                $t->string('domain', 96);
                $t->string('code', 128);
                $t->string('label', 500)->nullable();
                $t->jsonb('payload')->default('{}');
                $t->string('content_hash', 64);
                $t->string('status', 16)->default('ACTIVE');
                $t->uuid('mapped_value_id')->nullable();
                $t->timestampTz('first_seen_at');
                $t->timestampTz('last_seen_at');
                $t->timestampsTz();
                $t->unique(['carrier_id', 'provider', 'domain', 'code']);
            });
        }

        if (Schema::hasTable('policies') && ! Schema::hasColumn('policies', 'carrier_contract_reference')) {
            Schema::table('policies', function (Blueprint $t): void {
                $t->string('carrier_contract_reference', 128)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('policies', 'carrier_contract_reference')) {
            Schema::table('policies', function (Blueprint $t): void {
                $t->dropIndex(['carrier_contract_reference']);
                $t->dropColumn('carrier_contract_reference');
            });
        }
        Schema::dropIfExists('carrier_reference_data');
        Schema::dropIfExists('carrier_api_sync_records');
        Schema::dropIfExists('carrier_api_calls');
        Schema::dropIfExists('carrier_api_connections');
    }
};
