<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authority_profiles', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('tenant_id')->constrained('tenants'); $table->foreignId('organization_id')->constrained('organizations'); $table->string('code'); $table->string('name'); $table->text('description')->nullable(); $table->string('status')->default('ACTIVE'); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable();
        });
        Schema::create('authority_profile_assignments', function (Blueprint $table) {
            $table->id(); $table->foreignId('authority_profile_id')->constrained('authority_profiles'); $table->foreignId('user_id')->constrained('users'); $table->foreignId('role_id')->nullable()->constrained('roles'); $table->foreignId('branch_id')->nullable()->constrained('organization_branches'); $table->unsignedBigInteger('product_id')->nullable(); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable();
        });
        Schema::create('authority_limits', function (Blueprint $table) {
            $table->id(); $table->foreignId('authority_profile_id')->constrained('authority_profiles'); $table->string('authority_type'); $table->unsignedBigInteger('insurance_class_id')->nullable(); $table->unsignedBigInteger('product_id')->nullable(); $table->char('currency',3)->nullable(); $table->decimal('max_amount',20,4)->nullable(); $table->decimal('max_percentage',9,6)->nullable(); $table->decimal('max_discount_percentage',9,6)->nullable(); $table->jsonb('conditions_json')->nullable(); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable();
        });
        Schema::create('master_data_domains', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->string('name_en'); $table->string('name_fr'); $table->boolean('hierarchical')->default(false); $table->boolean('tenant_extensible')->default(true); $table->string('status')->default('ACTIVE');
        });
        Schema::create('master_data_values', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('domain_id')->constrained('master_data_domains'); $table->foreignId('parent_id')->nullable()->constrained('master_data_values'); $table->string('canonical_code'); $table->string('label_en'); $table->string('label_fr'); $table->text('description_en')->nullable(); $table->text('description_fr')->nullable(); $table->integer('sort_order')->default(0); $table->char('country_code',2)->nullable(); $table->string('source_type')->default('PLATFORM_NORMALIZED'); $table->string('source_reference')->nullable(); $table->string('verification_status')->default('VERIFIED'); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable(); $table->string('status')->default('ACTIVE'); $table->unique(['domain_id','canonical_code']);
        });
        Schema::create('master_data_aliases', function (Blueprint $table) {
            $table->id(); $table->foreignId('master_data_value_id')->constrained('master_data_values'); $table->string('alias'); $table->string('language')->nullable(); $table->string('normalized_alias')->index();
        });
        Schema::create('master_data_suggestions', function (Blueprint $table) {
            $table->id(); $table->foreignId('domain_id')->constrained('master_data_domains'); $table->foreignId('submitted_by')->constrained('users'); $table->foreignId('tenant_id')->constrained('tenants'); $table->string('proposed_value'); $table->foreignId('proposed_parent_id')->nullable()->constrained('master_data_values'); $table->string('context_type')->nullable(); $table->unsignedBigInteger('context_id')->nullable(); $table->string('status')->default('SUBMITTED'); $table->foreignId('matched_master_value_id')->nullable()->constrained('master_data_values'); $table->foreignId('reviewed_by')->nullable()->constrained('users'); $table->timestampTz('reviewed_at')->nullable();
        });
        Schema::create('identity_documents', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('tenant_id')->constrained('tenants'); $table->foreignId('party_id')->constrained('parties'); $table->unsignedBigInteger('document_type_id'); $table->string('document_number')->nullable(); $table->char('country_code',2)->nullable(); $table->string('issuer')->nullable(); $table->date('issued_at')->nullable(); $table->date('expires_at')->nullable(); $table->string('verification_status')->default('UNVERIFIED'); $table->timestampTz('verified_at')->nullable(); $table->foreignId('verified_by')->nullable()->constrained('users'); $table->unsignedBigInteger('document_file_id')->nullable(); $table->string('status')->default('ACTIVE');
        });
        Schema::create('kyc_cases', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('tenant_id')->constrained('tenants'); $table->foreignId('party_id')->constrained('parties'); $table->string('case_number'); $table->string('customer_type'); $table->string('risk_level')->nullable(); $table->string('status')->default('NOT_STARTED'); $table->timestampTz('opened_at')->nullable(); $table->timestampTz('submitted_at')->nullable(); $table->timestampTz('reviewed_at')->nullable(); $table->timestampTz('approved_at')->nullable(); $table->foreignId('reviewed_by')->nullable()->constrained('users'); $table->timestampTz('next_review_at')->nullable(); $table->string('reason_code')->nullable(); $table->text('notes')->nullable(); $table->unique(['tenant_id','case_number']);
        });
        Schema::create('screening_checks', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('tenant_id')->constrained('tenants'); $table->foreignId('party_id')->constrained('parties'); $table->string('screening_type'); $table->string('provider_code')->nullable(); $table->string('request_reference')->nullable(); $table->timestampTz('performed_at'); $table->string('result'); $table->decimal('match_score',9,6)->nullable(); $table->string('status')->default('PENDING_REVIEW'); $table->foreignId('reviewed_by')->nullable()->constrained('users'); $table->timestampTz('reviewed_at')->nullable(); $table->unsignedBigInteger('case_id')->nullable(); $table->jsonb('raw_result_json')->nullable();
        });
    }

    public function down(): void
    {
        // Reverse in dependency-safe order in production migrations.
        // Scaffold intentionally avoids destructive blanket drops.
    }
};
