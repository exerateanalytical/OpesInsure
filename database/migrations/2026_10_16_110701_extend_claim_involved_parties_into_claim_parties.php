<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-CLM-006 — claim_involved_parties becomes THE claim party table (generic ClaimParty: claimant, insured, third
 * parties, drivers, witnesses, payees, lawyers, repairers, experts…). Extended in place, not duplicated: the mobile
 * self-reported rows keep working (source MOBILE) and staff rows (source STAFF) add a golden-record link (party_id,
 * match-or-create with human-approved merges), an optional provider link (partner_id), payee bank details (encrypted
 * + masked), a consent basis and a dated, soft-removed lifecycle (effective_from / effective_to / removed_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_involved_parties', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->foreignUuid('party_id')->nullable()->after('claim_id')->constrained('parties')->nullOnDelete();
            $table->foreignUuid('partner_id')->nullable()->after('party_id')->constrained('partners')->nullOnDelete();
            $table->string('source', 16)->default('MOBILE');
            $table->string('match_status', 24)->default('UNLINKED'); // UNLINKED | LINKED | MATCHED | CREATED | REVIEW_PENDING
            $table->unsignedSmallInteger('match_candidates')->default(0);
            $table->string('consent_basis', 32)->nullable(); // EXPLICIT | CONTRACT | LEGAL_OBLIGATION | LEGITIMATE_INTEREST
            $table->timestampTz('consent_recorded_at')->nullable();
            $table->string('bank_name', 128)->nullable();
            $table->string('bank_account_holder', 255)->nullable();
            $table->text('bank_account_encrypted')->nullable();
            $table->string('bank_account_masked', 64)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->foreignUuid('removed_by')->nullable()->constrained('users');
            $table->string('removal_reason', 500)->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->index(['tenant_id', 'claim_id']);
            $table->index('party_id');
        });
        DB::statement('ALTER TABLE claim_involved_parties ALTER COLUMN role TYPE varchar(32)');
        DB::statement('UPDATE claim_involved_parties p SET tenant_id = c.tenant_id FROM claims c WHERE c.id = p.claim_id AND p.tenant_id IS NULL');
        DB::statement('UPDATE claim_involved_parties SET effective_from = created_at::date WHERE effective_from IS NULL');
    }

    public function down(): void
    {
        Schema::table('claim_involved_parties', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'claim_id']);
            $table->dropIndex(['party_id']);
            $table->dropConstrainedForeignId('party_id');
            $table->dropConstrainedForeignId('partner_id');
            $table->dropConstrainedForeignId('removed_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['tenant_id', 'source', 'match_status', 'match_candidates', 'consent_basis', 'consent_recorded_at', 'bank_name',
                'bank_account_holder', 'bank_account_encrypted', 'bank_account_masked', 'effective_from', 'effective_to', 'removed_at', 'removal_reason']);
        });
    }
};
