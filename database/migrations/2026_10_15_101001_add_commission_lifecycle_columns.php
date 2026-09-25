<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 10-1 — REQ-COM-001 commission machine (App\Application\Commissions\Machine\CommissionMachine).
 * Additive only: commission_accruals.status keeps its stored codes (PENDING, VESTED, ...); the blueprint state is derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_accruals', function (Blueprint $t): void {
            $t->timestampTz('earned_at')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->foreignUuid('approved_by')->nullable()->constrained('users');
            $t->timestampTz('payable_at')->nullable();
            $t->timestampTz('paid_at')->nullable();
            $t->foreignUuid('adjusted_by')->nullable()->constrained('users');
            $t->timestampTz('disputed_at')->nullable();
            $t->text('dispute_reason')->nullable();
            $t->foreignUuid('financial_obligation_id')->nullable()->constrained('financial_obligations');
            $t->index(['tenant_id', 'policy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('commission_accruals', function (Blueprint $t): void {
            $t->dropIndex(['tenant_id', 'policy_id', 'status']);
            $t->dropConstrainedForeignId('approved_by');
            $t->dropConstrainedForeignId('adjusted_by');
            $t->dropConstrainedForeignId('financial_obligation_id');
            $t->dropColumn(['earned_at', 'approved_at', 'payable_at', 'paid_at', 'disputed_at', 'dispute_reason']);
        });
    }
};
