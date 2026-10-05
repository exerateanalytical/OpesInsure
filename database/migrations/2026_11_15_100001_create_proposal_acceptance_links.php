<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web contract-acceptance links (owner decision 2026-09-30, ProposalAcceptanceLinks): an agent/broker sale for a customer
 * without the app sends the customer a signed, expiring, single-use link by SMS. Only the SHA-256 of the link token is
 * stored; the customer proves the proposal's phone with an OTP before reviewing and accepting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_acceptance_links', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained('tenants');
            $t->foreignUuid('proposal_id')->constrained('proposals');
            $t->foreignUuid('party_id')->constrained('parties');
            $t->string('phone_e164', 20);
            $t->string('token_hash', 64)->unique();
            $t->string('sms_status', 24)->default('PENDING'); // SENT | FAILED | NOT_CONFIGURED | SKIPPED_DEMO
            $t->timestampTz('expires_at');
            $t->timestampTz('verified_at')->nullable();
            $t->timestampTz('used_at')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users');
            $t->timestampsTz();
            $t->index(['proposal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_acceptance_links');
    }
};
