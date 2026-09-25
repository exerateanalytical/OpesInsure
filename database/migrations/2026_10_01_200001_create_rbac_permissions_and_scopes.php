<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-RBAC-001 / REQ-RBAC-002 (additive).
 *
 *  - permissions: the governed catalogue of module.resource.action strings,
 *    synchronised from config/permissions.php by PermissionCatalogue::sync().
 *    roles.permissions (jsonb) stays the canonical grant store — no separate
 *    role_permissions table, so there is exactly one place a grant lives.
 *  - roles.data_scope: optional override of the role's default data scope
 *    (RoleCatalogue::defaultScope()).
 *  - tenant_memberships.team_code: grouping for the TEAM scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 128)->unique();
            $t->string('module', 64);
            $t->string('resource', 64)->nullable();
            $t->string('action', 64);
            $t->string('category', 64);
            $t->boolean('is_business_data')->default(true);
            $t->text('description')->nullable();
            $t->jsonb('suggested_roles')->default('[]');
            $t->timestampsTz();
            $t->index('module');
        });

        Schema::table('roles', function (Blueprint $t) {
            $t->string('data_scope', 32)->nullable();
        });
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_data_scope_allowed CHECK (data_scope IS NULL OR data_scope IN ('OWN','ASSIGNED','TEAM','BRANCH','ORGANIZATION','CARRIER_RELATIONSHIP','TENANT','PLATFORM','REGULATOR_READ'))");

        Schema::table('tenant_memberships', function (Blueprint $t) {
            $t->string('team_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_memberships', fn (Blueprint $t) => $t->dropColumn('team_code'));
        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_data_scope_allowed');
        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('data_scope'));
        Schema::dropIfExists('permissions');
    }
};
