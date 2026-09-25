<?php

declare(strict_types=1);

namespace App\Application\Fraud;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Events\OutboxWriter;
use App\Models\Claim;
use App\Models\FraudRuleVersion;
use App\Models\RiskAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-FRD-001 / WF-089 — rules-driven, versioned, explainable claim fraud indicators.
 *
 *   assess()        evaluates every ACTIVE, in-force CLAIM-scope fraud_rule_versions row; each fired rule becomes an
 *                   explainable indicator (rule code + version + hash + the facts that matched). Any indicator puts the
 *                   claim under review: claims.fraud_flag = REVIEW_REQUIRED, a CLAIM_FRAUD_REVIEW risk alert and a
 *                   CLAIM_INVESTIGATION case (case engine). Never FRAUD_CONFIRMED (LOCK-010).
 *   recordOutcome() the ONLY path to a fraud outcome: a named human records CLEARED / MONITOR / CONFIRMED_FRAUD with a
 *                   rationale; it becomes an append-only case decision, the alert is decided and the flag is set.
 * While the review is open, ClaimFraudHold blocks approval / settlement (ClaimTransitionGuard, C1 contract).
 */
final class ClaimFraudIndicatorService
{
    public const ALERT_TYPE = 'CLAIM_FRAUD_REVIEW';

    public const CASE_TYPE = 'CLAIM_INVESTIGATION';

    public const OUTCOMES = ['CLEARED' => 'CLEARED', 'MONITOR' => 'MONITOR', 'CONFIRMED_FRAUD' => 'FRAUD_CONFIRMED'];

