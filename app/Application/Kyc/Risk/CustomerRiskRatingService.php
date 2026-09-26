<?php

declare(strict_types=1);

namespace App\Application\Kyc\Risk;

use App\Application\Audit\AuditWriter;
use App\Application\Customers\Relationships\PartyRelationshipService;
use App\Application\Kyc\Models\KycRiskAssessment;
use App\Application\Kyc\Models\ScreeningCheck;
use App\Application\Kyc\Screening\ScreeningMode;
use App\Models\KycSubmission;
use App\Models\Party;
use App\Models\Tenant;

/**
 * Owner decision 27 (2026-09-25) — risk-based, audited KYC. Rates the customer from customer, country, product and
 * channel risk; PEP / sanctions; beneficial ownership; decides EDD and whether source of funds / wealth must be
 * declared; schedules periodic refresh and rescreening. Every weight, score, band and period comes from
 * config kyc.risk (tenant override: tenants.settings.kyc.risk) and defaults to NULL (UNVERIFIED): nothing is
 * invented. A factor without configuration is reported in configuration_gaps and the rating stays UNRATED unless
 * a hard trigger (PEP / sanctions) makes it HIGH. Screening is MANUAL_AUDITED; automated screening is never claimed.
 */
final class CustomerRiskRatingService
{
    public const RATINGS = ['LOW', 'MEDIUM', 'HIGH', 'UNRATED'];

    public const FACTORS = ['customer', 'country', 'product', 'channel', 'occupation'];

    /**
     * Workflow Data Master v1 kyc_aml.risk_dimensions => factor. OCCUPATION_RISK is supported but optional: until its
     * weight is configured (kyc.risk.factors.occupation) and an occupation is given it neither scores nor blocks the
     * rating (the occupation catalogue is PENDING_SOURCE).
     */
    public const DIMENSIONS = ['CUSTOMER_RISK' => 'customer', 'COUNTRY_RISK' => 'country', 'PRODUCT_RISK' => 'product', 'CHANNEL_RISK' => 'channel', 'OCCUPATION_RISK' => 'occupation'];

    private const OPTIONAL_FACTORS = ['occupation'];

    public function __construct(private readonly AuditWriter $audit) {}

    /** Effective configuration for a tenant (config kyc.risk overlaid with tenants.settings.kyc.risk). @return array<string, mixed> */
    public function configuration(?string $tenantId): array
    {
        $cfg = (array) config('kyc.risk', []);
        if ($tenantId) {
            $settings = Tenant::whereKey($tenantId)->value('settings');
            $settings = is_string($settings) ? json_decode($settings, true) : (array) $settings;
            $cfg = array_replace_recursive($cfg, (array) ($settings['kyc']['risk'] ?? []));
        }

        return $cfg;
    }

    public function latest(KycSubmission $s): ?KycRiskAssessment
    {
        return KycRiskAssessment::where('kyc_submission_id', $s->id)->orderByDesc('version')->first();
    }

