<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications in the reader's language: each inbox row keeps a stable
 * NotificationCatalog code and its parameters next to the English title/body,
 * so the mobile inbox, push and SMS render it in English or French.
 * Additive and production-safe: nullable, no backfill (older rows are
 * recognised from their English text when read).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $t) {
            if (! Schema::hasColumn('user_notifications', 'code')) {
                $t->string('code', 80)->nullable();
            }
            if (! Schema::hasColumn('user_notifications', 'params')) {
                $t->json('params')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $t) {
            if (Schema::hasColumn('user_notifications', 'params')) {
                $t->dropColumn('params');
            }
            if (Schema::hasColumn('user_notifications', 'code')) {
                $t->dropColumn('code');
            }
        });
    }
};
