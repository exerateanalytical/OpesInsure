<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/** Push the new broker.claims.file grant (BROKER_STAFF / SUPERVISOR / ADMIN, RoleCatalogue) to stored tenant roles. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles')) {
            Artisan::call('rbac:sync-role-permissions');
        }
    }

    public function down(): void {}
};
