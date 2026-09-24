<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('tenant_id')->constrained('tenants'); $table->string('party_type'); $table->string('customer_number')->nullable();
            $table->string('status')->default('ACTIVE'); $table->string('risk_status')->nullable(); $table->timestampsTz(); $table->unique(['tenant_id','customer_number']);
        });
        Schema::create('organizations', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('tenant_id')->constrained('tenants'); $table->unsignedBigInteger('organization_type_id')->nullable();
            $table->foreignId('party_id')->nullable()->unique()->constrained('parties'); $table->foreignId('parent_organization_id')->nullable()->constrained('organizations');
            $table->string('legal_name'); $table->string('trade_name')->nullable(); $table->string('registration_number')->nullable(); $table->string('taxpayer_number')->nullable();
            $table->string('regulatory_identifier')->nullable(); $table->char('country_code',2)->default('CM'); $table->string('website')->nullable(); $table->string('primary_email')->nullable();
            $table->string('primary_phone')->nullable(); $table->string('status')->default('DRAFT'); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable(); $table->timestampsTz();
        });
        Schema::create('addresses', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('tenant_id')->constrained('tenants'); $table->foreignId('party_id')->nullable()->constrained('parties');
            $table->foreignId('organization_id')->nullable()->constrained('organizations'); $table->string('address_type'); $table->char('country_code',2)->default('CM');
            $table->unsignedBigInteger('region_id')->nullable(); $table->unsignedBigInteger('department_id')->nullable(); $table->unsignedBigInteger('municipality_id')->nullable(); $table->unsignedBigInteger('city_id')->nullable();
            $table->string('locality')->nullable(); $table->string('quarter')->nullable(); $table->text('street_address')->nullable(); $table->string('postal_code')->nullable();
            $table->decimal('latitude',10,7)->nullable(); $table->decimal('longitude',10,7)->nullable(); $table->boolean('is_primary')->default(false); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable();
        });
        Schema::create('persons', function (Blueprint $table) {
            $table->foreignId('party_id')->primary()->constrained('parties'); $table->string('title_code')->nullable(); $table->string('first_name'); $table->string('middle_name')->nullable(); $table->string('last_name');
            $table->string('former_name')->nullable(); $table->date('date_of_birth')->nullable(); $table->string('place_of_birth')->nullable(); $table->string('sex_code')->nullable(); $table->string('marital_status_code')->nullable();
            $table->char('nationality_code',2)->nullable(); $table->unsignedBigInteger('occupation_id')->nullable(); $table->foreignId('employer_party_id')->nullable()->constrained('parties');
            $table->string('primary_phone')->nullable()->index(); $table->string('secondary_phone')->nullable(); $table->string('primary_email')->nullable()->index(); $table->string('preferred_language')->default('fr');
        });
        Schema::create('organization_branches', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('tenant_id')->constrained('tenants'); $table->foreignId('organization_id')->constrained('organizations'); $table->foreignId('parent_branch_id')->nullable()->constrained('organization_branches');
            $table->string('code'); $table->string('name'); $table->string('branch_type')->nullable(); $table->foreignId('address_id')->nullable()->constrained('addresses'); $table->string('phone')->nullable(); $table->string('email')->nullable();
            $table->foreignId('manager_user_id')->nullable()->constrained('users'); $table->string('status')->default('ACTIVE'); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable(); $table->timestampsTz(); $table->unique(['organization_id','code']);
        });
        Schema::create('departments', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('tenant_id')->constrained('tenants'); $table->foreignId('organization_id')->constrained('organizations'); $table->foreignId('branch_id')->nullable()->constrained('organization_branches');
            $table->foreignId('parent_department_id')->nullable()->constrained('departments'); $table->string('code'); $table->string('name'); $table->string('status')->default('ACTIVE'); $table->unique(['organization_id','code']);
        });
        Schema::create('user_organization_memberships', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained('users'); $table->foreignId('tenant_id')->constrained('tenants'); $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('branch_id')->nullable()->constrained('organization_branches'); $table->foreignId('department_id')->nullable()->constrained('departments'); $table->string('job_title')->nullable(); $table->string('employee_number')->nullable();
            $table->boolean('is_primary')->default(false); $table->string('status')->default('ACTIVE'); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable();
        });
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users'); $table->foreignId('role_id')->constrained('roles'); $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('organization_id')->nullable()->constrained('organizations'); $table->foreignId('branch_id')->nullable()->constrained('organization_branches'); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable();
            $table->unique(['user_id','role_id','tenant_id','organization_id','branch_id'], 'user_roles_scope_unique');
        });
        Schema::create('party_relationships', function (Blueprint $table) {
            $table->id(); $table->foreignId('tenant_id')->constrained('tenants'); $table->foreignId('from_party_id')->constrained('parties'); $table->foreignId('to_party_id')->constrained('parties'); $table->string('relationship_type');
            $table->string('relationship_subtype')->nullable(); $table->decimal('percentage',9,6)->nullable(); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable(); $table->string('status')->default('ACTIVE');
        });
        Schema::create('party_roles', function (Blueprint $table) {
            $table->id(); $table->foreignId('party_id')->constrained('parties'); $table->string('role_type'); $table->string('context_type'); $table->unsignedBigInteger('context_id'); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable(); $table->index(['context_type','context_id']);
        });
        Schema::create('ownership_interests', function (Blueprint $table) {
            $table->id(); $table->foreignId('organization_party_id')->constrained('parties'); $table->foreignId('owner_party_id')->constrained('parties'); $table->decimal('ownership_percentage',9,6); $table->decimal('voting_percentage',9,6)->nullable();
            $table->string('control_type')->nullable(); $table->boolean('is_ubo')->default(false); $table->string('verification_status')->default('UNVERIFIED'); $table->timestampTz('effective_from')->nullable(); $table->timestampTz('effective_until')->nullable();
        });
    }

    public function down(): void
    {
        // Reverse in dependency-safe order in production migrations.
        // Scaffold intentionally avoids destructive blanket drops.
    }
};
