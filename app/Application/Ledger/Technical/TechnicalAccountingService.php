<?php

declare(strict_types=1);

namespace App\Application\Ledger\Technical;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Batch 10-9 — REQ-ACC-004 technical accounting (read-only against policies / claims).
 *
 * Premiums (per carrier / line / currency, period = [from, to] inclusive dates, tenant timezone = app timezone):
 *   - written  = premium_minor of policies whose written date (issued_at, else coverage_starts_at) falls in the period;
 *   - earned   = pro-rata daily: earnedCum(X) = premium × elapsedDays(X) / coverageDays (integer, cumulative so
 *                period values never drift); coverage = coverage_starts_at → coverage_ends_at, cut short by the
 *                current CANCELLATION policy_version (valid_from); nothing is earned before the policy is written;
 *   - UPR      = written-to-date − earned-to-date at period end (unearned premium). Identity:
 *                UPR(end) = UPR(start) + written − earned − cancelled_release (unearned part of cancelled cover).
 * Claims (grouped by the claim's policy carrier / line, claim currency):
 *   - paid        = claim_payments PAID (or later REVERSED, until reversed_at) by paid_at;
 *   - outstanding = latest APPROVED claim_reserve_changes amount (case estimate) − paid to date, floored at 0,
 *                   0 once the claim is closed;
 *   - incurred    = paid + Δoutstanding over the period.
 * IBNR and life actuarial values come ONLY from approved technical_actuarial_imports (never computed here).
 */
final class TechnicalAccountingService
{
    public const UNSPECIFIED_LINE = 'UNSPECIFIED';

    /** @return list<array<string,mixed>> */
    public function premiums(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, ?string $carrierId = null, ?string $line = null): array
    {
        [$start, $end] = $this->bounds($from, $to);
        $rows = [];
        foreach ($this->policies($tenantId, $carrierId, $line) as $p) {
            $key = $p->carrier_id.'|'.$p->line_code.'|'.$p->currency;
            $rows[$key] ??= ['carrier_id' => $p->carrier_id, 'line_code' => $p->line_code, 'currency' => $p->currency,
                'policies' => 0, 'written_minor' => 0, 'earned_minor' => 0, 'upr_opening_minor' => 0, 'upr_closing_minor' => 0, 'cancelled_release_minor' => 0];
            $written = $p->written_at >= $start && $p->written_at < $end ? (int) $p->premium_minor : 0;
            $earned = $this->earnedCum($p, $end) - $this->earnedCum($p, $start);
            $uprOpen = $this->uprAt($p, $start);
            $uprClose = $this->uprAt($p, $end);
            if ($written === 0 && $earned === 0 && $uprOpen === 0 && $uprClose === 0) {
                continue;
            }
            $r = &$rows[$key];
            $r['policies']++;
            $r['written_minor'] += $written;
            $r['earned_minor'] += $earned;
            $r['upr_opening_minor'] += $uprOpen;
            $r['upr_closing_minor'] += $uprClose;
            $r['cancelled_release_minor'] += $uprOpen + $written - $earned - $uprClose;
            unset($r);
        }

        return $this->finish(array_filter($rows, fn ($r) => $r['policies'] > 0), fn ($r) => $r + ['upr_movement_minor' => $r['upr_closing_minor'] - $r['upr_opening_minor']]);
    }

    /** @return list<array<string,mixed>> */
    public function claims(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, ?string $carrierId = null, ?string $line = null): array
    {
        [$start, $end] = $this->bounds($from, $to);
        $claims = DB::table('claims as c')->join('policies as p', 'p.id', '=', 'c.policy_id')
            ->where('c.tenant_id', $tenantId)
            ->when($carrierId, fn ($q, $v) => $q->where('p.carrier_id', $v))
            ->when($line, fn ($q, $v) => $this->lineFilter($q, $v))
            ->select('c.id', 'c.currency', 'c.closed_at', 'c.submitted_at', 'c.created_at', 'p.carrier_id', DB::raw($this->lineExpr().' as line_code'))
            ->get();
        if ($claims->isEmpty()) {
            return [];
        }
        $ids = $claims->pluck('id')->all();
        $payments = DB::table('claim_payments')->whereIn('claim_id', $ids)->whereIn('status', ['PAID', 'REVERSED'])->whereNotNull('paid_at')
            ->get(['claim_id', 'amount_minor', 'paid_at', 'reversed_at'])->groupBy('claim_id');
        $reserves = DB::table('claim_reserve_changes')->whereIn('claim_id', $ids)->where('status', 'APPROVED')->whereNotNull('approved_at')
            ->orderBy('approved_at')->orderBy('created_at')->get(['claim_id', 'requested_amount_minor', 'approved_at'])->groupBy('claim_id');

        $rows = [];
        foreach ($claims as $c) {
            $pay = $payments->get($c->id, collect());
            $res = $reserves->get($c->id, collect());
            $paidOpen = $this->paidAt($pay, $start);
            $paidClose = $this->paidAt($pay, $end);
            $osOpen = $this->outstandingAt($c, $res, $paidOpen, $start);
            $osClose = $this->outstandingAt($c, $res, $paidClose, $end);
            if ($paidOpen === $paidClose && $osOpen === 0 && $osClose === 0 && ! $this->reported($c, $start, $end)) {
                continue;
            }
            $key = $c->carrier_id.'|'.$c->line_code.'|'.$c->currency;
            $rows[$key] ??= ['carrier_id' => $c->carrier_id, 'line_code' => $c->line_code, 'currency' => $c->currency,
                'claims' => 0, 'reported' => 0, 'paid_minor' => 0, 'outstanding_opening_minor' => 0, 'outstanding_closing_minor' => 0, 'incurred_minor' => 0];
            $r = &$rows[$key];
            $r['claims']++;
            $r['reported'] += $this->reported($c, $start, $end) ? 1 : 0;
            $r['paid_minor'] += $paidClose - $paidOpen;
            $r['outstanding_opening_minor'] += $osOpen;
            $r['outstanding_closing_minor'] += $osClose;
            $r['incurred_minor'] += ($paidClose - $paidOpen) + ($osClose - $osOpen);
            unset($r);
        }

        return $this->finish($rows, fn ($r) => $r);
    }

    /**
     * Combined per carrier / line / currency view: premiums + claims + approved actuarial values at period end.
     *
     * @return list<array<string,mixed>>
     */
    public function summary(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, ?string $carrierId = null, ?string $line = null): array
    {
        $zeroP = ['policies' => 0, 'written_minor' => 0, 'earned_minor' => 0, 'upr_opening_minor' => 0, 'upr_closing_minor' => 0, 'cancelled_release_minor' => 0, 'upr_movement_minor' => 0];
        $zeroC = ['claims' => 0, 'reported' => 0, 'paid_minor' => 0, 'outstanding_opening_minor' => 0, 'outstanding_closing_minor' => 0, 'incurred_minor' => 0];
        $out = [];
        foreach ($this->premiums($tenantId, $from, $to, $carrierId, $line) as $r) {
            $out[$r['carrier_id'].'|'.$r['line_code'].'|'.$r['currency']] = $r + $zeroC + ['actuarial' => []];
        }
        foreach ($this->claims($tenantId, $from, $to, $carrierId, $line) as $r) {
            $k = $r['carrier_id'].'|'.$r['line_code'].'|'.$r['currency'];
            $out[$k] = array_merge($out[$k] ?? (['carrier_id' => $r['carrier_id'], 'line_code' => $r['line_code'], 'currency' => $r['currency']] + $zeroP + ['actuarial' => []]), $r);
        }
        foreach ($this->actuarialValues($tenantId, $to, $carrierId, $line) as $v) {
            $lineCode = $v->line_code ?? self::UNSPECIFIED_LINE;
            $k = $v->carrier_id.'|'.$lineCode.'|'.$v->currency;
            $out[$k] ??= ['carrier_id' => $v->carrier_id, 'line_code' => $lineCode, 'currency' => $v->currency] + $zeroP + $zeroC + ['actuarial' => []];
            $out[$k]['actuarial'][$v->metric] = ($out[$k]['actuarial'][$v->metric] ?? 0) + (int) $v->amount_minor;
        }
        foreach ($out as &$r) {
            $r['ibnr_minor'] = $r['actuarial']['IBNR'] ?? null;
            $r['loss_ratio_bp'] = $r['earned_minor'] > 0 ? intdiv(($r['incurred_minor'] + (int) ($r['ibnr_minor'] ?? 0)) * 10000, $r['earned_minor']) : null;
        }
        unset($r);

        return $this->finish($out, fn ($r) => $r);
    }

    /** Total UPR at the end of $date per currency (for period-end posting). @return array<string,int> */
    public function uprTotals(string $tenantId, CarbonImmutable $date): array
    {
        $end = $date->startOfDay()->addDay();
        $totals = [];
        foreach ($this->policies($tenantId, null, null) as $p) {
            $totals[$p->currency] = ($totals[$p->currency] ?? 0) + $this->uprAt($p, $end);
        }
        ksort($totals);

        return $totals;
    }

    /** Values of the approved (current) import per kind for the period end ≤ $to (latest period end). */
    public function actuarialValues(string $tenantId, CarbonImmutable $to, ?string $carrierId = null, ?string $line = null): \Illuminate\Support\Collection
    {
        $imports = DB::table('technical_actuarial_imports')->where('tenant_id', $tenantId)->where('status', 'APPROVED')
            ->where('period_end', '<=', $to->toDateString())->orderByDesc('period_end')->get()->unique('kind')->pluck('id');

        return DB::table('technical_actuarial_values as v')->join('technical_actuarial_imports as i', 'i.id', '=', 'v.import_id')
            ->whereIn('v.import_id', $imports)
            ->when($carrierId, fn ($q, $x) => $q->where('v.carrier_id', $x))
            ->when($line, fn ($q, $x) => $x === self::UNSPECIFIED_LINE ? $q->whereNull('v.line_code') : $q->where('v.line_code', $x))
            ->orderBy('v.metric')->get(['v.carrier_id', 'v.line_code', 'v.metric', 'v.amount_minor', 'v.currency', 'i.kind', 'i.version', 'i.period_end']);
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function bounds(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->startOfDay(), $to->startOfDay()->addDay()];
    }

    private function policies(string $tenantId, ?string $carrierId, ?string $line): \Illuminate\Support\Collection
    {
        $cancellations = DB::table('policy_versions')->where('kind', 'CANCELLATION')->whereNull('superseded_at')
            ->groupBy('policy_id')->select('policy_id', DB::raw('MIN(valid_from) as cancelled_from'));

        return DB::table('policies as p')->leftJoinSub($cancellations, 'cx', 'cx.policy_id', '=', 'p.id')
            ->where('p.tenant_id', $tenantId)->whereNotNull('p.issued_at')->where('p.premium_minor', '>', 0)
            ->when($carrierId, fn ($q, $v) => $q->where('p.carrier_id', $v))
            ->when($line, fn ($q, $v) => $this->lineFilter($q, $v))
            ->orderBy('p.id')
            ->get(['p.id', 'p.carrier_id', 'p.currency', 'p.premium_minor', 'p.issued_at', 'p.coverage_starts_at', 'p.coverage_ends_at', 'cx.cancelled_from', DB::raw($this->lineExpr().' as line_code')])
            ->map(function ($p) {
                $p->starts = CarbonImmutable::parse($p->coverage_starts_at)->startOfDay();
                $p->ends = max($p->starts->addDay(), CarbonImmutable::parse($p->coverage_ends_at)->startOfDay());
                $p->days = (int) $p->starts->diffInDays($p->ends);
                $p->earn_ends = $p->cancelled_from ? max($p->starts, min($p->ends, CarbonImmutable::parse($p->cancelled_from)->startOfDay())) : $p->ends;
                $p->written_at = CarbonImmutable::parse($p->issued_at ?? $p->coverage_starts_at);

                return $p;
            });
    }

    /** Premium earned strictly before instant $x. Cancelled cover stops earning; the unearned rest is released (not earned). */
    private function earnedCum(object $p, CarbonImmutable $x): int
    {
        if ($p->written_at >= $x) {
            return 0;
        }
        $upTo = min(max($x, $p->starts), $p->earn_ends);

        return intdiv((int) $p->premium_minor * (int) $p->starts->diffInDays($upTo), $p->days);
    }

    private function uprAt(object $p, CarbonImmutable $x): int
    {
        if ($p->written_at >= $x || ($p->cancelled_from && $p->earn_ends < $x)) {
            return 0;
        }

        return (int) $p->premium_minor - $this->earnedCum($p, $x);
    }

    private function paidAt(\Illuminate\Support\Collection $payments, CarbonImmutable $x): int
    {
        return (int) $payments->filter(fn ($p) => CarbonImmutable::parse($p->paid_at) < $x && ($p->reversed_at === null || CarbonImmutable::parse($p->reversed_at) >= $x))->sum('amount_minor');
    }

    private function outstandingAt(object $c, \Illuminate\Support\Collection $reserves, int $paid, CarbonImmutable $x): int
    {
        if ($c->closed_at !== null && CarbonImmutable::parse($c->closed_at) < $x) {
            return 0;
        }
        $reserve = $reserves->filter(fn ($r) => CarbonImmutable::parse($r->approved_at) < $x)->last();

        return max(0, (int) ($reserve->requested_amount_minor ?? 0) - $paid);
    }

    private function reported(object $c, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $at = CarbonImmutable::parse($c->submitted_at ?? $c->created_at);

        return $at >= $start && $at < $end;
    }

    private function lineExpr(): string
    {
        return "COALESCE(NULLIF(p.terms_snapshot->>'line_code', ''), '".self::UNSPECIFIED_LINE."')";
    }

    private function lineFilter($q, string $line)
    {
        return $q->whereRaw($this->lineExpr().' = ?', [$line]);
    }

    /** @return list<array<string,mixed>> */
    private function finish(array $rows, callable $map): array
    {
        ksort($rows);

        return array_values(array_map($map, $rows));
    }
}
