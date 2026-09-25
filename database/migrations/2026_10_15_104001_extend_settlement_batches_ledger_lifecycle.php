<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 10-4 — REQ-STL-001 broker–insurer settlement calculated from the ledger/obligations.
 *
 * settlement_batches.calculation_basis      POLICY (Wave6 CarrierSettlementService, DRAFT→APPROVED→SUBMITTED→PAID)
 *                                           or OBLIGATIONS (App\Application\Settlements\SettlementService,
 *                                           DRAFT→CALCULATED→REVIEW→APPROVED→PROCESSING→SETTLED→RECONCILED).
 * settlement_items.financial_obligation_id  the carrier PAYABLE obligation the line settles.
 * settlement_items.collection_mode          frozen collection mode (Batch 9-4 collection_semantics) that made the line remittable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_batches', function (Blueprint $t): void {
            $t->string('calculation_basis', 16)->default('POLICY');
            $t->uuid('partner_id')->nullable();
            $t->jsonb('calculation_summary')->nullable();
            $t->timestampTz('calculated_at')->nullable();
            $t->uuid('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->timestampTz('processing_at')->nullable();
            $t->timestampTz('settled_at')->nullable();
            $t->timestampTz('reconciled_at')->nullable();
            $t->string('reconciliation_reference', 120)->nullable();
            $t->string('correlation_id', 64)->nullable();
            $t->index(['tenant_id', 'calculation_basis', 'status']);
        });
        Schema::table('settlement_items', function (Blueprint $t): void {
            $t->uuid('financial_obligation_id')->nullable()->index();
            $t->string('collection_mode', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settlement_items', fn (Blueprint $t) => $t->dropColumn(['financial_obligation_id', 'collection_mode']));
        Schema::table('settlement_batches', function (Blueprint $t): void {
            $t->dropIndex(['tenant_id', 'calculation_basis', 'status']);
            $t->dropColumn(['calculation_basis', 'partner_id', 'calculation_summary', 'calculated_at', 'reviewed_by', 'reviewed_at', 'processing_at', 'settled_at', 'reconciled_at', 'reconciliation_reference', 'correlation_id']);
        });
    }
};
