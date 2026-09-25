<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-DUP-008 / REQ-STL-002 — a bordereau item now points at the business record it
 * reports (policy, endorsement, cancellation, claim, commission accrual), so one
 * policy can appear several times (e.g. two endorsements) in the same bordereau.
 * amount_minor is the item's own amount (premium, premium delta, -refund, claim
 * amount, commission); bordereaux.total_amount_minor is their sum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bordereau_items', function (Blueprint $t): void {
            $t->string('source_type', 32)->nullable();
            $t->uuid('source_id')->nullable();
            $t->bigInteger('amount_minor')->default(0);
        });
        DB::statement("UPDATE bordereau_items SET source_type = 'POLICY', source_id = policy_id, amount_minor = premium_minor");
        Schema::table('bordereau_items', function (Blueprint $t): void {
            $t->string('source_type', 32)->nullable(false)->change();
            $t->uuid('source_id')->nullable(false)->change();
            $t->dropUnique(['bordereau_id', 'policy_id', 'transaction_type']);
            $t->unique(['bordereau_id', 'source_type', 'source_id']);
        });
        Schema::table('bordereaux', function (Blueprint $t): void {
            $t->bigInteger('total_amount_minor')->default(0);
        });
        DB::statement('UPDATE bordereaux SET total_amount_minor = gross_premium_minor');
    }

    public function down(): void
    {
        Schema::table('bordereaux', function (Blueprint $t): void {
            $t->dropColumn('total_amount_minor');
        });
        Schema::table('bordereau_items', function (Blueprint $t): void {
            $t->dropUnique(['bordereau_id', 'source_type', 'source_id']);
            $t->dropColumn(['source_type', 'source_id', 'amount_minor']);
            $t->unique(['bordereau_id', 'policy_id', 'transaction_type']);
        });
    }
};
