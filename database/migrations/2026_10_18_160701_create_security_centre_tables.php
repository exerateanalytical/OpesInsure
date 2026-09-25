<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Agent B7 — security centre: login activity (REQ-SEC-001), purpose-of-use
 * registry + enforcement log (REQ-SEC-003), device integrity attestations
 * (REQ-SEC-005), crash reports (REQ-MOB-007) and security-finding lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_activities', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('method', 24);
            $t->uuid('device_id')->nullable();
            $t->string('device_fingerprint_hash', 64)->nullable();
            $t->string('device_name', 120)->nullable();
            $t->string('platform', 24)->nullable();
            $t->string('ip_hash', 64)->nullable();
            $t->string('user_agent_hash', 64)->nullable();
            $t->string('country_code', 2)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->boolean('new_device')->default(false);
            $t->jsonb('anomaly_flags')->default('[]');
            $t->timestampTz('occurred_at')->index();
            $t->index(['user_id', 'occurred_at']);
        });

        Schema::create('processing_purposes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('code', 64)->unique();
            $t->string('name', 160);
            $t->string('lawful_basis', 32);
            $t->string('consent_purpose', 64)->nullable();
            $t->string('basis_status', 32)->default('PLATFORM_PROVISIONAL');
            $t->boolean('is_active')->default(true);
            $t->text('description')->nullable();
            $t->timestampsTz();
        });

        Schema::create('purpose_of_use_checks', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->nullable()->index();
            $t->uuid('party_id')->nullable()->index();
            $t->string('purpose_code', 64);
            $t->string('operation', 64);
            $t->string('decision', 16);
            $t->string('lawful_basis', 32)->nullable();
            $t->uuid('consent_id')->nullable();
            $t->string('refusal_code', 32)->nullable();
            $t->string('reference_type', 64)->nullable();
            $t->string('reference_id', 64)->nullable();
            $t->uuid('actor_id')->nullable();
            $t->timestampTz('occurred_at');
        });

        Schema::create('device_integrity_attestations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->string('platform', 16);
            $t->string('provider', 32);
            $t->string('verdict', 16);
            $t->string('verifier', 64);
            $t->jsonb('reasons')->default('[]');
            $t->string('nonce_hash', 64);
            $t->timestampTz('created_at');
        });

        Schema::create('mobile_crash_reports', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('fingerprint', 64);
            $t->string('platform', 16);
            $t->string('app_version', 32);
            $t->string('build', 32)->nullable();
            $t->string('os_version', 32)->nullable();
            $t->string('error_type', 120);
            $t->text('message')->nullable();
            $t->text('stack')->nullable();
            $t->unsignedInteger('occurrences')->default(1);
            $t->timestampTz('first_seen_at');
            $t->timestampTz('last_seen_at');
            $t->unique(['fingerprint', 'app_version']);
        });

        // Security findings register (REQ-SEC-001): the Wave 11 release-assurance
        // table becomes the platform-wide register — extended, not duplicated.
        Schema::table('security_findings', function (Blueprint $t): void {
            $t->uuid('tenant_id')->nullable()->index();
            $t->string('reference', 32)->nullable()->unique();
            $t->string('category', 48)->nullable();
            $t->string('affected_asset', 191)->nullable();
            $t->uuid('reported_by')->nullable();
            $t->text('remediation_plan')->nullable();
            $t->uuid('risk_accepted_by')->nullable();
            $t->timestampTz('risk_acceptance_expires_at')->nullable();
            $t->text('resolution_notes')->nullable();
        });

        Schema::create('security_finding_events', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('security_finding_id')->constrained()->cascadeOnDelete();
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->uuid('actor_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestampTz('occurred_at');
        });

        // Starter catalogue. Every lawful basis is PLATFORM_PROVISIONAL until
        // the owner / DPO confirms it against Cameroonian data-protection law.
        $now = now();
        $rows = [
            ['INSURANCE_SERVICES', 'Insurance contract servicing', 'CONTRACT', null],
            ['CLAIMS_PROCESSING', 'Claims handling', 'CONTRACT', null],
            ['MARKETING', 'Marketing communications', 'CONSENT', 'MARKETING'],
            ['DATA_SHARING', 'Sharing personal data with third parties', 'CONSENT', 'DATA_SHARING'],
            ['PARTNER_SHARING', 'Sharing with distribution partners', 'CONSENT', 'PARTNER_SHARING'],
            ['ANALYTICS', 'Product analytics', 'CONSENT', 'ANALYTICS'],
            ['WHATSAPP_UPDATES', 'WhatsApp service updates', 'CONSENT', 'WHATSAPP_UPDATES'],
            ['DSR_FULFILMENT', 'Answering a data-subject request', 'LEGAL_OBLIGATION', null],
            ['PORTABILITY_CUSTOMER_REQUEST', 'Portability export requested by the customer', 'LEGAL_OBLIGATION', null],
            ['PORTABILITY_REGULATOR_REQUEST', 'Portability export requested by the regulator', 'LEGAL_OBLIGATION', null],
            ['PORTABILITY_INTERMEDIARY_TRANSFER', 'Portability export to a new intermediary', 'CONSENT', 'DATA_SHARING'],
            ['PORTABILITY_CARRIER_TRANSFER', 'Portability export to a new carrier', 'CONSENT', 'DATA_SHARING'],
        ];
        DB::table('processing_purposes')->insert(array_map(fn ($r) => [
            'id' => (string) Str::uuid(), 'code' => $r[0], 'name' => $r[1], 'lawful_basis' => $r[2], 'consent_purpose' => $r[3],
            'basis_status' => 'PLATFORM_PROVISIONAL', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $rows));
    }

    public function down(): void
    {
        Schema::table('security_findings', fn (Blueprint $t) => $t->dropColumn(['tenant_id', 'reference', 'category', 'affected_asset', 'reported_by', 'remediation_plan', 'risk_accepted_by', 'risk_acceptance_expires_at', 'resolution_notes']));
        foreach (['security_finding_events', 'mobile_crash_reports', 'device_integrity_attestations', 'purpose_of_use_checks', 'processing_purposes', 'login_activities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
