<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Policies\PolicyStateMachine;
use App\Models\Policy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Policy term transitions (A8/B14), each recorded in policy_status_history:
 *  - ACTIVE   -> EXPIRING  when coverage ends within lifecycle.expiring_window_days (30)
 *  - ACTIVE/EXPIRING -> EXPIRED once coverage_ends_at has passed
 *  - EXPIRED  -> LAPSED    after lifecycle.grace_period_days without a renewal
 * A renewed policy (a successor points at it) is expired but never lapses.
 */
final class ExpirePolicies extends Command
{
    protected $signature = 'policies:expire';

    protected $description = 'Move policies through EXPIRING, EXPIRED and LAPSED as their term ends.';

    public function handle(PolicyStateMachine $machine): int
    {
        $window = (int) config('lifecycle.expiring_window_days', 30);
        $grace = (int) config('lifecycle.grace_period_days', 15);

        $expired = $this->move(Policy::whereIn('status', ['ACTIVE', 'EXPIRING'])->where('coverage_ends_at', '<=', now()), 'EXPIRED', 'TERM_ENDED', $machine);
        $expiring = $this->move(Policy::where('status', 'ACTIVE')->where('coverage_ends_at', '>', now())->where('coverage_ends_at', '<=', now()->addDays($window)), 'EXPIRING', 'TERM_ENDING', $machine);
        $lapsed = $this->move(Policy::where('status', 'EXPIRED')->where('coverage_ends_at', '<=', now()->subDays($grace))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('policies as successor')->whereColumn('successor.previous_policy_id', 'policies.id')), 'LAPSED', 'GRACE_PERIOD_ENDED', $machine);

        $this->info("Expiring: {$expiring}. Expired: {$expired}. Lapsed: {$lapsed}.");

        return self::SUCCESS;
    }

    private function move($query, string $to, string $reason, PolicyStateMachine $machine): int
    {
        $count = 0;
        $query->orderBy('coverage_ends_at')->each(function (Policy $policy) use ($to, $reason, $machine, &$count): void {
            DB::transaction(function () use ($policy, $to, $reason, $machine, &$count): void {
                $locked = Policy::whereKey($policy->id)->lockForUpdate()->first();
                $from = $locked->status;
                if ($from === $to) {
                    return;
                }
                // ACTIVE -> EXPIRED passes through EXPIRING in the state machine.
                $path = $from === 'ACTIVE' && $to === 'EXPIRED' ? ['EXPIRING', 'EXPIRED'] : [$to];
                foreach ($path as $next) {
                    $machine->assert($from, $next);
                    DB::table('policy_status_history')->insert([
                        'id' => (string) Str::uuid(), 'policy_id' => $locked->id, 'from_status' => $from, 'to_status' => $next,
                        'reason_code' => $reason, 'actor_id' => null, 'metadata' => json_encode(['source' => 'policies:expire']), 'occurred_at' => now(),
                    ]);
                    $from = $next;
                }
                $locked->update(['status' => $to]);
                $count++;
            });
        });

        return $count;
    }
}
