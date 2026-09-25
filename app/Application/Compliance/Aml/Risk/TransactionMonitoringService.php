<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Risk;

use App\Application\Audit\AuditWriter;
use App\Application\Fraud\RuleConditionEvaluator;
use App\Models\FraudRuleVersion;
use App\Models\RiskAlert;
use Illuminate\Support\Facades\DB;

/**
 * REQ-AML-002 — transaction monitoring. Rules are fraud_rule_versions rows with scope AML_TRANSACTION (created and
 * maker-checker approved through the existing fraud-rules endpoints) evaluated by the Batch 12 C16
 * RuleConditionEvaluator. Every threshold lives in the rule's conditions; NONE is seeded (owner question OQ-5.2),
 * so monitoring is INACTIVE until a compliance officer configures and approves a rule.
 * Each fired rule opens one explainable risk_alerts row (alert_type AML_TRANSACTION_MONITORING, idempotent per rule
 * version + subject). No outbox event, no notification: AML alerts are internal (tipping-off, Reg. 003-25).
 */
final class TransactionMonitoringService
{
    public const SCOPE = 'AML_TRANSACTION';

    public const ALERT_TYPE = 'AML_TRANSACTION_MONITORING';

    public function __construct(
        private readonly RuleConditionEvaluator $evaluator,
        private readonly AmlRiskRatingService $risk,
        private readonly AuditWriter $audit,
    ) {}

    /** @return \Illuminate\Support\Collection<int, FraudRuleVersion> */
    public function activeRules()
    {
        $today = now()->toDateString();

        return FraudRuleVersion::where('scope', self::SCOPE)->where('status', 'ACTIVE')->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
            ->orderBy('code')->orderByDesc('version')->get()->unique('code')->values();
    }

    /**
     * @param  array{subject_type: string, subject_id: string, party_id?: ?string, facts: array<string, mixed>}  $txn
     * @return array{status: string, facts: array<string, mixed>, alerts: list<array<string, mixed>>}
     */
    public function evaluate(string $tenantId, array $txn): array
    {
        $rules = $this->activeRules();
        $facts = (array) $txn['facts'];
        if (! empty($txn['party_id']) && ! array_key_exists('customer_risk_band', $facts)) {
            $facts['customer_risk_band'] = $this->risk->latest($tenantId, $txn['party_id'])['band'] ?? null;
        }
        if ($rules->isEmpty()) {
            return ['status' => 'INACTIVE_NOT_CONFIGURED', 'facts' => $facts, 'alerts' => []];
        }
        $alerts = [];
        foreach ($rules as $rule) {
            $r = $this->evaluator->evaluate((array) $rule->conditions, $facts);
            if (! $r['matched']) {
                continue;
            }
            $key = "aml-tm:{$rule->id}:{$txn['subject_id']}";
            $alert = RiskAlert::where('tenant_id', $tenantId)->where('idempotency_key', $key)->first();
            if (! $alert) {
                $signals = ['rule' => ['id' => $rule->id, 'code' => $rule->code, 'version' => (int) $rule->version, 'rule_hash' => $rule->rule_hash],
                    'reasons' => $r['reasons'], 'facts' => $facts, 'party_id' => $txn['party_id'] ?? null, 'automated_outcome' => 'REVIEW_REQUIRED'];
                $alert = DB::transaction(function () use ($tenantId, $txn, $rule, $signals, $key) {
                    $a = RiskAlert::create([
                        'tenant_id' => $tenantId, 'subject_type' => $txn['subject_type'], 'subject_id' => $txn['subject_id'], 'alert_type' => self::ALERT_TYPE,
                        'risk_score' => (int) $rule->risk_points, 'severity' => self::severity((int) $rule->risk_points), 'status' => 'OPEN', 'signals' => $signals,
                        'fraud_rule_version_id' => $rule->id, 'idempotency_key' => $key, 'payload_hash' => hash('sha256', json_encode($signals, JSON_THROW_ON_ERROR)),
                    ]);
                    $this->audit->record('aml.transaction.alert_raised', 'risk_alert', $a->id, ['rule' => $rule->code.'@v'.$rule->version,
                        'subject_type' => $txn['subject_type'], 'subject_id' => $txn['subject_id']]);

                    return $a;
                });
            }
            $alerts[] = ['id' => $alert->id, 'rule' => $rule->code, 'version' => (int) $rule->version, 'severity' => $alert->severity,
                'status' => $alert->status, 'reasons' => $r['reasons']];
        }

        return ['status' => 'EVALUATED', 'facts' => $facts, 'alerts' => $alerts];
    }

    public static function severity(int $score): string
    {
        return match (true) {
            $score >= 80 => 'CRITICAL', $score >= 60 => 'HIGH', $score >= 30 => 'MEDIUM', default => 'LOW',
        };
    }
}
