<?php

declare(strict_types=1);

namespace App\Application\Import\Legacy\Adapters;

use App\Application\Import\Legacy\LegacyAdapter;
use App\Application\Import\Legacy\LegacyContext;
use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\QuoteOffer;
use Illuminate\Support\Facades\DB;

/**
 * REQ-IMP-002 policies. A legacy policy keeps its own number and dates; the quote → offer → proposal chain the
 * data model requires is recorded as MIGRATED (origin / status), never re-rated. The structured chronology
 * (policy_versions + policy_parties / risks / coverages) is written by PolicyChronologyWriter with kind BACKFILL
 * and source_type legacy_migration, so the policy has a §84 snapshot like any issued one.
 */
final class PolicyAdapter implements LegacyAdapter
{
    public const STATUSES = ['ACTIVE', 'EXPIRED', 'CANCELLED', 'LAPSED', 'SUSPENDED'];

    public const ORIGIN = 'LEGACY_MIGRATION';

    public function __construct(private readonly PolicyChronologyWriter $chronology) {}

    public function key(): string
    {
        return 'legacy.policies';
    }

    public function label(): string
    {
        return 'Legacy policies';
    }

    public function recordType(): string
    {
        return 'legacy.policy';
    }

    public function fields(): array
    {
        return ['legacy_id' => true, 'customer_legacy_id' => true, 'carrier_code' => true, 'product_code' => true, 'policy_number' => true,
            'starts_at' => true, 'ends_at' => true, 'premium' => true, 'currency' => true, 'status' => true, 'coverages' => false, 'risk_description' => false];
    }

    public function validate(array $row, LegacyContext $ctx): array
    {
        $e = [];
        if (! $ctx->resolve('legacy.customer', $row['customer_legacy_id'])) {
            $e[] = ['rule' => 'CUSTOMER_MIGRATED', 'field' => 'customer_legacy_id', 'message' => "Customer {$row['customer_legacy_id']} has not been migrated yet."];
        }
        $carrier = $this->carrier($row['carrier_code']);
        if (! $carrier) {
            $e[] = ['rule' => 'CARRIER_KNOWN', 'field' => 'carrier_code', 'message' => "Unknown carrier {$row['carrier_code']}."];
        } elseif (! $this->product($carrier->id, $row['product_code'])) {
            $e[] = ['rule' => 'PRODUCT_KNOWN', 'field' => 'product_code', 'message' => "Unknown product {$row['product_code']} for this carrier."];
        }
        $start = LegacyContext::date($row['starts_at']);
        $end = LegacyContext::date($row['ends_at']);
        if (! $start || ! $end || $end->lte($start)) {
            $e[] = ['rule' => 'PERIOD', 'field' => 'ends_at', 'message' => 'Start and end must be dates with end after start.'];
        }
        if (! preg_match('/^[A-Z]{3}$/', (string) $row['currency'])) {
            $e[] = ['rule' => 'CURRENCY', 'field' => 'currency', 'message' => 'Currency must be an ISO 4217 code.'];
        } elseif (($p = LegacyContext::minor($row['premium'], $row['currency'])) === null || $p < 0) {
            $e[] = ['rule' => 'AMOUNT', 'field' => 'premium', 'message' => 'Premium must be a non-negative amount in the currency precision.'];
        }
        if (! in_array(strtoupper((string) $row['status']), self::STATUSES, true)) {
            $e[] = ['rule' => 'POLICY_STATUS', 'field' => 'status', 'message' => 'Status must be one of '.implode(', ', self::STATUSES).'.'];
        }
        if (Policy::where('policy_number', $row['policy_number'])->exists()) {
            $e[] = ['rule' => 'POLICY_NUMBER_UNIQUE', 'field' => 'policy_number', 'message' => "Policy number {$row['policy_number']} already exists."];
        }
        if ($row['coverages'] !== null && $this->coverages($row['coverages'], (string) $row['currency']) === null) {
            $e[] = ['rule' => 'COVERAGES', 'field' => 'coverages', 'message' => 'Coverages must read CODE:premium|CODE:premium.'];
        }

        return $e;
    }

    public function sourceAmount(array $row): int
    {
        return (int) LegacyContext::minor($row['premium'], (string) $row['currency']);
    }