    /**
     * Records a new assessment version. $inputs overlay the previous version's inputs (customer_type, country_code,
     * product_codes, channel, declared_factors); $declarations may carry source_of_funds / source_of_wealth.
     *
     * @param  array<string, mixed>  $inputs
     * @param  array{source_of_funds?: ?array, source_of_wealth?: ?array}  $declarations
     */
    public function assess(KycSubmission $s, array $inputs = [], array $declarations = [], ?string $actorId = null, ?string $reason = null): KycRiskAssessment
    {
        $prev = $this->latest($s);
        $cfg = $this->configuration($s->tenant_id);
        $party = Party::find($s->party_id);
        $in = array_replace($this->derivedInputs($s, $party), (array) ($prev?->inputs ?? []), array_filter($inputs, fn ($v) => $v !== null));
        $in['declared_factors'] = array_values(array_unique(array_map('strtoupper', (array) ($in['declared_factors'] ?? []))));

        // 1. Weighted factor score — only when every weight and every score used is configured.
        [$factors, $gaps, $score] = $this->score($cfg, $in);

        // 2. Facts: screening outcomes, reviewer-declared factors, beneficial ownership.
        $facts = $in['declared_factors'];
        foreach (ScreeningCheck::where('subject_type', 'kyc_submission')->where('subject_id', $s->id)->whereIn('status', ['POSSIBLE_MATCH', 'CONFIRMED_MATCH'])->get() as $c) {
            $facts[] = $c->check_type.'_'.$c->status;
        }
        $ubo = null;
        if ($s->subject_kind === 'CORPORATE' && $party) {
            $graph = app(PartyRelationshipService::class)->ultimateBeneficialOwners($party);
            $ubo = ['threshold' => $graph['threshold'], 'threshold_rule' => $graph['threshold_rule'] ?? 'GREATER_THAN',
                'owners' => array_map(fn ($o) => ['party_id' => $o['party_id'], 'effective_percentage' => $o['effective_percentage'], 'grounds' => $o['grounds'] ?? [], 'roles' => $o['roles'] ?? []], $graph['owners']),
                'unresolved' => $graph['unresolved']];
            if ($graph['owners'] === []) {
                $facts[] = 'UBO_NOT_IDENTIFIED';
            }
            if ($graph['unresolved'] !== []) {
                $facts[] = 'UBO_UNRESOLVED';
            }
        }
        $facts = array_values(array_unique($facts));

        // 3. Rating: hard triggers first, then configured bands.
        $hard = array_values(array_intersect($facts, (array) ($cfg['hard_triggers'] ?? [])));
        $rating = 'UNRATED';
        if ($hard !== []) {
            $rating = 'HIGH';
        } elseif ($score !== null) {
            $low = $cfg['bands']['LOW'] ?? null;
            $med = $cfg['bands']['MEDIUM'] ?? null;
            if ($low === null || $med === null) {
                $gaps[] = 'bands';
            } else {
                $rating = $score <= (float) $low ? 'LOW' : ($score <= (float) $med ? 'MEDIUM' : 'HIGH');
            }
        }
        if ($rating === 'HIGH') {
            $facts[] = 'HIGH_RISK';
        }

        // 4. EDD and source of funds / wealth.
        $edd = array_values(array_intersect(array_unique($facts), (array) ($cfg['edd_triggers'] ?? [])));
        $needs = function (string $key) use ($cfg, $edd, $rating): bool {
            $when = array_map('strtoupper', (array) ($cfg[$key] ?? []));

            return ($edd !== [] && in_array('EDD', $when, true)) || in_array($rating, $when, true);
        };
        // Gap Closure Pack 08: an approved (VERIFIED) tenant refresh policy wins over the config default.
        $refresh = app(\App\Application\Compliance\Catalogue\ComplianceCatalogueService::class)->monthsFor($s->tenant_id, $rating) ?? ($cfg['refresh_months'][$rating] ?? null);
        $rescreen = $cfg['rescreen_months'][$rating] ?? null;

        $a = KycRiskAssessment::create([
            'tenant_id' => $s->tenant_id, 'kyc_submission_id' => $s->id, 'party_id' => $s->party_id, 'version' => ($prev?->version ?? 0) + 1,
            'rating' => $rating, 'score' => $score, 'inputs' => $in, 'factors' => $factors,
            'triggers' => ['facts' => array_values(array_unique($facts)), 'hard' => $hard, 'edd' => $edd],
            'beneficial_ownership' => $ubo, 'edd_required' => $edd !== [],
            'source_of_funds_required' => $needs('source_of_funds_required_when'), 'source_of_wealth_required' => $needs('source_of_wealth_required_when'),
            'source_of_funds' => array_key_exists('source_of_funds', $declarations) ? $declarations['source_of_funds'] : $prev?->source_of_funds,
            'source_of_wealth' => array_key_exists('source_of_wealth', $declarations) ? $declarations['source_of_wealth'] : $prev?->source_of_wealth,
            'screening_mode' => ScreeningMode::normalize((string) config('kyc.screening.mode', ScreeningMode::MANUAL_AUDITED)),
            'configuration_gaps' => array_values(array_unique($gaps)),
            'refresh_months' => $refresh !== null ? (int) $refresh : null,
            'next_rescreen_at' => $rescreen !== null ? now()->addMonthsNoOverflow((int) $rescreen) : null,
            'reason' => $reason, 'assessed_by' => $actorId,
        ]);
        $this->audit->record('kyc_submission.risk_assessed', 'kyc_submission', $s->id, [
            'assessment_id' => $a->id, 'version' => $a->version, 'rating' => $rating, 'score' => $score, 'edd_required' => $a->edd_required,
            'triggers' => $a->triggers, 'configuration_gaps' => $a->configuration_gaps, 'screening_mode' => $a->screening_mode, 'automated_screening' => false, 'reason' => $reason,
        ]);

        return $a;
    }

