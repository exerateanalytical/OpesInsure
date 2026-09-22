<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('slug', 80)->nullable()->unique(); $t->string('registration_number', 80)->nullable();
            $t->string('tax_number', 80)->nullable(); $t->string('primary_locale', 5)->default('en');
            $t->foreignUuid('parent_tenant_id')->nullable()->constrained('tenants'); $t->timestampTz('activated_at')->nullable();
            $t->unique(['country_code', 'registration_number']);
        });
        Schema::create('roles', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->nullable()->constrained(); $t->string('code', 64); $t->jsonb('permissions')->default('[]');
            $t->boolean('is_system')->default(false); $t->timestampsTz(); $t->unique(['tenant_id', 'code']);
        });
        Schema::create('membership_roles', function (Blueprint $t) {
            $t->foreignUuid('membership_id')->constrained('tenant_memberships')->cascadeOnDelete(); $t->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $t->primary(['membership_id', 'role_id']);
        });
        Schema::create('tenant_invitations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('tenant_id')->constrained(); $t->string('recipient_email')->nullable(); $t->string('recipient_phone_e164')->nullable();
            $t->string('role_code', 64); $t->string('token_hash', 64)->unique(); $t->string('status', 24)->default('PENDING'); $t->timestampTz('expires_at');
            $t->foreignUuid('invited_by')->constrained('users'); $t->timestampTz('accepted_at')->nullable(); $t->timestampsTz();
        });
        Schema::create('user_devices', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('user_id')->constrained()->cascadeOnDelete(); $t->string('device_fingerprint', 128); $t->string('name')->nullable();
            $t->string('platform', 24)->nullable(); $t->timestampTz('last_seen_at'); $t->timestampTz('trusted_at')->nullable(); $t->timestampTz('revoked_at')->nullable();
            $t->jsonb('security_metadata')->default('{}'); $t->timestampsTz(); $t->unique(['user_id', 'device_fingerprint']);
        });
        Schema::create('mfa_methods', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('user_id')->constrained()->cascadeOnDelete(); $t->string('type', 24); $t->text('secret_encrypted')->nullable();
            $t->string('destination_masked')->nullable(); $t->timestampTz('verified_at')->nullable(); $t->timestampTz('disabled_at')->nullable(); $t->timestampsTz();
        });
        Schema::create('verification_challenges', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('user_id')->nullable()->constrained(); $t->string('purpose', 32); $t->string('channel', 16); $t->string('destination_hash', 64);
            $t->string('code_hash'); $t->unsignedSmallInteger('attempts')->default(0); $t->unsignedSmallInteger('max_attempts')->default(5); $t->timestampTz('expires_at');
            $t->timestampTz('consumed_at')->nullable(); $t->string('request_ip_hash', 64)->nullable(); $t->timestampsTz();
        });
        Schema::create('consents', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('party_id')->constrained(); $t->string('purpose', 64); $t->string('notice_version', 32); $t->string('status', 16);
            $t->string('channel', 24); $t->timestampTz('given_at'); $t->timestampTz('withdrawn_at')->nullable(); $t->jsonb('evidence')->default('{}'); $t->timestampsTz();
        });
        Schema::create('party_addresses', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('party_id')->constrained()->cascadeOnDelete(); $t->string('type', 24); $t->string('country_code', 2)->default('CM');
            $t->string('region')->nullable(); $t->string('city'); $t->string('district')->nullable(); $t->text('line1')->nullable(); $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable(); $t->boolean('is_primary')->default(false); $t->timestampsTz();
        });
        Schema::create('partner_licences', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('partner_id')->constrained()->cascadeOnDelete(); $t->string('authority', 64); $t->string('licence_type', 64);
            $t->string('licence_number'); $t->date('issued_on')->nullable(); $t->date('expires_on')->nullable(); $t->string('status', 24)->default('PENDING_VERIFICATION');
            $t->foreignUuid('evidence_document_id')->nullable()->constrained('documents'); $t->foreignUuid('verified_by')->nullable()->constrained('users'); $t->timestampTz('verified_at')->nullable();
            $t->text('verification_notes')->nullable(); $t->timestampsTz(); $t->unique(['authority', 'licence_number']);
        });
        Schema::create('attribution_disputes', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignUuid('attribution_id')->constrained('customer_attributions'); $t->foreignUuid('raised_by_partner_id')->nullable()->constrained('partners');
            $t->string('reason_code', 64); $t->text('description'); $t->string('status', 24)->default('OPEN'); $t->jsonb('evidence')->default('[]');
            $t->foreignUuid('assigned_to')->nullable()->constrained('users'); $t->foreignUuid('resolved_by')->nullable()->constrained('users'); $t->string('resolution_code', 64)->nullable();
            $t->text('resolution_notes')->nullable(); $t->timestampTz('resolved_at')->nullable(); $t->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach (['attribution_disputes','partner_licences','party_addresses','consents','verification_challenges','mfa_methods','user_devices','tenant_invitations','membership_roles','roles'] as $table) Schema::dropIfExists($table);
        Schema::table('tenants', function (Blueprint $t) { $t->dropConstrainedForeignId('parent_tenant_id'); $t->dropUnique(['country_code','registration_number']); $t->dropColumn(['slug','registration_number','tax_number','primary_locale','activated_at']); });
    }
};
