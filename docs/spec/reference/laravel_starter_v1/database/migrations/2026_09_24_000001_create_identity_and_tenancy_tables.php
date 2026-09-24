<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->string('code')->unique(); $table->string('name');
            $table->string('tenant_type'); $table->char('country_code', 2)->default('CM'); $table->char('base_currency', 3)->default('XAF');
            $table->string('timezone')->default('Africa/Douala'); $table->string('default_language')->default('fr'); $table->string('secondary_language')->nullable();
            $table->string('status')->default('DRAFT'); $table->string('data_region')->nullable(); $table->jsonb('configuration_json')->nullable(); $table->timestampsTz();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->string('first_name'); $table->string('middle_name')->nullable(); $table->string('last_name');
            $table->string('display_name')->nullable(); $table->string('email')->nullable()->unique(); $table->string('phone')->nullable()->index(); $table->string('password_hash');
            $table->string('status')->default('ACTIVE'); $table->timestampTz('email_verified_at')->nullable(); $table->timestampTz('phone_verified_at')->nullable();
            $table->boolean('mfa_enabled')->default(false); $table->string('mfa_method')->nullable(); $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('password_changed_at')->nullable(); $table->timestampTz('locked_until')->nullable(); $table->unsignedInteger('failed_login_count')->default(0);
            $table->string('locale')->default('fr'); $table->string('timezone')->default('Africa/Douala'); $table->timestampsTz();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->uuid('uuid')->unique(); $table->foreignId('tenant_id')->nullable()->constrained('tenants'); $table->string('code'); $table->string('name');
            $table->text('description')->nullable(); $table->string('role_type')->default('BUSINESS'); $table->string('scope_type')->default('TENANT'); $table->boolean('system_role')->default(false); $table->string('status')->default('ACTIVE');
            $table->unique(['tenant_id','code']);
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id(); $table->string('code')->unique(); $table->string('module'); $table->string('resource'); $table->string('action'); $table->string('sensitivity_level')->default('STANDARD'); $table->text('description')->nullable();
        });
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles'); $table->foreignId('permission_id')->constrained('permissions'); $table->jsonb('conditions_json')->nullable(); $table->primary(['role_id','permission_id']);
        });
    }

    public function down(): void
    {
        // Reverse in dependency-safe order in production migrations.
        // Scaffold intentionally avoids destructive blanket drops.
    }
};
