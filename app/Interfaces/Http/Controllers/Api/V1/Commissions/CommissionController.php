<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Commissions;

use Illuminate\Support\Facades\DB;

/**
 * Read-only commission reporting.
 *
 * The write methods that used to live here (createRule, approveRule, accrue,
 * vest, clawback) were removed: they wrote commission_rule_versions,
 * commission_accruals and commission_movements with raw queries, bypassing the
 * maker-checker, advisory locks, idempotency keys and event trail in
 * App\Application\FinancialDistribution\CommissionService — and used a status
 * vocabulary (ACTIVE / ACCRUED / CLAWED_BACK) incompatible with the one that
 * service requires (APPROVED / PENDING / VESTED), so rows written here could
 * not be consumed there at all. routes/wave6.php is now the only write path.
 *
 * balance() stays because Wave6 has no equivalent aggregate.
 * See docs/design/FINANCIAL_DISTRIBUTION_LEGACY_PATHS.md.
 */
final class CommissionController
{
    public function balance(string $partner){$rows=DB::table('commission_accruals')->where('tenant_id',app(\App\Domain\Tenancy\TenantContext::class)->id())->where('partner_id',$partner)->selectRaw("currency, SUM(amount_minor-clawed_back_minor) as earned_minor, SUM(vested_minor-paid_minor) as available_minor, SUM(paid_minor) as paid_minor")->groupBy('currency')->get();return response()->json(['data'=>$rows]);}
}
