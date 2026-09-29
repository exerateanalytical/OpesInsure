<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S14 SMS providers (platform-level, configured by platform admins in Integrations → SMS providers).
 * sms_provider_connections.secrets is encrypted at rest (APP_KEY, encrypted:array cast) and never sent back to a form.
 * sms_messages is the delivery log: destinations are stored masked + hashed, never in clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_provider_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32);            // orange | twilio | africastalking | generic_http
            $table->string('label', 120);
            $table->string('role', 16)->default('STANDBY'); // PRIMARY | FALLBACK | STANDBY
            $table->string('status', 16)->default('ACTIVE'); // ACTIVE | DISABLED
            $table->json('settings')->nullable();      // non-secret: sender, URLs, templates
            $table->text('secrets')->nullable();       // encrypted:array
            $table->string('health_status', 16)->default('UNKNOWN'); // UNKNOWN | OK | FAILING
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->index(['status', 'role']);
        });

        Schema::create('sms_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('connection_id')->nullable()->index();
            $table->string('provider', 32)->nullable();
            $table->string('purpose', 24);             // OTP | NOTIFICATION | TEST
            $table->string('destination_masked', 32);
            $table->string('destination_hash', 64)->index();
            $table->string('encoding', 8);             // GSM7 | UCS2
            $table->unsignedSmallInteger('length');
            $table->unsignedSmallInteger('segments');
            $table->string('status', 16);              // SENT | FAILED | RATE_LIMITED | CONFIG_REQUIRED | REJECTED
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('provider_reference', 190)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_provider_connections');
    }
};
