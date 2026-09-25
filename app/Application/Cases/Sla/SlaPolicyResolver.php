<?php

declare(strict_types=1);

namespace App\Application\Cases\Sla;

use App\Application\Cases\Models\CaseType;
use App\Application\Cases\Models\WorkCase;
use App\Domain\Shared\Clock\Clock;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CAS-001 / REQ-QUO-006 / REQ-CPL-001 — which SLA target applies to a case (owner decisions #32, complaints).
 *
 * Sources, most specific wins per metric:
 *  1. the pinned case type version's sla_policies (entries with case_subtypes beat the plain entry);
 *  2. ACTIVE sla_policy_overrides for the case type, scoped by any of tenant, insurer (carrier_id), product,
 *     branch, market (jurisdiction) and case_subtype. A NULL scope column matches everything; every
 *     matching non-NULL column adds specificity. Overrides always beat the type defaults.
 *
 * Every resolved policy carries its label (PLATFORM_SLA unless a legal basis makes it REGULATORY_DEADLINE)
 * and its source, which the clock stores.
 */
final class SlaPolicyResolver
{
    private const SCOPES = ['tenant_id' => 'tenant_id', 'carrier_id' => 'carrier_id', 'product_id' => 'product_id', 'branch_id' => 'branch_id', 'market' => 'jurisdiction', 'case_subtype' => 'case_subtype'];

    public function __construct(private readonly Clock $clock) {}

    /** @return array<string, array<string, mixed>> metric => policy */
    public function resolve(WorkCase $case, CaseType $type): array
    {
        $best = [];
        foreach ($type->sla_policies ?? [] as $p) {
            $subtypes = $p['case_subtypes'] ?? null;
            if ($subtypes !== null && ! in_array($case->case_subtype, (array) $subtypes, true)) {
                continue;
            }
            $score = $subtypes !== null ? 1 : 0;
            $metric = (string) $p['metric'];
            if (! isset($best[$metric]) || $score > $best[$metric]['score']) {
                $best[$metric] = ['score' => $score, 'policy' => $p + ['label' => 'PLATFORM_SLA', 'source' => "CASE_TYPE:{$type->code}:v{$type->version}"]];
            }
        }

        $today = $this->clock->today()->toDateString();
        $q = DB::table('sla_policy_overrides')->where('case_type_code', $type->code)->where('status', 'ACTIVE')
            ->whereDate('effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today));
        foreach (self::SCOPES as $column => $attr) {
            $value = $case->{$attr};
            $q->where(fn ($q) => $value === null ? $q->whereNull($column) : $q->whereNull($column)->orWhere($column, $value));
        }
        foreach ($q->orderBy('created_at')->get() as $o) {
            $score = 10;
            foreach (array_keys(self::SCOPES) as $column) {
                $score += $o->{$column} !== null ? 1 : 0;
            }
            if (isset($best[$o->metric]) && $score < $best[$o->metric]['score']) {
                continue;
            }
            $best[$o->metric] = ['score' => $score, 'policy' => array_filter([
                'metric' => $o->metric, 'target_business_minutes' => $o->target_business_minutes, 'target_business_days' => $o->target_business_days,
                'warn_at_pct' => (int) $o->warn_at_pct, 'escalate_to' => $o->escalate_to,
            ], fn ($v) => $v !== null) + ['label' => $o->deadline_label, 'legal_basis' => $o->legal_basis, 'source' => 'OVERRIDE:'.$o->id]];
        }

        return array_map(fn ($b) => $b['policy'], $best);
    }

    /** @return array<string, mixed>|null */
    public function forMetric(WorkCase $case, CaseType $type, string $metric): ?array
    {
        return $this->resolve($case, $type)[$metric] ?? null;
    }
}
