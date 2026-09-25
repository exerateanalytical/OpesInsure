<?php

declare(strict_types=1);

namespace App\Application\Policies\Special;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Rules\Models\RuleSet;
use App\Application\Rules\RuleSetResolver;
use App\Application\Rules\RuleSetService;
use App\Domain\Rules\EligibilityOutcome;
use App\Domain\Rules\RuleSetEvaluator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PRD-011 — life surrender value (PRE §66–73; TRACEABILITY "life engine interface — actuarial values from carrier").
 *
 * The platform does not do actuarial maths: the carrier supplies a surrender scale per product (factor per policy year
 * in bps of a basis — premiums paid or the carrier's mathematical reserve — plus a surrender charge), activated by a
 * second user (maker-checker). Whether a surrender is allowed is decided by the existing rules engine: every APPROVED
 * ELIGIBILITY rule set for line LIFE (platform/line/product scope) whose code starts with LIFE_SURRENDER is evaluated
 * against the facts surrender.* (years_in_force, basis_minor, loans_outstanding_minor, policy_status, reason).
 * INELIGIBLE / MORE_INFORMATION_REQUIRED block the quote; REFER_TO_UNDERWRITING is returned flagged for review.
 *
 * net = floor(basis × factor_bps / 10000) − floor(gross × charge_bps / 10000) − loans, never below 0.
 * Read-only on policies; each computation is stored in life_surrender_quotes with the rule trace.
 */
final class LifeSurrenderService
{
    public const RULE_PREFIX = 'LIFE_SURRENDER';

    public const BASES = ['PREMIUMS_PAID', 'MATHEMATICAL_RESERVE'];

    public function __construct(
        private readonly SpecialPolicyGuard $guard,
        private readonly RuleSetResolver $resolver,
        private readonly RuleSetService $ruleSets,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly RuleSetEvaluator $evaluator = new RuleSetEvaluator,
    ) {}

    public function createScale(string $tenantId, array $d, User $actor): array
    {
        $product = DB::table('insurance_products')->where('id', $d['insurance_product_id'])->first();
        if (! $product || strtoupper((string) $product->line_code) !== 'LIFE') {
            throw ValidationException::withMessages(['insurance_product_id' => 'A surrender scale needs a LIFE product.']);
        }
        $basis = strtoupper($d['basis'] ?? 'PREMIUMS_PAID');
        if (! in_array($basis, self::BASES, true)) {
            throw ValidationException::withMessages(['basis' => 'Unknown surrender basis.']);
        }
        $factors = [];
        foreach ((array) $d['factors_bps'] as $year => $bps) {
            if (! ctype_digit((string) $year) || (int) $year < 1 || ! is_int($bps) || $bps < 0 || $bps > 10000) {
                throw ValidationException::withMessages(['factors_bps' => 'Factors map a policy year (>=1) to 0..10000 bps.']);
            }
            $factors[(string) (int) $year] = $bps;
        }
        if ($factors === []) {
            throw ValidationException::withMessages(['factors_bps' => 'At least one factor is required.']);
        }
        ksort($factors, SORT_NUMERIC);
        $version = (int) DB::table('life_surrender_scales')->where('insurance_product_id', $product->id)->max('version') + 1;
        $id = (string) Str::uuid();
        DB::table('life_surrender_scales')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'insurance_product_id' => $product->id, 'version' => $version, 'status' => 'DRAFT',
            'basis' => $basis, 'min_years_in_force' => (int) ($d['min_years_in_force'] ?? 2), 'factors_bps' => json_encode($factors),
            'surrender_charge_bps' => (int) ($d['surrender_charge_bps'] ?? 0), 'source_reference' => $d['source_reference'] ?? null,
            'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('life_surrender_scale.created', 'life_surrender_scale', $id, ['product_id' => $product->id, 'version' => $version]);

        return $this->scale($tenantId, $id);
    }

    public function activateScale(string $tenantId, string $scaleId, User $actor): array
    {
        $s = DB::table('life_surrender_scales')->where('id', $scaleId)->where('tenant_id', $tenantId)->first() ?? abort(404, 'Scale not found.');
        if ($s->status !== 'DRAFT') {
            throw ValidationException::withMessages(['scale' => 'Only a DRAFT scale can be activated.']);
        }
        if ($s->created_by === $actor->id) {
            throw ValidationException::withMessages(['scale' => 'Maker-checker: the author cannot activate their own scale.']);
        }
        DB::transaction(function () use ($s, $actor, $tenantId) {
            DB::table('life_surrender_scales')->where('insurance_product_id', $s->insurance_product_id)->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->update(['status' => 'RETIRED', 'updated_at' => now()]);
            DB::table('life_surrender_scales')->where('id', $s->id)->update(['status' => 'ACTIVE', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record('life_surrender_scale.activated', 'life_surrender_scale', $s->id, ['product_id' => $s->insurance_product_id, 'version' => $s->version]);
            $this->outbox->record('life_surrender_scale.activated', 'life_surrender_scale', $s->id, ['tenant_id' => $tenantId, 'product_id' => $s->insurance_product_id, 'version' => (int) $s->version]);
        });

        return $this->scale($tenantId, $s->id);
    }

    public function scale(string $tenantId, string $id): array
    {
        $s = DB::table('life_surrender_scales')->where('id', $id)->where('tenant_id', $tenantId)->first() ?? abort(404);

        return [
            'id' => $s->id, 'insurance_product_id' => $s->insurance_product_id, 'version' => (int) $s->version, 'status' => $s->status, 'basis' => $s->basis,
            'min_years_in_force' => (int) $s->min_years_in_force, 'factors_bps' => json_decode($s->factors_bps, true),
            'surrender_charge_bps' => (int) $s->surrender_charge_bps, 'source_reference' => $s->source_reference,
        ];
    }

    /** @param array{as_of?:?string, basis_minor:int, loans_outstanding_minor?:int, reason?:?string} $d */
    public function quote(string $tenantId, string $policyId, array $d, User $actor): array
    {
        $policy = $this->guard->policy($tenantId, $policyId);
        $productId = $this->productOf($policy);
        $line = strtoupper((string) (json_decode((string) $policy->terms_snapshot, true)['line_code'] ?? DB::table('insurance_products')->where('id', $productId)->value('line_code') ?? ''));
        if ($line !== 'LIFE') {
            throw ValidationException::withMessages(['policy' => 'Surrender applies to LIFE policies only.']);
        }
        $scale = DB::table('life_surrender_scales')->where('tenant_id', $tenantId)->where('insurance_product_id', $productId)->where('status', 'ACTIVE')->first();
        if (! $scale) {
            throw ValidationException::withMessages(['policy' => 'No active carrier surrender scale for this product.']);
        }
        $asOf = \Carbon\CarbonImmutable::parse($d['as_of'] ?? now())->startOfDay();
        $years = max(0, (int) \Carbon\CarbonImmutable::parse($policy->coverage_starts_at)->startOfDay()->diffInYears($asOf, false));
        $basis = (int) $d['basis_minor'];
        $loans = (int) ($d['loans_outstanding_minor'] ?? 0);

        $facts = [
            'surrender.years_in_force' => $years, 'surrender.basis_minor' => $basis, 'surrender.loans_outstanding_minor' => $loans,
            'surrender.policy_status' => strtoupper((string) $policy->status), 'surrender.min_years_in_force' => (int) $scale->min_years_in_force,
            'context.today' => $asOf->toDateString(), 'context.line_code' => 'LIFE',
        ];
        if (! empty($d['reason'])) {
            $facts['surrender.reason'] = strtoupper((string) $d['reason']);
        }
        // Platform floor: the scale's minimum duration (carrier term) is always enforced.
        $fired = [];
        $trace = [];
        $missing = [];
        if ($years < (int) $scale->min_years_in_force) {
            $reasons = ['SURRENDER_MIN_YEARS_NOT_REACHED'];
            $outcome = EligibilityOutcome::INELIGIBLE;
        } else {
            $sets = $this->resolver->resolve('ELIGIBILITY', $productId, 'LIFE', $asOf)->filter(fn (RuleSet $s) => str_starts_with($s->code, self::RULE_PREFIX));
            foreach ($sets as $set) {
                $ev = $this->evaluator->evaluate($this->ruleSets->toDefinition($set), $facts);
                $fired = [...$fired, ...$ev['fired']];
                $trace = [...$trace, ...array_map(fn ($t) => $t + ['rule_set' => $set->code], $ev['trace'])];
                $missing = [...$missing, ...$ev['missing']];
            }
            $c = RuleSetEvaluator::combineEligibility(['fired' => $fired, 'trace' => $trace, 'missing' => array_values(array_unique($missing)), 'stopped_at' => null]);
            $outcome = $c['outcome'];
            $reasons = $c['reasons'];
        }

        $factor = self::factorFor(json_decode($scale->factors_bps, true), $years);
        $blocked = in_array($outcome, [EligibilityOutcome::INELIGIBLE, EligibilityOutcome::MORE_INFORMATION_REQUIRED], true);
        $gross = $blocked ? 0 : intdiv($basis * $factor, 10000);
        $charge = intdiv($gross * (int) $scale->surrender_charge_bps, 10000);
        $net = max(0, $gross - $charge - $loans);

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $tenantId, $policyId, $scale, $asOf, $outcome, $years, $basis, $factor, $gross, $charge, $loans, $net, $policy, $reasons, $trace, $actor) {
            DB::table('life_surrender_quotes')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'policy_id' => $policyId, 'scale_id' => $scale->id, 'as_of' => $asOf->toDateString(),
                'outcome' => $outcome->value, 'years_in_force' => $years, 'basis_minor' => $basis, 'factor_bps' => $factor, 'gross_value_minor' => $gross,
                'charge_minor' => $charge, 'loans_outstanding_minor' => $loans, 'net_value_minor' => $net, 'currency' => $policy->currency ?? 'XAF',
                'reasons' => json_encode($reasons), 'trace' => json_encode($trace), 'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('life_surrender.quoted', 'policy', $policyId, ['quote_id' => $id, 'outcome' => $outcome->value, 'net_value_minor' => $net]);
            $this->outbox->record('life_surrender.quoted', 'policy', $policyId, ['tenant_id' => $tenantId, 'quote_id' => $id, 'outcome' => $outcome->value, 'net_value_minor' => $net]);
        });

        return [
            'id' => $id, 'policy_id' => $policyId, 'as_of' => $asOf->toDateString(), 'outcome' => $outcome->value, 'blocked' => $blocked,
            'requires_review' => $outcome === EligibilityOutcome::REFER_TO_UNDERWRITING, 'reasons' => $reasons,
            'scale' => ['id' => $scale->id, 'version' => (int) $scale->version, 'basis' => $scale->basis],
            'years_in_force' => $years, 'basis_minor' => $basis, 'factor_bps' => $factor, 'gross_value_minor' => $gross,
            'charge_minor' => $charge, 'loans_outstanding_minor' => $loans, 'net_value_minor' => $net, 'currency' => $policy->currency ?? 'XAF', 'trace' => $trace,
        ];
    }

    /** Factor for the policy year: the highest configured year <= years in force (0 when none). */
    public static function factorFor(array $factors, int $years): int
    {
        $f = 0;
        foreach ($factors as $y => $bps) {
            if ((int) $y <= $years) {
                $f = (int) $bps;
            }
        }

        return $f;
    }

    private function productOf(object $policy): string
    {
        $id = DB::table('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
            ->where('proposals.id', $policy->proposal_id)->value('quote_offers.product_id');
        if (! $id) {
            throw ValidationException::withMessages(['policy' => 'Policy product could not be resolved.']);
        }

        return (string) $id;
    }
}
