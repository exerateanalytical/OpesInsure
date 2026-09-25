<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-AUTH-002 / REQ-DUP-023 (batch 7A) — append-only log of every AuthorityService decision (ALLOWED / REFERRED /
 * DENIED): which authority_limits row (or the legacy delegated_authority_agreements fallback) decided it, the
 * intermediary authorization seen (REQ-AUTH-003) and the AUTHORITY_REFERRAL case opened on threshold-exceed.
 * ALLOWED rows that name a limit are that limit's consumption record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authority_checks', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->nullable()->constrained();
            $t->foreignUuid('carrier_id')->nullable()->constrained();
            $t->string('holder_type', 16);
            $t->string('holder_id', 64);
            $t->string('authority_type', 32);
            $t->string('action', 32);                 // ISSUE | BIND | QUOTE | ...
            $t->string('subject_type', 64)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->string('line_code', 32)->nullable();
            $t->bigInteger('amount_minor')->default(0);
            $t->string('currency', 3)->default('XAF');
            $t->string('outcome', 16);                // ALLOWED | REFERRED | DENIED
            $t->string('reason', 64);
            $t->string('source', 24);                 // AUTHORITY_LIMIT | LEGACY_AGREEMENT
            $t->foreignUuid('authority_limit_id')->nullable()->constrained('authority_limits');
            $t->uuid('delegated_authority_agreement_id')->nullable();
            $t->foreignUuid('intermediary_authorization_id')->nullable()->constrained('intermediary_authorizations');
            $t->uuid('referral_case_id')->nullable();
            $t->foreignUuid('checked_by')->nullable()->constrained('users');
            $t->timestampTz('created_at');
            $t->index(['holder_type', 'holder_id', 'authority_type', 'outcome']);
            $t->index(['subject_type', 'subject_id']);
        });
        DB::statement("ALTER TABLE authority_checks ADD CONSTRAINT authority_checks_outcome_valid CHECK (outcome IN ('ALLOWED','REFERRED','DENIED'))");
        DB::statement("ALTER TABLE authority_checks ADD CONSTRAINT authority_checks_referral_has_case CHECK (outcome <> 'REFERRED' OR referral_case_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_checks');
    }
};
