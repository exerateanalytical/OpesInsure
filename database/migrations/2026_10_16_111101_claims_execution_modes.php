<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-CLM-011 — claims execution modes: inbound channel on carrier exchange messages, per-carrier signing
 * keys for API_SYNCHRONIZED inbound messages, and maker-checker manual entries of carrier responses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_exchange_messages', function (Blueprint $t): void {
            $t->string('channel', 24)->nullable();
            $t->string('signature_key_id', 64)->nullable();
        });

        Schema::create('claim_carrier_signing_keys', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('carrier_id')->constrained();
            $t->string('key_id', 64);
            $t->text('secret_encrypted');
            $t->string('status', 16)->default('ACTIVE');
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampTz('revoked_at')->nullable();
            $t->timestampsTz();
            $t->unique(['carrier_id', 'key_id']);
        });

        Schema::create('claim_carrier_manual_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained();
            $t->foreignUuid('claim_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('carrier_id')->constrained();
            $t->string('claims_mode', 24);
            $t->string('message_type', 48);
            $t->jsonb('payload');
            $t->string('payload_hash', 64);
            $t->string('status', 24);
            $t->foreignUuid('entered_by')->constrained('users');
            $t->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $t->timestampTz('reviewed_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->foreignUuid('carrier_exchange_message_id')->nullable()->constrained('carrier_exchange_messages');
            $t->timestampsTz();
            $t->index(['claim_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_carrier_manual_entries');
        Schema::dropIfExists('claim_carrier_signing_keys');
        Schema::table('carrier_exchange_messages', function (Blueprint $t): void {
            $t->dropColumn(['channel', 'signature_key_id']);
        });
    }
};
