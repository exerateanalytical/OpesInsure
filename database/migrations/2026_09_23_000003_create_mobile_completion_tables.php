<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables behind the mobile screens that had no backend at all: the
 * per-user notification inbox, push-token registry and notification
 * preferences (account/notifications, notifications/*). Everything else
 * the app expects (support, service requests, claims completion, partner
 * portals) maps onto tables that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->json('notification_preferences')->nullable();
        });

        Schema::create('user_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $t->string('type', 64);
            $t->string('title', 200);
            $t->text('body');
            $t->string('severity', 16)->default('INFO');
            $t->string('path', 255)->nullable();
            $t->timestampTz('read_at')->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'created_at']);
            $t->index(['user_id', 'read_at']);
        });

        Schema::create('user_push_tokens', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('token', 255)->unique();
            $t->string('platform', 16);
            $t->timestampTz('last_seen_at')->nullable();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_push_tokens');
        Schema::dropIfExists('user_notifications');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notification_preferences'));
    }
};
