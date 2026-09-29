<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S13 — demo mode lives in the database, not in .env. NULL keeps the legacy
 * behaviour (DEMO_MODE_ENABLED from config/demo.php); true/false is the
 * operator's explicit decision, set by `php artisan demo:exit` / `demo:enter`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $t): void {
            $t->boolean('demo_mode_enabled')->nullable();
            $t->timestampTz('demo_mode_changed_at')->nullable();
            $t->string('demo_mode_changed_by', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', fn (Blueprint $t) => $t->dropColumn(['demo_mode_enabled', 'demo_mode_changed_at', 'demo_mode_changed_by']));
    }
};
