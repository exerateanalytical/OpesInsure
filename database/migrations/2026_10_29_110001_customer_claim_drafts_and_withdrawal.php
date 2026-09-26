<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer claims self-service:
 *  - claim_drafts: a customer's unfinished FNOL, kept outside `claims` so a draft never consumes an official
 *    claim number, never appears in staff queues and never starts SLAs/notifications. Submitting a draft runs
 *    the one FNOL path (FnolService) and links the created claim (claim_id) — the draft then stops being listed.
 *  - claims.withdrawn_at / withdrawal_reason: set when the claimant withdraws an early-stage claim (ClaimMachine
 *    event `withdraw` → CLOSED). Additive, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('claim_drafts')) {
            Schema::create('claim_drafts', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('tenant_id');
                $t->uuid('party_id');
                $t->uuid('user_id')->nullable();
                $t->uuid('policy_id')->nullable();
                $t->jsonb('payload')->default('{}');
                $t->uuid('claim_id')->nullable();
                $t->timestampTz('submitted_at')->nullable();
                $t->timestampsTz();
                $t->index(['tenant_id', 'party_id', 'claim_id']);
            });
        }
        Schema::table('claims', function (Blueprint $t) {
            if (! Schema::hasColumn('claims', 'withdrawn_at')) {
                $t->timestampTz('withdrawn_at')->nullable();
            }
            if (! Schema::hasColumn('claims', 'withdrawal_reason')) {
                $t->text('withdrawal_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_drafts');
        Schema::table('claims', function (Blueprint $t) {
            foreach (['withdrawn_at', 'withdrawal_reason'] as $c) {
                if (Schema::hasColumn('claims', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
