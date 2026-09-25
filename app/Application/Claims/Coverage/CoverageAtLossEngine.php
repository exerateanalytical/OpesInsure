<?php

declare(strict_types=1);

namespace App\Application\Claims\Coverage;

use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Application\Rules\PremiumCover\PremiumCoverEvaluator;
use App\Domain\Rules\Expression\ExpressionEvaluator;
use App\Models\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CLM-003 — coverage-at-loss engine. Pure read over the contract chronology:
 *
 *  - policy_versions as of the loss date, as known now AND as known at the report date (bitemporal);
 *  - policy_coverages of that version (coverage held, coverage window);
 *  - product-model exclusions (product_exclusions + exclusion_definitions) with the APPROVED legal text
 *    effective at the loss date (exclusion_legal_texts);
 *  - Batch 8 suspension / cancellation / lapse periods (policy_suspensions, policy_cancellations,
 *    policy_premium_instalments + policy_recovery_cases);
 *  - premium-to-cover state through the one PremiumCoverEvaluator.
 *
 * Outcome precedence: OUTSIDE_COVERAGE > POTENTIAL_EXCLUSION > REVIEW_REQUIRED > COVERAGE_CONFIRMED.
 * Every reason is explainable ({code, outcome, message, evidence}). The engine NEVER rejects a claim:
 * any outcome other than COVERAGE_CONFIRMED sets requires_review = true and routes to a human.
 */
final class CoverageAtLossEngine
{
    public const ENGINE_VERSION = 'clm003.v1';

    public const OUTCOMES = ['COVERAGE_CONFIRMED', 'REVIEW_REQUIRED', 'POTENTIAL_EXCLUSION', 'OUTSIDE_COVERAGE'];

    private const RANK = ['COVERAGE_CONFIRMED' => 0, 'REVIEW_REQUIRED' => 1, 'POTENTIAL_EXCLUSION' => 2, 'OUTSIDE_COVERAGE' => 3];

    /** @var list<array<string, mixed>> */
    private array $reasons = [];

    public function __construct(
        private readonly PolicyChronologyWriter $chronology,
        private readonly PremiumCoverEvaluator $premiumCover,
        private readonly ExpressionEvaluator $expressions,
    ) {}

