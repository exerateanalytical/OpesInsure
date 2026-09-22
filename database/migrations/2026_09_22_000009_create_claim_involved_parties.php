<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-reported driver/passenger/third-party/witness records for a
 * claim — the "involved parties" capability named in the mobile Claims
 * Completion merge guide (GET/POST /mobile/claims/{id}/parties). Deliberately
 * minimal: this is not a full Party/PartyContact record (no dedup against
 * the parties table, no KYC), just what a customer can self-report about a
 * loss event, with a consent flag gating storage of a third party's own
 * contact details per the merge guide's "minimize third-party personal
 * information" mandate. See MobileClaimPartyService for the enforcement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_involved_parties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('claim_id')->constrained('claims')->cascadeOnDelete();
            $table->string('role', 24);
            $table->string('display_name', 255);
            $table->boolean('is_self')->default(false);
            $table->string('contact_phone', 32)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->boolean('consent_given')->default(false);
            $table->text('notes')->nullable();
            $table->foreignUuid('added_by')->constrained('users');
            $table->timestampsTz();
            $table->index(['claim_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_involved_parties');
    }
};
