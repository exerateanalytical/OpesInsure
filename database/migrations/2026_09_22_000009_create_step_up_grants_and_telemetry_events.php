<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime batch: step-up authentication grants and client telemetry events.
 *
 * step_up_grants backs a short-lived, single-purpose, single-use elevated
 * grant issued after a fresh OTP re-verification (reusing the existing
 * verification_challenges mechanism with a purpose of
 * "STEP_UP:{PURPOSE}") — see App\Application\Security\MobileStepUpService.
 * Bound to user + tenant + purpose (device_id is nullable: the current
 * patched Expo client's step-up/verify call does not transmit a device
 * fingerprint the way login's otp/verify does, so device binding is
 * populated only when a caller supplies one). Presented back to a sensitive
 * mobile endpoint via the X-Step-Up-Grant header and consumed exactly once
 * by App\Interfaces\Http\Middleware\RequireStepUpGrant.
 *
 * telemetry_events backs POST /mobile/runtime/telemetry — an allowlisted,
 * non-PII, client-reported event stream (crash/error/usage). No user_id or
 * device identifier column on purpose: the contract explicitly forbids
 * accepting names, emails, phone numbers, addresses, IDs/documents, tokens,
 * PINs, OTPs, medical details, payment payloads or uploaded content, and the
 * endpoint is intentionally reachable unauthenticated (a crash on the splash
 * screen happens before login) — see App\Application\Runtime\MobileTelemetryService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('step_up_grants', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('device_id')->nullable()->constrained('user_devices')->nullOnDelete();
            $t->foreignUuid('challenge_id')->nullable()->constrained('verification_challenges')->nullOnDelete();
            $t->string('purpose', 64);
            $t->string('token_hash', 64)->unique();
            $t->timestampTz('expires_at');
            $t->timestampTz('consumed_at')->nullable();
            $t->timestampsTz();
            $t->index(['user_id', 'tenant_id', 'purpose']);
        });

        Schema::create('telemetry_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('event_name', 64);
            $t->string('correlation_id', 100);
            $t->string('app_version', 32);
            $t->string('release_channel', 32);
            $t->json('attributes')->nullable();
            $t->string('ip_hash', 64)->nullable();
            $t->timestampTz('created_at');
            $t->index(['event_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_events');
        Schema::dropIfExists('step_up_grants');
    }
};
