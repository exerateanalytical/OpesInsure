<?php

declare(strict_types=1);

namespace App\Application\Distribution;

use App\Application\Distribution\Execution\ExecutionPlanner;
use App\Models\InsuranceProduct;
use Illuminate\Support\Facades\DB;

/**
 * REQ-DST-001 / REQ-DST-002 — the sellable catalogue for one viewer (broker, agent, or a tenant's direct channel).
 *
 * Derived, never authored: each entry is a carrier product that passes SellabilityService for `quote`, with the
 * agreement's bind / collect permissions and commission, and the execution adapters the carrier's capability
 * profile selects. Cover, exclusions and tariff are the carrier's (broker_overridable = false); a broker can only
 * narrow the list (pause a publication), never add products or change cover/tariff.
 */
final class SellableCatalogue
{
    public function __construct(private readonly SellabilityService $sellability, private readonly ExecutionPlanner $planner) {}

    /**
     * @param  array{partner_id?:?string, tenant_id?:?string, channel?:?string, territory?:?string, on?:?string, line_code?:?string, include_blocked?:bool}  $viewer
     * @return list<array<string,mixed>>
     */
    public function for(array $viewer): array
    {
        $on = $viewer['on'] ?? now()->toDateString();
        $q = InsuranceProduct::query()->where('status', 'ACTIVE')->orderBy('line_code')->orderBy('name');
        if (! empty($viewer['line_code'])) {
            $q->where('line_code', $viewer['line_code']);
        }
        if (! empty($viewer['partner_id'])) {
            $partner = DB::table('partners')->where('id', $viewer['partner_id'])->first();
            if (! $partner) {
                return [];
            }
            [$seller] = $this->sellability->seller($partner, $on);
            // Only carriers the seller has an agreement with can ever be sellable: narrow before the per-product check.
            $q->whereIn('carrier_id', DB::table('carrier_broker_agreements')->where('partner_id', $seller->id)->select('carrier_id'));
        }

        $out = [];
        foreach ($q->get() as $product) {
            $check = $this->sellability->check($product->id, 'quote', $viewer);
            if (! $check['sellable'] && empty($viewer['include_blocked'])) {
                continue;
            }
            $entry = [
                'product_id' => $product->id, 'code' => $product->code, 'name' => $product->name, 'line_code' => $product->line_code,
                'version' => $product->version, 'carrier_id' => $product->carrier_id,
                'carrier_name' => DB::table('carriers')->join('parties', 'parties.id', '=', 'carriers.party_id')->where('carriers.id', $product->carrier_id)->value('parties.display_name'),
                'coverages' => $product->coverages, 'tariff_version_id' => DB::table('tariff_versions')->where(['insurance_product_id' => $product->id, 'status' => 'APPROVED'])->orderByDesc('version')->value('id'),
                'source' => 'CARRIER_PRODUCT', 'broker_overridable' => false,
                'sellable' => $check['sellable'], 'reasons' => $check['reasons'], 'channel' => $check['channel'],
                'agreement_id' => $check['agreement_id'], 'selling_partner_id' => $check['selling_partner_id'],
                'requires_carrier_approval' => $check['requires_carrier_approval'], 'commission_basis_points' => $check['commission_basis_points'],
                'permissions' => ['quote' => $check['sellable']],
            ];
            foreach (['bind', 'collect_premium'] as $action) {
                $entry['permissions'][$action] = $check['sellable'] && $this->sellability->check($product->id, $action, $viewer)['sellable'];
            }
            $entry['execution'] = $this->planner->plan($product->carrier_id, $product->id);
            $out[] = $entry;
        }

        return $out;
    }
}
