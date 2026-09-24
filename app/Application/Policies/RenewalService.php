<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Quotes\QuoteService;
use App\Models\Policy;
use App\Models\RenewalCase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RenewalService
{
    public function __construct(
        private QuoteService $quotes,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
    ) {}

    public function seed(Tenant $tenant, int $days, ?User $actor): int
    {
        $count = 0;
        Policy::where('tenant_id', $tenant->id)->whereIn('status', ['ACTIVE', 'EXPIRING'])
            ->whereBetween('coverage_ends_at', [now(), now()->addDays($days)])
            ->each(function (Policy $policy) use ($tenant, &$count): void {
                $attributionId = $policy->proposal->offer->quote->attribution_id;
                RenewalCase::firstOrCreate(
                    ['policy_id' => $policy->id, 'due_on' => $policy->coverage_ends_at->toDateString()],
                    [
                        'tenant_id' => $tenant->id,
                        'status' => 'DUE',
                        'attribution_snapshot' => $attributionId ? ['attribution_id' => $attributionId] : [],
                    ],
                );
                $count++;
            });

        return $count;
    }

    public function createQuote(RenewalCase $case, User $actor): RenewalCase
    {
        return DB::transaction(function () use ($case, $actor): RenewalCase {
            $case = RenewalCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! in_array($case->status, ['DUE', 'CONTACTED'], true)) {
                throw ValidationException::withMessages(['status' => __('wave5.renewal_not_due')]);
            }

            $policy = $case->policy;
            $oldQuote = $policy->proposal->offer->quote;
            $quote = $this->quotes->submit($policy->tenant, $policy->party_id, [
                'line_code' => $oldQuote->line_code,
                'risk_asset_id' => $oldQuote->risk_asset_id,
                'channel' => $oldQuote->channel,
                'risk_facts' => $oldQuote->risk_facts,
            ], $actor);
            $quote->update(['attribution_id' => $oldQuote->attribution_id]);
            $case->update([
                'renewal_quote_id' => $quote->id,
                'status' => 'QUOTED',
                'last_contacted_at' => now(),
                'contact_attempts' => $case->contact_attempts + 1,
            ]);

            $this->audit->record('renewal.quoted', 'renewal_case', $case->id, ['quote_id' => $quote->id]);
            $this->outbox->record('renewal.quoted', 'renewal_case', $case->id, [
                'renewal_case_id' => $case->id,
                'quote_id' => $quote->id,
            ]);

            return $case->refresh();
        });
    }

    public function complete(RenewalCase $case, Policy $successor): RenewalCase
    {
        return DB::transaction(function () use ($case, $successor): RenewalCase {
            $case = RenewalCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $successor = Policy::whereKey($successor->id)->lockForUpdate()->firstOrFail();
            if ($case->status !== 'QUOTED'
                || $successor->tenant_id !== $case->tenant_id
                || $successor->previous_policy_id !== $case->policy_id
                || $successor->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['successor_policy_id' => __('wave5.successor_invalid')]);
            }

            $case->update(['successor_policy_id' => $successor->id, 'status' => 'RENEWED']);
            $this->audit->record('renewal.completed', 'renewal_case', $case->id, [
                'successor_policy_id' => $successor->id,
            ]);
            $this->outbox->record('renewal.completed', 'renewal_case', $case->id, [
                'renewal_case_id' => $case->id,
                'successor_policy_id' => $successor->id,
            ]);

            return $case->refresh();
        });
    }
}
