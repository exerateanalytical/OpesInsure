<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 16 lifecycle:
 *  - documents.policy_id: issuance-generated schedule/certificate PDFs are
 *    linked to their policy so the wallet can list them.
 *  - user_push_tokens.provider: "expo" (Expo push token) or "fcm".
 *  - policy_expiry_reminders: one row per (policy, days-before) reminder so
 *    policies:notify-expiry is idempotent across daily runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->foreignUuid('policy_id')->nullable()->constrained('policies')->nullOnDelete();
            $t->index(['policy_id', 'category']);
        });

        Schema::table('user_push_tokens', function (Blueprint $t) {
            $t->string('provider', 16)->default('expo');
            $t->timestampTz('last_failure_at')->nullable();
            $t->string('last_failure_reason', 255)->nullable();
        });

        // Customer profile details that have no canonical column yet
        // (occupation, beneficiaries[]); address lives in party_addresses and
        // date of birth in parties.legal_identity.
        Schema::table('parties', function (Blueprint $t) {
            $t->jsonb('profile')->nullable();
        });

        // Support cases can reference the customer's own claim/payment/policy.
        Schema::table('support_tickets', function (Blueprint $t) {
            $t->foreignUuid('claim_id')->nullable()->constrained('claims')->nullOnDelete();
            $t->foreignUuid('payment_intent_id')->nullable()->constrained('payment_intents')->nullOnDelete();
            $t->foreignUuid('policy_id')->nullable()->constrained('policies')->nullOnDelete();
            $t->timestampTz('escalated_at')->nullable();
        });

        // The QR on the PDF carries ref + token. The hash stays the verifier;
        // the encrypted copy lets the owner's wallet and a document re-render
        // rebuild the same QR URL.
        Schema::table('policy_certificates', function (Blueprint $t) {
            $t->text('verification_token_encrypted')->nullable();
        });

        // Every public verification lookup (QR page and API), including
        // not-found ones, for anti-enumeration monitoring. Stores hashes only.
        Schema::create('public_verification_lookups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('channel', 16); // QR_PAGE | API
            $t->string('reference_hash', 64);
            $t->foreignUuid('policy_certificate_id')->nullable()->constrained('policy_certificates')->nullOnDelete();
            $t->string('result', 24);
            $t->boolean('token_presented')->default(false);
            $t->string('request_fingerprint_hash', 64);
            $t->timestampTz('occurred_at');
            $t->index(['request_fingerprint_hash', 'occurred_at']);
        });

        Schema::create('policy_expiry_reminders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('policy_id')->constrained('policies')->cascadeOnDelete();
            $t->unsignedSmallInteger('days_before');
            $t->timestampTz('sent_at');
            $t->unique(['policy_id', 'days_before']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_expiry_reminders');
        Schema::dropIfExists('public_verification_lookups');
        Schema::table('policy_certificates', fn (Blueprint $t) => $t->dropColumn('verification_token_encrypted'));
        Schema::table('support_tickets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('claim_id');
            $t->dropConstrainedForeignId('payment_intent_id');
            $t->dropConstrainedForeignId('policy_id');
            $t->dropColumn('escalated_at');
        });
        Schema::table('parties', fn (Blueprint $t) => $t->dropColumn('profile'));
        Schema::table('user_push_tokens', fn (Blueprint $t) => $t->dropColumn(['provider', 'last_failure_at', 'last_failure_reason']));
        Schema::table('documents', function (Blueprint $t) {
            $t->dropIndex(['policy_id', 'category']);
            $t->dropConstrainedForeignId('policy_id');
        });
    }
};
