<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use App\Application\Audit\AuditWriter;
use App\Models\InsuranceProduct;
use App\Models\Regulatory\LegacyProductAuthorization;

/**
 * Owner decision item 8: products that were already live (ACTIVE or paused RETIRED, grandfathered) when the CIMA
 * gate went on, and whose insurer has no verified authorization for a branch they sell, are recorded as
 * LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION with the exact branches they were selling. That status never
 * permits a new branch, an expansion into another branch, a new product family, or a regulatory claim
 * (enforced by CimaPublicationGuard / CimaProductMappingService). Nothing is fabricated: the record only says
 * "pending verification"; it resolves to VERIFIED when a real authorization covering those branches is approved.
 */
final class CimaLegacyAuthorizationService
{
    public function __construct(private readonly CimaPublicationGuard $guard, private readonly CimaAuthorizationService $authorizations, private readonly AuditWriter $audit) {}

    /** Registers every grandfathered live product with unauthorized branches. Idempotent. Returns rows created. */
    public function register(): int
    {
        $created = 0;
        InsuranceProduct::whereIn('status', ['ACTIVE', 'RETIRED'])->with('carrierProduct')->orderBy('code')->each(function (InsuranceProduct $p) use (&$created) {
            if ($p->carrier_id === null || ! $this->guard->isGrandfathered($p) || LegacyProductAuthorization::where('insurance_product_id', $p->id)->exists()) {
                return;
            }
            $branches = $this->guard->activeMappings($p)->whereIn('relationship_type', ['PRIMARY', 'COMPLEMENTARY'])->pluck('branch_code')->unique()
                ->reject(fn ($code) => $this->authorizations->isAuthorized($p->carrier_id, $code))->sort()->values()->all();
            if ($branches === []) {
                return;
            }
            $row = LegacyProductAuthorization::create([
                'insurance_product_id' => $p->id, 'carrier_id' => $p->carrier_id, 'product_code' => $p->code,
                'product_family_id' => $p->carrierProduct?->product_family_id, 'branch_codes' => $branches,
                'status' => LegacyProductAuthorization::STATUS, 'recorded_at' => now(),
                'notes' => 'Live before the CIMA authorization gate; insurer authorization not yet verified (owner decision item 8).',
            ]);
            $this->audit->record('regulatory.authorization.legacy_recorded', 'insurance_product', $p->id, ['legacy_id' => $row->id, 'branches' => $branches]);
            $created++;
        });

        return $created;
    }

    /** Closes the carrier's legacy records whose branches are now all covered by an ACTIVE authorization. */
    public function resolveFor(string $carrierId): int
    {
        $resolved = 0;
        LegacyProductAuthorization::where('carrier_id', $carrierId)->where('status', LegacyProductAuthorization::STATUS)->get()
            ->each(function (LegacyProductAuthorization $l) use ($carrierId, &$resolved) {
                foreach ((array) $l->branch_codes as $code) {
                    if (! $this->authorizations->isAuthorized($carrierId, $code)) {
                        return;
                    }
                }
                $l->update(['status' => 'VERIFIED', 'resolved_at' => now()]);
                $this->audit->record('regulatory.authorization.legacy_resolved', 'insurance_product', $l->insurance_product_id, ['legacy_id' => $l->id]);
                $resolved++;
            });

        return $resolved;
    }
}