    /** Risk factor codes to feed KycLevelResolver (HIGH_RISK / EDD_REQUIRED raise the level to ENHANCED). @return list<string> */
    public static function levelFactors(KycRiskAssessment $a): array
    {
        $out = [];
        if ($a->rating === 'HIGH') {
            $out[] = 'HIGH_RISK';
        }
        if ($a->edd_required) {
            $out[] = 'EDD_REQUIRED';
        }

        return $out;
    }

    /** Approval blockers from the current assessment. @return list<string> */
    public function blocking(KycSubmission $s): array
    {
        $a = $this->latest($s);
        if (! $a) {
            return [];
        }
        $out = [];
        if ($a->source_of_funds_required && empty($a->source_of_funds)) {
            $out[] = 'SOURCE_OF_FUNDS_REQUIRED';
        }
        if ($a->source_of_wealth_required && empty($a->source_of_wealth)) {
            $out[] = 'SOURCE_OF_WEALTH_REQUIRED';
        }
        if ($a->rating === 'UNRATED' && ($this->configuration($s->tenant_id)['require_rating'] ?? false)) {
            $out[] = 'RISK_RATING_REQUIRED';
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function present(?KycRiskAssessment $a): ?array
    {
        return $a ? $a->only(['id', 'version', 'rating', 'score', 'inputs', 'factors', 'triggers', 'beneficial_ownership', 'edd_required',
            'source_of_funds_required', 'source_of_wealth_required', 'source_of_funds', 'source_of_wealth', 'configuration_gaps', 'refresh_months', 'reason', 'assessed_by'])
            + ['next_rescreen_at' => $a->next_rescreen_at?->toIso8601String(), 'created_at' => $a->created_at?->toIso8601String()]
            + ScreeningMode::describe($a->screening_mode) : null;
    }

    /** @return array<string, mixed> */
    private function derivedInputs(KycSubmission $s, ?Party $party): array
    {
        $identity = (array) ($party?->legal_identity ?? []);
        $profile = (array) ($party?->profile ?? []);
        $country = $identity['nationality'] ?? $identity['country_code'] ?? $profile['country_code'] ?? $profile['country'] ?? null;

        return ['customer_type' => $s->subject_kind ?: 'INDIVIDUAL', 'country_code' => is_string($country) ? strtoupper($country) : null,
            'product_codes' => [], 'channel' => null, 'declared_factors' => []];
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $in
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: ?float}
     */
    private function score(array $cfg, array $in): array
    {
        $values = ['customer' => [$in['customer_type'] ?? null], 'country' => [$in['country_code'] ?? null],
            'product' => array_values((array) ($in['product_codes'] ?? [])), 'channel' => [$in['channel'] ?? null], 'occupation' => [$in['occupation_code'] ?? null]];
        $factors = [];
        $gaps = [];
        $sum = 0.0;
        $weights = 0.0;
        $complete = true;
        foreach (self::FACTORS as $f) {
            $weight = $cfg['factors'][$f]['weight'] ?? null;
            $vals = array_values(array_filter($values[$f], fn ($v) => $v !== null && $v !== ''));
            if (in_array($f, self::OPTIONAL_FACTORS, true) && $weight === null && $vals === []) {
                $factors[] = ['factor' => $f, 'values' => [], 'weight' => null, 'score' => null, 'status' => 'NOT_APPLICABLE'];

                continue;
            }
            $scores = [];
            foreach ($vals as $v) {
                $sc = $cfg['factors'][$f]['scores'][strtoupper((string) $v)] ?? null;
                $scores[] = $sc;
                if ($sc === null) {
                    $gaps[] = "factors.{$f}.scores.".strtoupper((string) $v);
                }
            }
            if ($weight === null) {
                $gaps[] = "factors.{$f}.weight";
            }
            $status = match (true) {
                $vals === [] => 'NO_INPUT',
                $weight === null || in_array(null, $scores, true) => 'NOT_CONFIGURED',
                default => 'SCORED',
            };
            // Several products: the riskiest one counts.
            $factorScore = $status === 'SCORED' ? max(array_map('floatval', $scores)) : null;
            $factors[] = ['factor' => $f, 'values' => $vals, 'weight' => $weight, 'score' => $factorScore, 'status' => $status];
            if ($status === 'SCORED') {
                $sum += $factorScore * (float) $weight;
                $weights += (float) $weight;
            } else {
                $complete = false;
                if ($status === 'NO_INPUT') {
                    $gaps[] = "inputs.{$f}";
                }
            }
        }
        $score = $complete && $weights > 0 ? round($sum / $weights, 4) : null;

        return [$factors, $gaps, $score];
    }
}
