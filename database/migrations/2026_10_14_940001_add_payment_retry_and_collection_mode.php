<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 9-4 — REQ-PAY-008 (retries as attempts under one payment intent / obligation) and REQ-PAY-014
 * (collection mode recorded per payment). financial_obligation_id has no FK on purpose: the
 * financial_obligations table is owned by Batch 9-1 and lands in parallel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $t) {
            // financial_obligation_id is added by 2026_10_14_910001 (Batch 9-1)
            $t->string('collection_mode', 32)->nullable();
            $t->jsonb('collection_semantics')->nullable();
            $t->unsignedSmallInteger('max_attempts')->default(3);
        });
        Schema::table('payment_attempts', function (Blueprint $t) {
            $t->string('collection_mode', 32)->nullable();
            $t->uuid('retry_of_attempt_id')->nullable();
            $t->string('retry_idempotency_key', 128)->nullable()->unique();
            $t->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_collection_mode CHECK (collection_mode IS NULL OR collection_mode IN ('BROKER_COLLECTION','INSURER_COLLECTION','MOBILE_MONEY','BANK','EXTERNAL_PROVIDER'))");
        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_max_attempts CHECK (max_attempts BETWEEN 1 AND 10)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_intents DROP CONSTRAINT IF EXISTS payment_intents_collection_mode');
        DB::statement('ALTER TABLE payment_intents DROP CONSTRAINT IF EXISTS payment_intents_max_attempts');
        Schema::table('payment_attempts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('requested_by');
            $t->dropColumn(['collection_mode', 'retry_of_attempt_id', 'retry_idempotency_key']);
        });
        Schema::table('payment_intents', fn (Blueprint $t) => $t->dropColumn(['collection_mode', 'collection_semantics', 'max_attempts']));
    }
};