    public function migrate(array $row, LegacyContext $ctx): array
    {
        $partyId = $ctx->resolve('legacy.customer', $row['customer_legacy_id']);
        $carrier = $this->carrier($row['carrier_code']);
        $product = $this->product($carrier->id, $row['product_code']);
        $start = LegacyContext::date($row['starts_at']);
        $end = LegacyContext::date($row['ends_at']);
        $currency = (string) $row['currency'];
        $premium = $this->sourceAmount($row);
        $coverages = $this->coverages($row['coverages'], $currency) ?? [];
        $legacy = ['legacy_id' => $row['legacy_id'], 'source' => $ctx->source->name, 'batch_id' => $ctx->batchId];

        // origin / data_origin are not mass-assignable on these models: forceFill keeps the provenance explicit.
        $quote = tap((new Quote)->forceFill(['tenant_id' => $ctx->tenantId, 'party_id' => $partyId, 'line_code' => $product->line_code, 'status' => 'MIGRATED', 'currency' => $currency,
            'risk_facts' => array_filter(['description' => $row['risk_description'], 'legacy' => $legacy]), 'data_origin' => self::ORIGIN]))->save();
        $offer = tap((new QuoteOffer)->forceFill(['quote_id' => $quote->id, 'carrier_id' => $carrier->id, 'product_id' => $product->id, 'premium_minor' => $premium, 'total_minor' => $premium,
            'currency' => $currency, 'status' => 'MIGRATED', 'origin' => 'MIGRATED', 'calculation_breakdown' => ['legacy' => $legacy], 'coverage_snapshot' => $coverages,
            'valid_until' => $start, 'data_origin' => self::ORIGIN]))->save();
        $proposal = tap((new Proposal)->forceFill(['tenant_id' => $ctx->tenantId, 'quote_offer_id' => $offer->id, 'party_id' => $partyId, 'status' => 'MIGRATED', 'data_origin' => self::ORIGIN]))->save();
        $policy = tap((new Policy)->forceFill([
            'tenant_id' => $ctx->tenantId, 'proposal_id' => $proposal->id, 'carrier_id' => $carrier->id, 'party_id' => $partyId,
            'policy_number' => $row['policy_number'], 'status' => strtoupper((string) $row['status']), 'coverage_starts_at' => $start, 'coverage_ends_at' => $end,
            'terms_snapshot' => ['quote_id' => $quote->id, 'currency' => $currency, 'premium_minor' => $premium, 'product_id' => $product->id,
                'coverage_snapshot' => ['coverages' => $coverages], 'legacy' => $legacy],
            'version' => 1, 'currency' => $currency, 'premium_minor' => $premium, 'issued_at' => $start, 'issuance_reference' => 'LEGACY:'.$row['legacy_id'],
            'data_origin' => self::ORIGIN,
        ]))->save();
        $versionId = $this->chronology->record($policy, 'BACKFILL', $start, ['source_type' => 'legacy_migration', 'source_id' => $ctx->batchId, 'actor_id' => $ctx->actor->id]);

        return ['id' => $policy->id, 'notes' => ['policy_version_id' => $versionId, 'coverages' => count($coverages)]];
    }

    public function measure(string $id): ?int
    {
        $p = DB::table('policies')->where('id', $id)->value('premium_minor');

        return $p === null ? null : (int) $p;
    }

    private function carrier(?string $code): ?Carrier
    {
        return $code ? Carrier::where('cima_code', $code)->orWhere('insurer_code', $code)->first() : null;
    }

    private function product(string $carrierId, ?string $code): ?InsuranceProduct
    {
        return $code ? InsuranceProduct::where(['carrier_id' => $carrierId, 'code' => $code])->orderByDesc('version')->first() : null;
    }

    /** "TPL:60000|FIRE:5000" → coverage snapshot rows; null when malformed. */
    private function coverages(?string $raw, string $currency): ?array
    {
        if ($raw === null) {
            return [];
        }
        $out = [];
        foreach (array_filter(array_map('trim', explode('|', $raw))) as $part) {
            [$code, $amount] = array_pad(explode(':', $part, 2), 2, null);
            $minor = $amount === null ? null : LegacyContext::minor($amount, $currency);
            if (! preg_match('/^[A-Z0-9_]{1,64}$/', (string) $code) || $minor === null || $minor < 0) {
                return null;
            }
            $out[] = ['code' => $code, 'name' => $code, 'mandatory' => false, 'optional' => false, 'premium_minor' => $minor];
        }

        return $out;
    }
}