    /**
     * @param  array<string, mixed>  $facts  loss facts used by exclusion conditions (e.g. {"loss": {"cause": "FLOOD"}})
     * @return array<string, mixed>
     */
    public function evaluate(Policy $policy, \DateTimeInterface $lossAt, ?\DateTimeInterface $reportedAt = null, ?string $coverageCode = null, array $facts = []): array
    {
        $this->reasons = [];
        $loss = CarbonImmutable::instance($lossAt);
        $reported = CarbonImmutable::instance($reportedAt ?? now());
        $lossDate = $loss->toDateString();

        $current = $this->chronology->asOf($policy->id, $loss);
        $atReport = $this->chronology->asOf($policy->id, $loss, $reported);
        $hasChronology = DB::table('policy_versions')->where('policy_id', $policy->id)->exists();

        // 1. Contract period / chronology.
        if ($current === null) {
            if ($hasChronology) {
                $this->add('NO_VERSION_AT_LOSS_DATE', 'OUTSIDE_COVERAGE', 'No policy version was in force at the loss date.', ['loss_occurred_at' => $loss->toIso8601String()]);
            } else {
                $this->add('NO_CHRONOLOGY', 'REVIEW_REQUIRED', 'The policy has no recorded chronology; the check falls back to the policy period.', []);
            }
        }
        $starts = $policy->coverage_starts_at ? CarbonImmutable::instance($policy->coverage_starts_at) : null;
        $ends = $policy->coverage_ends_at ? CarbonImmutable::instance($policy->coverage_ends_at) : null;
        if (($starts && $loss->lt($starts)) || ($ends && $loss->gt($ends))) {
            $this->add('LOSS_OUTSIDE_POLICY_PERIOD', 'OUTSIDE_COVERAGE', 'The loss date is outside the policy period.', [
                'coverage_starts_at' => $starts?->toIso8601String(), 'coverage_ends_at' => $ends?->toIso8601String(),
            ]);
        }
        if ($current !== null && in_array($current->kind, ['CANCELLATION', 'SUSPENSION'], true)) {
            $this->add('VERSION_'.$current->kind, 'OUTSIDE_COVERAGE', "The policy version in force at the loss date is a {$current->kind} version.", ['policy_version_id' => $current->id, 'version_no' => $current->version_no]);
        }
        if (($current->id ?? null) !== ($atReport->id ?? null)) {
            $this->add('CHRONOLOGY_CHANGED_SINCE_REPORT', 'REVIEW_REQUIRED', 'The contract as known at the report date differs from the contract as known now for the loss date (retroactive change).', [
                'version_known_at_report' => $atReport->version_no ?? null, 'version_known_now' => $current->version_no ?? null,
            ]);
        }

        // 2. Cancellation / suspension / lapse periods (Batch 8).
        $this->cancellations($policy, $loss);
        $this->suspensions($policy, $loss);
        $premium = $this->premium($policy, $loss, $current);

        // 3. Coverage held at the loss date.
        $coverages = $current ? DB::table('policy_coverages')->where('policy_version_id', $current->id)->orderBy('coverage_code')->get() : collect();
        $coverage = null;
        if ($coverageCode === null) {
            $this->add('COVERAGE_NOT_SPECIFIED', 'REVIEW_REQUIRED', 'No coverage was named for the loss; a handler must map the loss to a coverage.', ['coverages_in_force' => $coverages->pluck('coverage_code')->all()]);
        } elseif ($current !== null) {
            $coverage = $coverages->firstWhere('coverage_code', $coverageCode);
            if ($coverage === null) {
                $this->add('COVERAGE_NOT_HELD', 'OUTSIDE_COVERAGE', "Coverage {$coverageCode} was not part of the contract at the loss date.", ['coverages_in_force' => $coverages->pluck('coverage_code')->all()]);
            } elseif ($loss->lt(CarbonImmutable::parse($coverage->starts_at)) || ($coverage->ends_at && $loss->gt(CarbonImmutable::parse($coverage->ends_at)))) {
                $this->add('COVERAGE_PERIOD_EXCLUDES_LOSS', 'OUTSIDE_COVERAGE', "Coverage {$coverageCode} was not running at the loss date.", ['starts_at' => $coverage->starts_at, 'ends_at' => $coverage->ends_at]);
            }
        }

        // 4. Exclusions + legal texts from the product model.
        $productId = $this->productId($policy, $current);
        $exclusions = $this->exclusions($productId, $coverageCode, $lossDate, $facts + ['loss' => ['occurred_at' => $loss->toIso8601String(), 'coverage_code' => $coverageCode], 'context' => ['today' => $lossDate]]);

        $outcome = 'COVERAGE_CONFIRMED';
        foreach ($this->reasons as $r) {
            if (self::RANK[$r['outcome']] > self::RANK[$outcome]) {
                $outcome = $r['outcome'];
            }
        }
        if ($outcome === 'COVERAGE_CONFIRMED') {
            $this->add('COVERAGE_CONFIRMED', 'COVERAGE_CONFIRMED', 'The loss falls within an in-force coverage with no suspension, lapse, cancellation or triggered exclusion.', ['coverage_code' => $coverageCode]);
        }

        return [
            'engine_version' => self::ENGINE_VERSION,
            'outcome' => $outcome,
            'requires_review' => $outcome !== 'COVERAGE_CONFIRMED',
            'auto_rejected' => false,
            'policy_id' => $policy->id,
            'product_id' => $productId,
            'loss_occurred_at' => $loss->toIso8601String(),
            'reported_at' => $reported->toIso8601String(),
            'coverage_code' => $coverageCode,
            'policy_version' => $current ? ['id' => $current->id, 'version_no' => $current->version_no, 'kind' => $current->kind, 'valid_from' => $current->valid_from, 'valid_to' => $current->valid_to, 'snapshot_hash' => $current->snapshot_hash] : null,
            'policy_version_known_at_report' => $atReport ? ['id' => $atReport->id, 'version_no' => $atReport->version_no, 'kind' => $atReport->kind] : null,
            'coverage' => $coverage ? ['coverage_code' => $coverage->coverage_code, 'limit_minor' => $coverage->limit_minor, 'deductible_minor' => $coverage->deductible_minor, 'currency' => $coverage->currency, 'starts_at' => $coverage->starts_at, 'ends_at' => $coverage->ends_at] : null,
            'coverages_in_force' => $coverages->pluck('coverage_code')->values()->all(),
            'premium' => $premium,
            'exclusions' => $exclusions,
            'reasons' => $this->reasons,
        ];
    }

