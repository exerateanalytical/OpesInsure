<?php

declare(strict_types=1);

namespace App\Application\Distribution;

use App\Application\CarrierOperations\Agreements\CarrierBrokerAgreementService;
use App\Application\Regulatory\CimaPublicationGuard;
use App\Models\InsuranceProduct;
use Illuminate\Support\Facades\DB;

/**
 * REQ-DST-001 — can this viewer sell this product, for this action, on this channel/territory, on this date?
 *
 *   product ACTIVE (the published version) and effective; carrier ACTIVE; carrier CIMA-authorized for the
 *   product's branches (CimaPublicationGuard, grandfathering respected); seller ACTIVE with a valid licence
 *   (and an agent's branch ACTIVE); agreement permits the line/product/action (CarrierBrokerAgreementService::permits,
 *   the single agreement engine, no parallel logic here); agreement channel and territory lists allow it.
 *
 * An agent sells under its supervising brokerage's agreement (partners.supervisor_partner_id chain); an agent
 * with no supervisor must hold its own agreement. With no partner (direct B2C sale by a tenant) the tenant's
 * marketplace publication must be live on the channel. A broker/tenant can only narrow a carrier product:
 * a paused publication hides it; cover and tariff always come from the carrier product (REQ-DST-002).
 */
final class SellabilityService
{
    public const LIVE_PUBLICATION = ['APPROVED', 'PUBLISHED', 'ACTIVE'];

    public function __construct(private readonly CarrierBrokerAgreementService $agreements, private readonly CimaPublicationGuard $cima) {}

    /**
     * @param  array{partner_id?:?string, tenant_id?:?string, channel?:?string, territory?:?string, on?:?string}  $viewer
     * @return array<string,mixed>
     */
    public function check(string $productId, string $action, array $viewer): array
    {
        $on = $viewer['on'] ?? now()->toDateString();
        $product = InsuranceProduct::find($productId);
        $partner = ! empty($viewer['partner_id']) ? DB::table('partners')->where('id', $viewer['partner_id'])->first() : null;
        $channel = strtoupper((string) ($viewer['channel'] ?? DistributionChannels::defaultFor($partner?->type)));
        $out = ['sellable' => false, 'reasons' => [], 'product_id' => $productId, 'carrier_id' => $product?->carrier_id, 'action' => $action,
            'selling_partner_id' => null, 'agreement_id' => null, 'channel' => $channel, 'requires_carrier_approval' => true,
            'commission_basis_points' => null, 'regulatory' => []];

        if (! $product) {
            return ['reasons' => ['PRODUCT_NOT_FOUND']] + $out;
        }
        $reasons = [];
        if ($product->status !== 'ACTIVE') {
            $reasons[] = 'PRODUCT_NOT_ACTIVE';
        }
        $from = substr((string) $product->getRawOriginal('effective_from'), 0, 10);
        $until = $product->getRawOriginal('effective_until');
        if ($from > $on || ($until !== null && substr((string) $until, 0, 10) < $on)) {
            $reasons[] = 'PRODUCT_NOT_EFFECTIVE';
        }
        if (DB::table('carriers')->where('id', $product->carrier_id)->value('status') !== 'ACTIVE') {
            $reasons[] = 'CARRIER_INACTIVE';
        }
        if (! $this->cima->isGrandfathered($product)) {
            $out['regulatory'] = $this->cima->violations($product, false);
            if ($out['regulatory'] !== []) {
                $reasons[] = 'CARRIER_NOT_AUTHORIZED';
            }
        }

        if ($partner) {
            [$seller, $sellerReasons] = $this->seller($partner, $on);
            $reasons = [...$reasons, ...$sellerReasons];
            $out['selling_partner_id'] = $seller->id;
            $permit = $this->agreements->permits($seller->id, $product->carrier_id, $product->line_code, $product->id, $action, $on);
            $out['agreement_id'] = $permit['agreement_id'];
            if (! $permit['allowed']) {
                $reasons[] = $permit['reason'];
            } else {
                $out['requires_carrier_approval'] = $permit['requires_carrier_approval'];
                $out['commission_basis_points'] = $permit['commission_basis_points'];
                $agreement = DB::table('carrier_broker_agreements')->where('id', $permit['agreement_id'])->first(['channels', 'territories']);
                $channels = array_map('strtoupper', (array) json_decode((string) $agreement->channels, true));
                $territories = array_map('strtoupper', (array) json_decode((string) $agreement->territories, true));
                if ($channels !== [] && ! in_array($channel, $channels, true)) {
                    $reasons[] = 'CHANNEL_NOT_PERMITTED';
                }
                if (! empty($viewer['territory']) && $territories !== [] && ! in_array(strtoupper($viewer['territory']), $territories, true)) {
                    $reasons[] = 'TERRITORY_NOT_PERMITTED';
                }
            }
            if ($partner->tenant_id && $this->publicationPaused($partner->tenant_id, $product->id)) {
                $reasons[] = 'PUBLICATION_PAUSED';
            }
        } elseif (! empty($viewer['tenant_id'])) {
            if (! $this->publicationLive($viewer['tenant_id'], $product->id, $channel)) {
                $reasons[] = 'NOT_PUBLISHED_ON_CHANNEL';
            }
        } else {
            $reasons[] = 'NO_SELLER';
        }

        return ['sellable' => $reasons === [], 'reasons' => array_values(array_unique($reasons))] + $out;
    }

    /**
     * The partner whose agreement is used, plus every reason the viewer (or its supervisors) cannot sell.
     *
     * @return array{0:object,1:list<string>}
     */
    public function seller(object $partner, string $on): array
    {
        $reasons = $this->partnerReasons($partner, $on);
        if ($partner->type === 'AGENT' && ! empty($partner->branch_id)
            && DB::table('tenant_branches')->where('id', $partner->branch_id)->value('status') !== 'ACTIVE') {
            $reasons[] = 'BRANCH_INACTIVE';
        }
        $seller = $partner;
        $seen = [$partner->id => true];
        while ($seller->type === 'AGENT' && ! empty($seller->supervisor_partner_id) && ! isset($seen[$seller->supervisor_partner_id])) {
            $next = DB::table('partners')->where('id', $seller->supervisor_partner_id)->first();
            if (! $next) {
                break;
            }
            $seen[$next->id] = true;
            $reasons = [...$reasons, ...array_map(fn ($r) => 'SUPERVISOR_'.$r, $this->partnerReasons($next, $on))];
            $seller = $next;
        }

        return [$seller, $reasons];
    }

    /** @return list<string> */
    private function partnerReasons(object $partner, string $on): array
    {
        $r = [];
        if ($partner->status !== 'ACTIVE') {
            $r[] = 'PARTNER_INACTIVE';
        }
        if (! empty($partner->licence_expires_on) && substr((string) $partner->licence_expires_on, 0, 10) < $on) {
            $r[] = 'LICENCE_EXPIRED';
        }

        return $r;
    }

    private function publicationPaused(string $tenantId, string $productId): bool
    {
        $rows = DB::table('marketplace_publications')->where(['tenant_id' => $tenantId, 'product_id' => $productId])->pluck('status');

        return $rows->contains('PAUSED') && $rows->intersect(self::LIVE_PUBLICATION)->isEmpty();
    }

    private function publicationLive(string $tenantId, string $productId, string $channel): bool
    {
        return DB::table('marketplace_publications')->where(['tenant_id' => $tenantId, 'product_id' => $productId])->whereIn('status', self::LIVE_PUBLICATION)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get(['channels'])->contains(fn ($p) => in_array($channel, array_map('strtoupper', (array) json_decode((string) $p->channels, true)), true));
    }
}
