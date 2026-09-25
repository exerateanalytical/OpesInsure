<?php

declare(strict_types=1);

namespace App\Application\Finance\PremiumStatus;

use App\Models\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PAY-005 — premium status, derived (never stored) from premium components and
 * payment allocations. Deliberately separate from payment status: a SUCCEEDED payment
 * that is not allocated leaves the premium DUE; a refund reversal makes it REFUNDED.
 */
final class PremiumStatusReadModel
{
    public const STATUSES = ['NOT_DUE', 'DUE', 'PARTIALLY_PAID', 'PAID', 'OVERPAID', 'OVERDUE', 'CANCELLED', 'REFUNDED', 'PARTIALLY_REFUNDED', 'WRITTEN_OFF'];

    /** @return array<string, mixed> */
    public function forPolicy(Policy $policy, ?CarbonImmutable $at = null): array
    {
        $now = $at ?? CarbonImmutable::now();
        $components = DB::table('premium_components')->where('policy_id', $policy->id)->orderBy('due_at')->orderBy('line_key')->get();
        $moves = DB::table('payment_allocations')->whereIn('premium_component_id', $components->pluck('id'))
            ->selectRaw("premium_component_id, SUM(amount_minor) AS paid, SUM(CASE WHEN kind = 'REVERSAL' AND reason_code = 'REFUND' THEN -amount_minor ELSE 0 END) AS refunded")
            ->groupBy('premium_component_id')->get()->keyBy('premium_component_id');

        $lines = [];
        $t = ['due' => 0, 'paid' => 0, 'refunded' => 0, 'open' => 0, 'written_off' => 0];
        $overdue = false;
        $earliestOpenDue = null;
        $allCancelled = true;
        $anyPayable = false;
        foreach ($components as $c) {
            $paid = (int) ($moves[$c->id]->paid ?? 0);
            $refunded = (int) ($moves[$c->id]->refunded ?? 0);
            $amount = (int) $c->amount_minor;
            $line = ['id' => $c->id, 'line_key' => $c->line_key, 'component' => $c->component, 'payable' => (bool) $c->payable, 'amount_minor' => $amount,
                'currency' => $c->currency, 'due_at' => $c->due_at, 'financial_obligation_id' => $c->financial_obligation_id, 'closure' => $c->closure];
            if (! $c->payable) {
                $lines[] = $line;

                continue;
            }
            $anyPayable = true;
            $outstanding = $c->closure === 'CANCELLED' ? 0 : max(0, $amount - $paid);
            $line += ['paid_minor' => $paid, 'refunded_minor' => $refunded, 'outstanding_minor' => $outstanding,
                'status' => self::derive($c->closure === 'CANCELLED' ? 0 : $amount, $paid, $refunded, $c->closure, $c->due_at, $now)];
            $lines[] = $line;
            $t['paid'] += $paid;
            $t['refunded'] += $refunded;
            if ($c->closure === 'CANCELLED') {
                continue;
            }
            $allCancelled = false;
            $t['due'] += $amount;
            if ($c->closure === 'WRITTEN_OFF') {
                $t['written_off'] += $outstanding;

                continue;
            }
            $t['open'] += $outstanding;
            if ($outstanding > 0) {
                $overdue = $overdue || ($c->due_at !== null && CarbonImmutable::parse($c->due_at)->lt($now));
                $due = $c->due_at === null ? $now : CarbonImmutable::parse($c->due_at);
                $earliestOpenDue = $earliestOpenDue === null || $due->lt($earliestOpenDue) ? $due : $earliestOpenDue;
            }
        }

        $status = match (true) {
            ! $anyPayable => null,
            $allCancelled => self::derive(0, $t['paid'], $t['refunded'], 'CANCELLED', null, $now),
            $t['refunded'] > 0 => $t['paid'] > 0 ? 'PARTIALLY_REFUNDED' : 'REFUNDED',
            $t['paid'] > $t['due'] => 'OVERPAID',
            $t['open'] === 0 && $t['written_off'] === 0 => 'PAID',
            $t['open'] === 0 => 'WRITTEN_OFF',
            $overdue => 'OVERDUE',
            $t['paid'] === 0 && $earliestOpenDue !== null && $earliestOpenDue->gt($now) => 'NOT_DUE',
            $t['paid'] > 0 => 'PARTIALLY_PAID',
            default => 'DUE',
        };

        return ['policy_id' => $policy->id, 'currency' => $policy->currency, 'premium_status' => $status,
            'due_minor' => $t['due'], 'paid_minor' => $t['paid'], 'refunded_minor' => $t['refunded'], 'outstanding_minor' => $t['open'],
            'written_off_minor' => $t['written_off'], 'as_of' => $now->toIso8601String(), 'components' => $lines];
    }

    public static function derive(int $due, int $paid, int $refunded, ?string $closure, ?string $dueAt, CarbonImmutable $now): string
    {
        return match (true) {
            $refunded > 0 => $paid > 0 ? 'PARTIALLY_REFUNDED' : 'REFUNDED',
            $closure === 'CANCELLED' => $paid > 0 ? 'OVERPAID' : 'CANCELLED',
            $paid > $due => 'OVERPAID',
            $paid === $due => 'PAID',
            $closure === 'WRITTEN_OFF' => 'WRITTEN_OFF',
            $dueAt !== null && CarbonImmutable::parse($dueAt)->lt($now) => 'OVERDUE',
            $paid > 0 => 'PARTIALLY_PAID',
            $dueAt !== null && CarbonImmutable::parse($dueAt)->gt($now) => 'NOT_DUE',
            default => 'DUE',
        };
    }
}