    private function cancellations(Policy $policy, CarbonImmutable $loss): void
    {
        $c = DB::table('policy_cancellations')->where('policy_id', $policy->id)->where('status', 'APPROVED')->where('effective_at', '<=', $loss)->orderBy('effective_at')->first();
        if ($c) {
            $this->add('POLICY_CANCELLED', 'OUTSIDE_COVERAGE', 'The policy was cancelled before the loss date.', ['cancellation_id' => $c->id, 'effective_at' => $c->effective_at, 'reason_code' => $c->reason_code]);
        }
        $pending = DB::table('policy_cancellations')->where('policy_id', $policy->id)->whereIn('status', ['REQUESTED', 'UNDER_REVIEW'])->where('effective_at', '<=', $loss)->first();
        if ($pending) {
            $this->add('CANCELLATION_PENDING', 'REVIEW_REQUIRED', 'A cancellation effective before the loss date is pending a decision.', ['cancellation_id' => $pending->id, 'effective_at' => $pending->effective_at]);
        }
    }

    private function suspensions(Policy $policy, CarbonImmutable $loss): void
    {
        foreach (DB::table('policy_suspensions')->where('policy_id', $policy->id)->where('suspended_at', '<=', $loss)->get() as $s) {
            $end = $s->reinstated_at ?? $s->ended_at;
            if ($end === null || CarbonImmutable::parse($end)->gt($loss)) {
                $this->add('POLICY_SUSPENDED', 'OUTSIDE_COVERAGE', 'The policy was suspended at the loss date.', ['suspension_id' => $s->id, 'suspended_at' => $s->suspended_at, 'ended_at' => $end, 'source' => $s->source, 'reason_code' => $s->reason_code]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function premium(Policy $policy, CarbonImmutable $loss, ?object $version): array
    {
        $rows = DB::table('policy_premium_instalments')->where('policy_id', $policy->id)->whereDate('due_date', '<=', $loss->toDateString())->orderBy('sequence')->get();
        if ($rows->isEmpty()) {
            return ['state' => 'NO_INSTALMENT_DUE', 'unpaid_instalments' => []];
        }
        $recoveries = DB::table('policy_recovery_cases')->where('policy_id', $policy->id)->where('status', 'APPROVED')->where('decided_at', '<=', $loss)->pluck('decided_at');
        $unpaid = [];
        foreach ($rows as $i) {
            $settledBefore = $i->settled_at !== null && CarbonImmutable::parse($i->settled_at)->lte($loss);
            if ($settledBefore || $i->status === 'WAIVED') {
                continue;
            }
            if ($i->lapsed_at !== null && CarbonImmutable::parse($i->lapsed_at)->lte($loss)
                && ! $recoveries->contains(fn ($d) => CarbonImmutable::parse($d)->gte(CarbonImmutable::parse($i->lapsed_at)))) {
                $this->add('POLICY_LAPSED', 'OUTSIDE_COVERAGE', 'The policy had lapsed for non-payment before the loss date.', ['instalment_id' => $i->id, 'sequence' => $i->sequence, 'lapsed_at' => $i->lapsed_at]);
            }
            $unpaid[] = ['instalment_id' => $i->id, 'sequence' => $i->sequence, 'due_date' => $i->due_date, 'amount_minor' => $i->amount_minor, 'paid_minor' => $i->paid_minor, 'status' => $i->status];
        }
        if ($unpaid === []) {
            return ['state' => 'PAID', 'unpaid_instalments' => []];
        }
        $partial = collect($unpaid)->contains(fn ($u) => $u['paid_minor'] > 0);
        $eval = $this->premiumCover->evaluate([
            'product_id' => $this->productId($policy, $version), 'carrier_id' => $policy->carrier_id,
            'premium_status' => $partial ? 'PARTIALLY_PAID' : 'OVERDUE', 'effective_date' => $loss->toDateString(),
        ]);
        $evidence = ['premium_cover_outcome' => $eval['outcome'], 'rule' => $eval['rule'], 'unpaid_instalments' => count($unpaid)];
        match ($eval['outcome']) {
            'COVER_ACTIVE' => null,
            'GRACE' => $this->add('PREMIUM_IN_GRACE', 'REVIEW_REQUIRED', 'Premium was unpaid at the loss date; the premium-to-cover rules place it in grace.', $evidence),
            'NO_COVER', 'COVER_SUSPENDED' => $this->add('PREMIUM_NOT_COVERING', 'OUTSIDE_COVERAGE', 'Premium was unpaid at the loss date and the premium-to-cover rules give no cover.', $evidence),
            default => $this->add('PREMIUM_COVER_UNDETERMINED', 'REVIEW_REQUIRED', 'Premium was unpaid at the loss date and no premium-to-cover rule decides the cover.', $evidence + ['missing_facts' => $eval['missing_facts']]),
        };

        return ['state' => 'UNPAID', 'premium_cover' => ['outcome' => $eval['outcome'], 'cover_active' => $eval['cover_active'], 'rule' => $eval['rule']], 'unpaid_instalments' => $unpaid];
    }

    private function productId(Policy $policy, ?object $version): ?string
    {
        if ($version !== null) {
            $snap = json_decode((string) $version->snapshot, true);
            if (! empty($snap['version']['product_id'])) {
                return $snap['version']['product_id'];
            }
        }
        if (! empty($policy->terms_snapshot['product_id'])) {
            return $policy->terms_snapshot['product_id'];
        }

        return $policy->proposal_id ? DB::table('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
            ->where('proposals.id', $policy->proposal_id)->value('quote_offers.product_id') : null;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    private function exclusions(?string $productId, ?string $coverageCode, string $lossDate, array $facts): array
    {
        if ($productId === null) {
            $this->add('PRODUCT_UNKNOWN', 'REVIEW_REQUIRED', 'The product of the policy could not be resolved; exclusions were not checked.', []);

            return [];
        }
        $rows = DB::table('product_exclusions as pe')->join('exclusion_definitions as ed', 'ed.id', '=', 'pe.exclusion_definition_id')
            ->leftJoin('coverage_definitions as cd', 'cd.id', '=', 'pe.coverage_definition_id')
            ->where('pe.insurance_product_id', $productId)->where('ed.kind', 'EXCLUSION')->where('ed.status', 'ACTIVE')
            ->orderBy('ed.code')->get(['pe.id', 'pe.level', 'pe.condition', 'ed.id as definition_id', 'ed.code', 'ed.name', 'cd.code as coverage_code']);

        $out = [];
        foreach ($rows as $r) {
            if ($r->level === 'COVERAGE' && $r->coverage_code !== $coverageCode) {
                continue;
            }
            $text = DB::table('exclusion_legal_texts')->where('exclusion_definition_id', $r->definition_id)->where('status', 'APPROVED')
                ->whereDate('effective_from', '<=', $lossDate)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $lossDate))
                ->orderByDesc('version')->first();
            $condition = json_decode((string) $r->condition, true) ?: [];
            $result = $condition === [] ? null : $this->expressions->evaluate($condition, $facts);
            $status = $condition === [] ? 'IN_FORCE' : match ($result['result']) { true => 'TRIGGERED', false => 'NOT_TRIGGERED', default => 'UNKNOWN' };
            $item = [
                'exclusion_definition_id' => $r->definition_id, 'code' => $r->code, 'name' => json_decode((string) $r->name, true), 'level' => $r->level,
                'status' => $status, 'missing_facts' => $result['missing'] ?? [],
                'legal_text' => $text ? ['id' => $text->id, 'version' => $text->version, 'text' => json_decode((string) $text->text, true), 'legal_reference' => $text->legal_reference, 'text_hash' => $text->text_hash] : null,
            ];
            $out[] = $item;
            $evidence = ['exclusion_code' => $r->code, 'level' => $r->level, 'legal_text_id' => $text->id ?? null, 'legal_text_version' => $text->version ?? null, 'legal_reference' => $text->legal_reference ?? null];
            if ($status === 'TRIGGERED') {
                $this->add('EXCLUSION_TRIGGERED', 'POTENTIAL_EXCLUSION', "Exclusion {$r->code} may apply to this loss.", $evidence + ['referenced_facts' => $result['referenced']]);
                if ($text === null) {
                    $this->add('EXCLUSION_LEGAL_TEXT_MISSING', 'REVIEW_REQUIRED', "Exclusion {$r->code} has no approved legal text effective at the loss date.", $evidence);
                }
            } elseif ($status === 'UNKNOWN') {
                $this->add('EXCLUSION_FACTS_MISSING', 'REVIEW_REQUIRED', "Exclusion {$r->code} could not be evaluated: loss facts are missing.", $evidence + ['missing_facts' => $result['missing']]);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $evidence */
    private function add(string $code, string $outcome, string $message, array $evidence): void
    {
        $this->reasons[] = ['code' => $code, 'outcome' => $outcome, 'message' => $message, 'evidence' => $evidence];
    }
}
