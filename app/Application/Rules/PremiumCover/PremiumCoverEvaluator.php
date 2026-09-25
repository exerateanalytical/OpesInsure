<?php

declare(strict_types=1);

namespace App\Application\Rules\PremiumCover;

use App\Domain\Rules\Expression\ExpressionEvaluator;
use Illuminate\Support\Facades\DB;

/**
 * REQ-POL-008 — owner decision #17: premium-to-cover is a configurable rule engine, not a Boolean.
 *
 * premium_cover_rules are scoped by insurer, product, class, jurisdiction, premium status and effective dates.
 * Each rule's activation_rule is a Rules-engine expression (App\Domain\Rules\Expression — the one evaluator,
 * no parallel one) over the supplied facts. Order: exceptions first, then specificity
 * (product > class > insurer > jurisdiction), then priority. The first rule whose activation rule is TRUE
 * decides the outcome; an UNKNOWN activation rule stops evaluation with MORE_INFORMATION_REQUIRED.
 *
 * No rule → UNDETERMINED (cover_active = null). Nothing is assumed: the legal basis is owner input (OQ-2.4).
 */
final class PremiumCoverEvaluator
{
    public const OUTCOMES = ['COVER_ACTIVE', 'NO_COVER', 'COVER_SUSPENDED', 'GRACE'];

    public const PREMIUM_STATUSES = ['UNPAID', 'PARTIALLY_PAID', 'PAID', 'OVERDUE', 'WAIVED', 'REFUNDED'];

    public function __construct(private readonly ExpressionEvaluator $expressions) {}

    /**
     * @param  array{product_id?: ?string, carrier_id?: ?string, class_code?: ?string, jurisdiction?: ?string,
     *   premium_status: string, effective_date: string, facts?: array<string, mixed>}  $in
     * @return array<string, mixed>
     */
    public function evaluate(array $in): array
    {
        $date = (string) $in['effective_date'];
        $facts = ($in['facts'] ?? []) + ['premium' => ['status' => $in['premium_status']], 'context' => ['today' => $date]];
        $facts['premium'] = ($facts['premium'] ?? []) + ['status' => $in['premium_status']];
        $facts['context'] = ($facts['context'] ?? []) + ['today' => $date];

        $rules = DB::table('premium_cover_rules')->where('status', 'ACTIVE')
            ->whereDate('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->get()
            ->filter(fn ($r) => $this->scopeMatches($r, $in))
            ->sortBy(fn ($r) => [$r->is_exception ? 0 : 1, -$this->specificity($r), (int) $r->priority, (string) $r->code])
            ->values();

        $trace = [];
        foreach ($rules as $r) {
            $eval = $this->expressions->evaluate(json_decode((string) $r->activation_rule, true), $facts);
            $trace[] = ['rule_id' => $r->id, 'code' => $r->code, 'result' => $eval['result'], 'missing' => $eval['missing']];
            if ($eval['result'] === null) {
                return $this->result('MORE_INFORMATION_REQUIRED', null, $r, $trace, $eval['missing']);
            }
            if ($eval['result'] === true) {
                return $this->result($r->outcome, $r->outcome === 'COVER_ACTIVE' || $r->outcome === 'GRACE', $r, $trace);
            }
        }

        return $this->result('UNDETERMINED', null, null, $trace);
    }

    private function scopeMatches(object $r, array $in): bool
    {
        foreach (['product_id', 'carrier_id', 'class_code', 'jurisdiction'] as $k) {
            if ($r->{$k} !== null && $r->{$k} !== ($in[$k] ?? null)) {
                return false;
            }
        }
        $statuses = json_decode((string) $r->premium_statuses, true) ?: [];

        return $statuses === [] || in_array($in['premium_status'], $statuses, true);
    }

    private function specificity(object $r): int
    {
        return ($r->product_id !== null ? 8 : 0) + ($r->class_code !== null ? 4 : 0) + ($r->carrier_id !== null ? 2 : 0) + ($r->jurisdiction !== null ? 1 : 0);
    }

    /** @param list<array<string, mixed>> $trace @param list<string> $missing */
    private function result(string $outcome, ?bool $active, ?object $rule, array $trace, array $missing = []): array
    {
        return [
            'outcome' => $outcome, 'cover_active' => $active,
            'rule' => $rule ? ['id' => $rule->id, 'code' => $rule->code, 'is_exception' => (bool) $rule->is_exception, 'grace_days' => $rule->grace_days, 'lapse_after_days' => $rule->lapse_after_days ?? null,
                'legal_basis' => $rule->legal_basis, 'verification_status' => $rule->verification_status] : null,
            'missing_facts' => $missing, 'trace' => $trace,
        ];
    }
}
