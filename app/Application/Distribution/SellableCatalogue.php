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
        $products = $q->get();
        // R4: carriers (name, short name) and approved tariffs for the whole list in two queries, not three per product.
        $carriers = \App\Models\Carrier::with('party')->whereIn('id', $products->pluck('carrier_id')->filter()->unique()->values())->get()->keyBy('id');
        $tariffs = [];
        foreach (DB::table('tariff_versions')->whereIn('insurance_product_id', $products->pluck('id'))->where('status', 'APPROVED')->orderByDesc('version')->get(['id', 'insurance_product_id']) as $tv) {
            $tariffs[(string) $tv->insurance_product_id] ??= $tv->id;
        }
        // ... and SellabilityService reads the same rows from the request memo instead of re-querying them per check.
        \App\Application\Identity\Rbac\RequestMemo::prime($products->mapWithKeys(fn ($p) => ['sell-product:'.$p->id => $p])->all()
            + $carriers->mapWithKeys(fn ($c) => ['carrier-status:'.$c->id => $c->getRawOriginal('status')])->all());
        foreach ($products as $product) {
            $check = $this->sellability->check($product->id, 'quote', $viewer);
            if (! $check['sellable'] && empty($viewer['include_blocked'])) {
                continue;
            }
            $entry = [
                'product_id' => $product->id, 'code' => $product->code, 'name' => $product->name, 'line_code' => $product->line_code,
                'version' => $product->version, 'carrier_id' => $product->carrier_id,
                'carrier_name' => $carriers->get($product->carrier_id)?->party?->display_name,
                'carrier_short_name' => \App\Application\Directory\InsurerShortNames::shortOf($carriers->get($product->carrier_id)),
                'coverages' => $product->coverages, 'tariff_version_id' => $tariffs[(string) $product->id] ?? null,
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
