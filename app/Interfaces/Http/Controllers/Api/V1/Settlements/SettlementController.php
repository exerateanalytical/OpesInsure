<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Settlements;

use Illuminate\Support\Facades\DB;

/**
 * Read-only settlement reporting.
 *
 * prepare() and approve() were removed: they wrote settlement_batches and
 * settlement_items with raw queries on a narrower state machine
 * (PENDING_APPROVAL / APPROVED) than
 * App\Application\FinancialDistribution\CarrierSettlementService models
 * (DRAFT / APPROVED / SUBMITTED / PAID / FAILED / REVERSED), and prepare()
 * selected policies with no tenant filter at all. routes/wave6.php is now the
 * only write path.
 *
 * show() stays because Wave6 has no equivalent read, and it surfaces
 * settlement_approvals, which CarrierSettlementService is being taught to
 * write. See docs/design/FINANCIAL_DISTRIBUTION_LEGACY_PATHS.md.
 */
final class SettlementController
{
    public function show(string$batch){$row=DB::table('settlement_batches')->where('tenant_id',app(\App\Domain\Tenancy\TenantContext::class)->id())->where('id',$batch)->first();abort_unless($row,404);return response()->json(['data'=>['batch'=>$row,'items'=>DB::table('settlement_items')->where('settlement_batch_id',$batch)->get(),'approvals'=>DB::table('settlement_approvals')->where('settlement_batch_id',$batch)->get()]]);}
}
