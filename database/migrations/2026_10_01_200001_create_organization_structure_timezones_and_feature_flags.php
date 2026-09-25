<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 2 / agent 2D — additive only.
 *  REQ-TEN-001 tenants.type constrained to the SCF tenant kinds (CARRIER legacy alias of INSURER; AGENCY legacy value offered by the old admin form).
 *  REQ-TEN-002 branch hierarchy + capabilities, tenant_departments.
 *  REQ-ORG-001 agent hierarchy on partners (supervisor, agent type, branch).
 *  REQ-SEC-004 feature_flags scoped by environment/country/tenant/branch/product.
 *  REQ-TMP-003 platform/tenant/user timezone settings (branch column already exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('timezone', 64)->nullable(); // NULL = inherit platform default
        });
        // NOT VALID: enforced for new/updated rows without failing on unknown legacy production values.
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_type_check CHECK (type IN ('PLATFORM','INSURER','CARRIER','BROKER','REINSURER','PROVIDER_NETWORK','CORPORATE','AGENCY')) NOT VALID");

        Schema::table('users', function (Blueprint $t) {
            $t->string('display_timezone', 64)->nullable(); // NULL = follow tenant/branch
        });

        if (Schema::hasTable('platform_settings')) {
            Schema::table('platform_settings', function (Blueprint $t) {
                $t->string('default_timezone', 64)->nullable(); // NULL = config('app.timezone') = Africa/Douala
            });
        }

        Schema::table('tenant_branches', function (Blueprint $t) {
            $t->uuid('parent_branch_id')->nullable();
            $t->jsonb('capabilities')->default('[]');
            $t->foreign('parent_branch_id')->references('id')->on('tenant_branches')->nullOnDelete();
            $t->index('parent_branch_id');
        });
        // REQ-TMP-003: a branch with no timezone of its own inherits the tenant's.
        // Existing rows keep their stored value; only new rows stop defaulting to Douala.
        DB::statement('ALTER TABLE tenant_branches ALTER COLUMN timezone DROP NOT NULL');
        DB::statement('ALTER TABLE tenant_branches ALTER COLUMN timezone DROP DEFAULT');

        Schema::create('tenant_departments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('branch_id')->nullable()->constrained('tenant_branches')->nullOnDelete();
            $t->string('code', 40);
            $t->string('name', 160);
            $t->string('status', 24)->default('ACTIVE');
            $t->foreignUuid('manager_user_id')->nullable()->constrained('users');
            $t->string('queue_code', 64)->nullable();
            $t->jsonb('limits')->default('{}');
            $t->timestampsTz();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::table('partners', function (Blueprint $t) {
            $t->uuid('supervisor_partner_id')->nullable();
            $t->uuid('branch_id')->nullable();
            $t->string('agent_type', 32)->nullable(); // SCF §32: EMPLOYEE, INDEPENDENT, SUB_AGENT, BRANCH, CORPORATE_REPRESENTATIVE (null for non-agents)
            $t->foreign('supervisor_partner_id')->references('id')->on('partners')->nullOnDelete();
            $t->foreign('branch_id')->references('id')->on('tenant_branches')->nullOnDelete();
            $t->index('supervisor_partner_id');
        });

        Schema::create('partner_hierarchy_history', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('partner_id')->constrained()->cascadeOnDelete();
            $t->uuid('from_supervisor_partner_id')->nullable();
            $t->uuid('to_supervisor_partner_id')->nullable();
            $t->uuid('from_branch_id')->nullable();
            $t->uuid('to_branch_id')->nullable();
            $t->string('reason_code', 64);
            $t->text('notes')->nullable();
            $t->foreignUuid('actor_id')->nullable()->constrained('users');
            $t->timestampTz('occurred_at');
        });

        Schema::create('feature_flags', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('key', 96);
            $t->string('environment', 24)->nullable();
            $t->string('country_code', 2)->nullable();
            $t->uuid('tenant_id')->nullable();
            $t->uuid('branch_id')->nullable();
            $t->string('product_code', 64)->nullable();
            $t->boolean('enabled');
            $t->text('description')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->foreign('branch_id')->references('id')->on('tenant_branches')->cascadeOnDelete();
            $t->index('key');
        });
        DB::statement("CREATE UNIQUE INDEX feature_flags_scope_unique ON feature_flags (key, COALESCE(environment,''), COALESCE(country_code,''), COALESCE(tenant_id::text,''), COALESCE(branch_id::text,''), COALESCE(product_code,''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('partner_hierarchy_history');
        Schema::table('partners', function (Blueprint $t) {
            $t->dropForeign(['supervisor_partner_id']);
            $t->dropForeign(['branch_id']);
            $t->dropColumn(['supervisor_partner_id', 'branch_id', 'agent_type']);
        });
        Schema::dropIfExists('tenant_departments');
        Schema::table('tenant_branches', function (Blueprint $t) {
            $t->dropForeign(['parent_branch_id']);
            $t->dropColumn(['parent_branch_id', 'capabilities']);
        });
        DB::statement("UPDATE tenant_branches SET timezone = 'Africa/Douala' WHERE timezone IS NULL");
        DB::statement("ALTER TABLE tenant_branches ALTER COLUMN timezone SET DEFAULT 'Africa/Douala'");
        DB::statement('ALTER TABLE tenant_branches ALTER COLUMN timezone SET NOT NULL');
        if (Schema::hasColumn('platform_settings', 'default_timezone')) {
            Schema::table('platform_settings', fn (Blueprint $t) => $t->dropColumn('default_timezone'));
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('display_timezone'));
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_type_check');
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('timezone'));
    }
};
