<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton row of admin-editable platform settings (support contacts, SMS /
 * WhatsApp / mail providers, OTP routing). Secrets are stored encrypted by the
 * model's encrypted casts, so their columns are TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            // Support contacts
            $table->string('support_email', 190)->nullable();
            $table->string('support_phone', 32)->nullable();
            $table->string('whatsapp_number', 32)->nullable();
            $table->string('partner_email', 190)->nullable();
            // Twilio
            $table->string('twilio_account_sid', 64)->nullable();
            $table->text('twilio_auth_token')->nullable();
            $table->string('twilio_sms_from', 32)->nullable();
            $table->string('twilio_whatsapp_from', 32)->nullable();
            $table->boolean('twilio_enabled')->default(true);
            // ETECH KEYS
            $table->string('etech_sms_login', 120)->nullable();
            $table->text('etech_sms_password')->nullable();
            $table->string('etech_sms_sender', 11)->nullable();
            $table->text('etech_rest_token')->nullable();
            $table->string('etech_whatsapp_template_name', 120)->nullable();
            $table->string('etech_whatsapp_template_language', 16)->nullable();
            $table->boolean('etech_sms_enabled')->default(true);
            $table->boolean('etech_whatsapp_enabled')->default(true);
            // OTP routing
            $table->string('otp_channel_priority', 64)->default('whatsapp,sms');
            $table->string('otp_provider_priority', 64)->default('etech,twilio');
            $table->boolean('require_contact_verification')->default(false);
            // Mail (SMTP)
            $table->string('mail_host', 190)->nullable();
            $table->unsignedInteger('mail_port')->nullable();
            $table->string('mail_username', 190)->nullable();
            $table->text('mail_password')->nullable();
            $table->string('mail_encryption', 16)->nullable();
            $table->string('mail_from_address', 190)->nullable();
            $table->string('mail_from_name', 120)->nullable();
            $table->boolean('mail_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('otp_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('verification_challenge_id')->nullable()->index();
            $table->string('provider', 16);
            $table->string('channel', 16);
            $table->string('destination_hash', 64);
            $table->string('provider_reference', 190)->nullable()->index();
            $table->string('status', 16);
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('receipt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_deliveries');
        Schema::dropIfExists('platform_settings');
    }
};
