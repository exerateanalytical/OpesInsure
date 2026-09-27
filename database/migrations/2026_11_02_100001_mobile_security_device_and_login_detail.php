<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile audit 2026-09-27 B1/B2: device detail (model, OS, app version, first seen, last auth method, attestation status,
 * approximate server-derived country/city — never GPS) and login-activity detail (outcome, event type, app version,
 * masked IP). Existing rows keep nulls; login rows default to a SUCCESS LOGIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $t) {
            $t->string('model', 120)->nullable();
            $t->string('os_version', 40)->nullable();
            $t->string('app_version', 40)->nullable();
            $t->timestampTz('first_seen_at')->nullable();
            $t->string('last_auth_method', 32)->nullable();
            $t->string('attestation_status', 24)->nullable();
            $t->string('approx_country', 2)->nullable();
            $t->string('approx_city', 120)->nullable();
        });
        Schema::table('login_activities', function (Blueprint $t) {
            $t->string('outcome', 16)->default('SUCCESS');
            $t->string('event_type', 40)->default('LOGIN');
            $t->string('app_version', 40)->nullable();
            $t->string('masked_ip', 64)->nullable();
            $t->index(['user_id', 'event_type', 'occurred_at']);
        });
        // Non-login security events (logout-all, step-up, attestation) carry no sign-in method.
        Schema::table('login_activities', fn (Blueprint $t) => $t->string('method', 24)->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('login_activities', function (Blueprint $t) {
            $t->dropIndex(['user_id', 'event_type', 'occurred_at']);
            $t->dropColumn(['outcome', 'event_type', 'app_version', 'masked_ip']);
        });
        Schema::table('user_devices', fn (Blueprint $t) => $t->dropColumn(['model', 'os_version', 'app_version', 'first_seen_at', 'last_auth_method', 'attestation_status', 'approx_country', 'approx_city']));
    }
};
