<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Notifications\CustomerNotifier;
use App\Models\Policy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Renewal reminders (A8/B14): 30/14/7/1 days before coverage ends, to the
 * inbox + push, and SMS when an SMS provider is configured. Each
 * (policy, offset) is sent once — policy_expiry_reminders makes daily runs
 * and re-runs idempotent. A policy already renewed (a successor points at
 * it) is skipped.
 */
final class NotifyPolicyExpiry extends Command
{
    protected $signature = 'policies:notify-expiry {--dry-run : Count what would be sent without sending}';

    protected $description = 'Send renewal reminders 30/14/7/1 days before policies expire.';

    public function handle(CustomerNotifier $notifier): int
    {
        $sent = 0;
        $offsets = collect(config('lifecycle.expiry_reminder_days', [30, 14, 7, 1]))->map(fn ($d) => (int) $d)->sortDesc()->values();

        foreach ($offsets as $days) {
            $from = now()->startOfDay()->addDays($days);
            $to = $from->copy()->endOfDay();

            Policy::with(['proposal.offer.product'])
                ->whereIn('status', ['ACTIVE', 'EXPIRING'])
                ->whereBetween('coverage_ends_at', [$from, $to])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('policies as successor')->whereColumn('successor.previous_policy_id', 'policies.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('policy_expiry_reminders as r')->whereColumn('r.policy_id', 'policies.id')->where('r.days_before', $days))
                ->orderBy('coverage_ends_at')
                ->each(function (Policy $policy) use ($days, $notifier, &$sent): void {
                    if ($this->option('dry-run')) {
                        $sent++;

                        return;
                    }
                    $inserted = DB::table('policy_expiry_reminders')->insertOrIgnore([
                        'id' => (string) Str::uuid(), 'policy_id' => $policy->id, 'days_before' => $days, 'sent_at' => now(),
                    ]);
                    if (! $inserted) {
                        return; // another run got here first
                    }
                    $product = $policy->proposal?->offer?->product?->name ?? 'Your policy';
                    $when = $days === 1 ? 'tomorrow' : "in {$days} days";
                    $notifier->toParty($policy->party_id, $policy->tenant_id, 'RENEWAL', $days === 1 ? 'Your cover ends tomorrow' : "Your cover ends {$when}",
                        "{$product} policy {$policy->policy_number} expires {$when} (".$policy->coverage_ends_at->format('d M Y').'). Renew now to stay covered.',
                        $days <= 7 ? 'WARNING' : 'INFO', "/policy/{$policy->id}", true);
                    $sent++;
                });
        }

        $this->info(($this->option('dry-run') ? 'Would send' : 'Sent')." {$sent} renewal reminder(s).");

        return self::SUCCESS;
    }
}
