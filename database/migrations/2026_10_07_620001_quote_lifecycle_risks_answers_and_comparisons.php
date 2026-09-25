<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 6B — REQ-QUO-001…005, REQ-DST-003 (additive only).
 *
 *  quotes.lifecycle_state   canonical blueprint state (QuoteMachine); quotes.status stays as the
 *                           app-facing projection (SUBMITTED/REFERRED/OFFERED/ACCEPTED/...) — mobile 1.3.0.
 *  quotes.quote_number      server-allocated (DocumentNumberAllocator, INSURANCE_QUOTE family).
 *  quote_risks              the risks quoted, referencing insured objects (risk_assets, REQ-RSK-001).
 *  quote_answers            answers captured against the resolved QUOTE question set (version + hash).
 *  quote_shares             send/share + viewed tracking (WF-012).
 *  insurance_products.quote_validity_days   per product version validity (REQ-QUO-002); NULL = config default.
 *  quote_offers.sellability / original_* / premium_override_id   sellability snapshot, controlled override.
 *  saved_comparisons.dimensions             normalized comparison dimensions (the canonical comparison store).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $t): void {
            $t->string('lifecycle_state', 24)->nullable();
            $t->string('quote_number', 64)->nullable();
            $t->uuid('partner_id')->nullable();
            $t->uuid('question_set_id')->nullable();
            $t->timestampTz('generated_at')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->timestampTz('viewed_at')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('declined_at')->nullable();
            $t->string('decline_reason_code', 64)->nullable();
            $t->timestampTz('expired_at')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->unique(['tenant_id', 'quote_number']);
            $t->index(['lifecycle_state', 'expires_at']);
        });

        Schema::table('quote_offers', function (Blueprint $t): void {
            $t->jsonb('sellability')->nullable();
            $t->bigInteger('original_premium_minor')->nullable();
            $t->bigInteger('original_total_minor')->nullable();
            $t->uuid('premium_override_id')->nullable();
        });

        Schema::table('insurance_products', function (Blueprint $t): void {
            $t->unsignedSmallInteger('quote_validity_days')->nullable();
        });

        Schema::create('quote_risks', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('quote_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('risk_asset_id')->nullable()->constrained('risk_assets');
            $t->unsignedSmallInteger('sequence')->default(1);
            $t->string('line_code', 32);
            $t->string('risk_type', 32)->nullable();
            $t->jsonb('facts');
            $t->char('facts_hash', 64);
            $t->timestampsTz();
            $t->unique(['quote_id', 'sequence']);
        });

        Schema::create('quote_answers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('quote_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('question_set_id')->nullable()->constrained('question_sets');
            $t->unsignedInteger('question_set_version')->nullable();
            $t->char('schema_hash', 64)->nullable();
            $t->jsonb('answers');
            $t->char('answers_hash', 64);
            $t->jsonb('unanswered_required')->default('[]');
            $t->foreignUuid('answered_by')->nullable()->constrained('users');
            $t->timestampTz('answered_at');
            $t->timestampsTz();
        });

        Schema::create('quote_shares', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('quote_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 16); // EMAIL | SMS | WHATSAPP | LINK | IN_APP
            $t->string('recipient', 191)->nullable();
            $t->char('token_hash', 64)->unique();
            $t->foreignUuid('shared_by')->nullable()->constrained('users');
            $t->timestampTz('shared_at');
            $t->timestampTz('first_viewed_at')->nullable();
            $t->unsignedInteger('view_count')->default(0);
            $t->timestampTz('expires_at')->nullable();
            $t->timestampsTz();
        });

        Schema::table('saved_comparisons', function (Blueprint $t): void {
            $t->jsonb('dimensions')->default('{}');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_lifecycle_state_allowed CHECK (lifecycle_state IS NULL OR lifecycle_state IN ('DRAFT','RATING','CALCULATED','REFERRED','GENERATED','SENT','VIEWED','ACCEPTED','DECLINED','EXPIRED','CANCELLED'))");
            DB::statement("ALTER TABLE quote_shares ADD CONSTRAINT quote_shares_channel_allowed CHECK (channel IN ('EMAIL','SMS','WHATSAPP','LINK','IN_APP'))");
        }
    }

    public function down(): void
    {
        Schema::table('saved_comparisons', fn (Blueprint $t) => $t->dropColumn('dimensions'));
        Schema::dropIfExists('quote_shares');
        Schema::dropIfExists('quote_answers');
        Schema::dropIfExists('quote_risks');
        Schema::table('insurance_products', fn (Blueprint $t) => $t->dropColumn('quote_validity_days'));
        Schema::table('quote_offers', fn (Blueprint $t) => $t->dropColumn(['sellability', 'original_premium_minor', 'original_total_minor', 'premium_override_id']));
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT IF EXISTS quotes_lifecycle_state_allowed');
        Schema::table('quotes', function (Blueprint $t): void {
            $t->dropUnique(['tenant_id', 'quote_number']);
            $t->dropIndex(['lifecycle_state', 'expires_at']);
            $t->dropColumn(['lifecycle_state', 'quote_number', 'partner_id', 'question_set_id', 'generated_at', 'sent_at', 'viewed_at', 'accepted_at', 'declined_at', 'decline_reason_code', 'expired_at', 'cancelled_at']);
        });
    }
};