    public function __construct(
        private readonly RuleConditionEvaluator $evaluator,
        private readonly ClaimFraudFacts $facts,
        private readonly CaseService $cases,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @return list<array{rule_id:string,code:string,version:int,rule_hash:string,risk_points:int,reasons:list<string>}> */
    public function indicators(Claim $claim, ?array $facts = null): array
    {
        $facts ??= $this->facts->for($claim);
        $today = now()->toDateString();
        $rules = FraudRuleVersion::where('scope', 'CLAIM')->where('status', 'ACTIVE')->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
            ->orderBy('code')->orderByDesc('version')->get();
        $out = [];
        foreach ($rules->unique('code') as $rule) {
            $r = $this->evaluator->evaluate((array) $rule->conditions, $facts);
            if ($r['matched']) {
                $out[] = ['rule_id' => $rule->id, 'code' => $rule->code, 'version' => (int) $rule->version, 'rule_hash' => $rule->rule_hash,
                    'risk_points' => (int) $rule->risk_points, 'reasons' => $r['reasons']];
            }
        }

        return $out;
    }

    /** @return array{indicators: list<array>, facts: array<string,mixed>, review: ?RiskAlert, case_id: ?string} */
    public function assess(Claim $claim, ?User $actor): array
    {
        $facts = $this->facts->for($claim);
        $indicators = $this->indicators($claim, $facts);
        if ($indicators === []) {
            $this->audit->record('fraud.claim.assessed', 'claim', $claim->id, ['indicators' => 0]);

            return ['indicators' => [], 'facts' => $facts, 'review' => null, 'case_id' => null];
        }

        return DB::transaction(function () use ($claim, $actor, $facts, $indicators) {
            $claim = Claim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            if ($open = $this->openReview($claim->id)) {
                return ['indicators' => $indicators, 'facts' => $facts, 'review' => $open, 'case_id' => $open->case_id];
            }
            $score = min(100, array_sum(array_column($indicators, 'risk_points')));
            $signals = ['indicators' => $indicators, 'facts' => $facts, 'automated_outcome' => 'REVIEW_REQUIRED'];
            $n = RiskAlert::where('subject_id', $claim->id)->where('alert_type', self::ALERT_TYPE)->count() + 1;
            $alert = RiskAlert::create([
                'tenant_id' => $claim->tenant_id, 'subject_type' => 'CLAIM', 'subject_id' => $claim->id, 'alert_type' => self::ALERT_TYPE,
                'risk_score' => $score, 'severity' => self::severity($score), 'status' => 'OPEN', 'signals' => $signals,
                'fraud_rule_version_id' => $indicators[0]['rule_id'], 'idempotency_key' => "claim-fraud-review:{$claim->id}:{$n}",
                'payload_hash' => hash('sha256', json_encode($signals, JSON_THROW_ON_ERROR)),
            ]);
            $case = $this->cases->open($claim->tenant_id, self::CASE_TYPE, [
                'title' => "Suspicious claim review {$claim->claim_number}", 'priority' => $score >= 60 ? 'HIGH' : 'NORMAL',
                'subject_type' => 'claim', 'subject_id' => $claim->id, 'source_type' => 'risk_alert', 'source_id' => $alert->id,
                'idempotency_key' => 'fraud-review-'.$alert->id,
            ], $actor);
            $alert->forceFill(['case_id' => $case->id])->save();
            DB::table('claims')->where('id', $claim->id)->update(['fraud_flag' => 'REVIEW_REQUIRED', 'fraud_flag_set_by' => null, 'fraud_flag_set_at' => now(), 'updated_at' => now()]);
            $payload = ['claim_id' => $claim->id, 'risk_alert_id' => $alert->id, 'case_id' => $case->id, 'risk_score' => $score,
                'rules' => array_map(fn ($i) => $i['code'].'@v'.$i['version'], $indicators)];
            $this->audit->record('fraud.claim.review_required', 'claim', $claim->id, $payload);
            $this->outbox->record('fraud.claim.review_required', 'claim', $claim->id, $payload);

            return ['indicators' => $indicators, 'facts' => $facts, 'review' => $alert->refresh(), 'case_id' => $case->id];
        });
    }

    /** WF-089: the human review outcome. CONFIRMED_FRAUD is only ever reachable here. */
    public function recordOutcome(RiskAlert $alert, string $outcome, string $rationale, User $reviewer): RiskAlert
    {
        if (! isset(self::OUTCOMES[$outcome])) {
            throw ValidationException::withMessages(['outcome' => 'Outcome must be one of '.implode(', ', array_keys(self::OUTCOMES)).'.']);
        }
        if (mb_strlen(trim($rationale)) < 20) {
            throw ValidationException::withMessages(['rationale' => 'A rationale of at least 20 characters is required.']);
        }

        return DB::transaction(function () use ($alert, $outcome, $rationale, $reviewer) {
            $alert = RiskAlert::whereKey($alert->id)->lockForUpdate()->firstOrFail();
            if ($alert->alert_type !== self::ALERT_TYPE || ! in_array($alert->status, ['OPEN', 'UNDER_REVIEW'], true)) {
                throw ValidationException::withMessages(['status' => 'This fraud review is not open.']);
            }
            if ($alert->case_id && ($case = WorkCase::withoutGlobalScopes()->find($alert->case_id))) {
                $this->cases->decide($case, ['decision_type' => 'FRAUD_REVIEW', 'outcome' => $outcome, 'rationale' => $rationale], $reviewer);
            }
            $alert->update(['status' => 'DECIDED', 'decision' => $outcome, 'decision_notes' => $rationale, 'decided_by' => $reviewer->id,
                'decided_at' => now(), 'version' => $alert->version + 1]);
            DB::table('claims')->where('id', $alert->subject_id)->update(['fraud_flag' => self::OUTCOMES[$outcome], 'fraud_flag_set_by' => $reviewer->id,
                'fraud_flag_set_at' => now(), 'updated_at' => now()]);
            $payload = ['claim_id' => $alert->subject_id, 'risk_alert_id' => $alert->id, 'case_id' => $alert->case_id, 'outcome' => $outcome, 'decided_by' => $reviewer->id];
            $this->audit->record('fraud.claim.review_decided', 'claim', $alert->subject_id, $payload, $rationale);
            $this->outbox->record('fraud.claim.review_decided', 'claim', $alert->subject_id, $payload);

            return $alert->refresh();
        });
    }

    public function openReview(string $claimId): ?RiskAlert
    {
        return RiskAlert::where('subject_id', $claimId)->where('alert_type', self::ALERT_TYPE)->whereIn('status', ['OPEN', 'UNDER_REVIEW'])->first();
    }

    public static function severity(int $score): string
    {
        return match (true) {
            $score >= 80 => 'CRITICAL', $score >= 60 => 'HIGH', $score >= 30 => 'MEDIUM', default => 'LOW',
        };
    }
}
