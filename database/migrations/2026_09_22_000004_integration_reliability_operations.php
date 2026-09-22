<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Circuit breaker: a subscription whose endpoint keeps failing stops
        // being hammered — the plan is explicit that repeated failure "must
        // open a circuit, prevent request storms and move work into a
        // visible delayed state" (§15.3).
        Schema::table('integration_webhook_subscriptions', function (Blueprint $t) {
            $t->unsignedInteger('consecutive_failures')->default(0)->after('status');
            $t->string('circuit_state', 16)->default('CLOSED')->after('consecutive_failures'); // CLOSED|OPEN|HALF_OPEN
            $t->timestampTz('circuit_opened_at')->nullable()->after('circuit_state');
        });

        // duration_ms: real latency data for the health dashboard (p50/p95),
        // not a placeholder. replayed_by/is_manual_replay distinguish a
        // human-triggered replay from the worker's own automatic retry, so
        // the audit trail can tell them apart.
        Schema::table('integration_delivery_attempts', function (Blueprint $t) {
            $t->unsignedInteger('duration_ms')->nullable()->after('response_status');
            $t->boolean('is_manual_replay')->default(false)->after('duration_ms');
            $t->foreignUuid('replayed_by')->nullable()->constrained('users')->after('is_manual_replay');
        });
    }

    public function down(): void
    {
        Schema::table('integration_delivery_attempts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('replayed_by');
            $t->dropColumn(['duration_ms', 'is_manual_replay']);
        });
        Schema::table('integration_webhook_subscriptions', function (Blueprint $t) {
            $t->dropColumn(['consecutive_failures', 'circuit_state', 'circuit_opened_at']);
        });
    }
};
