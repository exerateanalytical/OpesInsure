<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Policies\Renewals\RenewalMachine;
use App\Application\Quotes\QuoteService;
use App\Models\Policy;
use App\Models\RenewalCase;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-REN-001 renewal machine (WF-039..043, WF-087) — the one renewal service.
 *
 *  - seed / sweep (POST renewals/seed; broker/renewals/seed is a deprecated alias, REQ-DUP-010): opens a renewal case
 *    per expiring policy, mirrors the broker work-list row (renewal_work_items), records the reminder window reached
 *    (90/60/30/15/7 — RenewalMachine::WINDOWS) once, and lapses cases never quoted once cover has ended;
 *  - createQuote: re-rates on the policy's CURRENT version (latest policy_versions risk facts, else the original
 *    quote facts) through the one rating path (QuoteService::rate → tariff version in force now);
 *  - complete: continuity link — the successor must point at the renewed policy (previous_policy_id);
 *  - paid renewal whose issuance failed: Renewals\RenewalIssuanceFailureLink (issuance_exceptions queue).
 */
final class RenewalService
{
    public function __construct(
        private QuoteService $quotes,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
        private RenewalMachine $machine,
    ) {}

    public function seed(Tenant $tenant, int $days, ?User $actor): int
    {
        return $this->sweep($tenant, $days, $actor)['cases'];
    }

    /** @return array{cases: int, work_items_created: int, windows_reached: int, lapsed: int} */
    public function sweep(Tenant $tenant, int $days, ?User $actor): array
    {
        $stats = ['cases' => 0, 'work_items_created' => 0, 'windows_reached' => 0, 'lapsed' => 0];
        Policy::where('tenant_id', $tenant->id)->whereIn('status', ['ACTIVE', 'EXPIRING'])
            ->whereBetween('coverage_ends_at', [now(), now()->addDays($days)])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('policies as successor')->whereColumn('successor.previous_policy_id', 'policies.id'))
            ->each(function (Policy $policy) use ($tenant, $actor, &$stats): void {
                $attributionId = $policy->proposal?->offer?->quote?->attribution_id;
                $dueOn = $policy->coverage_ends_at->toDateString();
                $case = RenewalCase::firstOrCreate(
                    ['policy_id' => $policy->id, 'due_on' => $dueOn],
                    [
                        'tenant_id' => $tenant->id,
                        'status' => 'DUE',
                        'attribution_snapshot' => $attributionId ? ['attribution_id' => $attributionId] : [],
                    ],
                );
                if ($case->wasRecentlyCreated) {
                    $case->refresh();
                    $this->machine->trail($case, 'OPENED', null, 'DUE', $actor);
                }
                $stats['cases']++;
                $stats['work_items_created'] += DB::table('renewal_work_items')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'renewal_due_on' => $dueOn,
                    'status' => 'DUE', 'contact_attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $stats['windows_reached'] += $this->advanceWindow($case, $actor) ? 1 : 0;
            });

        RenewalCase::where('tenant_id', $tenant->id)->whereIn('status', ['DUE', 'CONTACTED'])->where('due_on', '<', now()->toDateString())
            ->each(function (RenewalCase $case) use ($actor, &$stats): void {
                DB::transaction(function () use ($case, $actor, &$stats): void {
                    $case = RenewalCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
                    if (! in_array($case->status, ['DUE', 'CONTACTED'], true)) {
                        return;
                    }
                    $this->machine->move($case, 'LAPSED', 'LAPSED', $actor, [], ['closed_reason' => 'NOT_RENEWED_BY_EXPIRY']);
                    $this->outbox->record('renewal.lapsed', 'renewal_case', $case->id, ['renewal_case_id' => $case->id, 'policy_id' => $case->policy_id]);
                    $stats['lapsed']++;
                });
            });

        $this->audit->record('renewal.swept', 'tenant', $tenant->id, $stats + ['days' => $days]);

        return $stats;
    }

    /** Records the reminder window the case is now in (once per window). True when a new window was reached. */
    public function advanceWindow(RenewalCase $case, ?User $actor = null): bool
    {
        if (! in_array($case->status, RenewalMachine::OPEN, true)) {
            return false;
        }
        $daysLeft = (int) CarbonImmutable::now()->startOfDay()->diffInDays(CarbonImmutable::parse($case->due_on)->startOfDay(), false);
        $window = RenewalMachine::windowFor($daysLeft);
        if ($window === null || ($case->window_days !== null && $window >= (int) $case->window_days)) {
            return false;
        }
        if (! $this->machine->trail($case, 'WINDOW_REACHED', $case->status, $case->status, $actor, ['days_left' => $daysLeft], $window)) {
            return false;
        }
        $first = $case->window_days === null;
        $case->forceFill(['window_days' => $window])->save();
        $payload = ['renewal_case_id' => $case->id, 'policy_id' => $case->policy_id, 'window_days' => $window, 'days_left' => $daysLeft, 'due_on' => $case->due_on->toDateString()];
        if ($first) {
            $this->outbox->record('renewal.due', 'renewal_case', $case->id, $payload);
        }
        $this->outbox->record('renewal.window_reached', 'renewal_case', $case->id, $payload);

        return true;
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
            // Re-rate on the policy's current version: endorsed risk facts win over the original quote's.
            $version = DB::table('policy_versions')->where('policy_id', $policy->id)->whereNull('superseded_at')->orderByDesc('version_no')->first();
            $versionFacts = $version ? DB::table('policy_risks')->where('policy_version_id', $version->id)->orderBy('created_at')->value('facts') : null;
            $facts = $versionFacts ? (json_decode((string) $versionFacts, true) ?: []) : [];
            $facts = $facts !== [] ? $facts : ($oldQuote->risk_facts ?? []);

            $quote = $this->quotes->submit($policy->tenant, $policy->party_id, [
                'line_code' => $oldQuote->line_code,
                'risk_asset_id' => $oldQuote->risk_asset_id,
                'channel' => $oldQuote->channel,
                'risk_facts' => $facts,
            ], $actor);
            $quote->update(['attribution_id' => $oldQuote->attribution_id]);
            $quote = $this->quotes->rate($quote, $actor);
            $tariffs = DB::table('quote_offers')->where('quote_id', $quote->id)->pluck('tariff_version_id')->filter()->values()->all();

            $meta = ['quote_id' => $quote->id, 'policy_version_id' => $version?->id, 'tariff_version_ids' => $tariffs, 'quote_state' => $quote->lifecycle_state];
            $this->machine->move($case, 'QUOTED', 'QUOTED', $actor, $meta, [
                'renewal_quote_id' => $quote->id,
                'rated_policy_version_id' => $version?->id,
                'last_contacted_at' => now(),
                'contact_attempts' => $case->contact_attempts + 1,
            ]);

            $this->audit->record('renewal.quoted', 'renewal_case', $case->id, $meta);
            $this->outbox->record('renewal.quoted', 'renewal_case', $case->id, [
                'renewal_case_id' => $case->id,
                'quote_id' => $quote->id,
                'policy_version_id' => $version?->id,
                'tariff_version_ids' => $tariffs,
            ]);

            return $case->refresh();
        });
    }

    /**
     * S3 2026-09-29: (re)assign an open renewal case to a colleague, or unassign it (null) — POST renewals/{r}/assignment
     * and the BRK-066 / renewal-case web actions (renewals.manage). Own-book scoping: a book-scoped caller (BookScope)
     * may only touch cases whose policy belongs to a party of their book (else 404, no existence leak), and may only
     * hand the case to a colleague they can see (BookScope::users). The assignee must be an ACTIVE member of the case's
     * tenant who holds renewals.manage. Trail REASSIGNED + audit + outbox.
     */
    public function reassign(RenewalCase $case, ?string $assigneeId, User $actor, ?string $reason = null): RenewalCase
    {
        $book = app(\App\Application\Partners\BookScope::class);
        $parties = $book->parties($actor);
        if ($parties !== null && ! DB::table('policies')->where('id', $case->policy_id)->whereIn('party_id', $parties)->exists()) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(RenewalCase::class, [$case->id]);
        }
        if ($assigneeId !== null) {
            $member = DB::table('tenant_memberships')->where(['tenant_id' => $case->tenant_id, 'user_id' => $assigneeId, 'status' => 'ACTIVE'])->exists();
            $assignee = $member ? User::find($assigneeId) : null;
            $colleagues = $book->users($actor);
            if ($assignee === null || ! $assignee->hasPermission('renewals.manage')
                || ($colleagues !== null && ! DB::query()->fromSub($colleagues, 'c')->where('c.user_id', $assigneeId)->exists())) {
                throw ValidationException::withMessages(['assignee_id' => __('leftover_actions.renewal.assignee_invalid')]);
            }
        }

        return DB::transaction(function () use ($case, $assigneeId, $actor, $reason): RenewalCase {
            $case = RenewalCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! in_array($case->status, RenewalMachine::OPEN, true)) {
                throw ValidationException::withMessages(['status' => __('leftover_actions.renewal.not_open')]);
            }
            $from = $case->assigned_to;
            if ((string) $from === (string) $assigneeId) {
                throw ValidationException::withMessages(['assignee_id' => __('leftover_actions.renewal.unchanged')]);
            }
            $case->forceFill(['assigned_to' => $assigneeId])->save();
            $meta = ['from_user_id' => $from, 'to_user_id' => $assigneeId];
            $this->machine->trail($case, 'REASSIGNED', $case->status, $case->status, $actor, $meta + ['reason' => $reason]);
            $this->audit->record('renewal.reassigned', 'renewal_case', $case->id, $meta, $reason);
            $this->outbox->record('renewal.reassigned', 'renewal_case', $case->id, ['renewal_case_id' => $case->id] + $meta);

            return $case->refresh();
        });
    }

    /**
     * 2026-09-30 (broker / agent renewal desk): opens the renewal case of one policy of the caller's book on demand,
     * exactly as the sweep would (case DUE + OPENED trail + broker work-list row), when the sweep has not reached it
     * yet. The policy must be ACTIVE/EXPIRING and not already renewed.
     */
    public function openFor(Policy $policy, ?User $actor): RenewalCase
    {
        $existing = RenewalCase::where('policy_id', $policy->id)->orderByDesc('created_at')->first();
        if ($existing !== null && (in_array($existing->status, RenewalMachine::OPEN, true) || $existing->status === 'RENEWED')) {
            return $existing;
        }
        if (! in_array($policy->status, ['ACTIVE', 'EXPIRING'], true) || $policy->coverage_ends_at === null
            || Policy::where('previous_policy_id', $policy->id)->exists()) {
            throw ValidationException::withMessages(['policy' => __('wave5.renewal_not_due')]);
        }
        $dueOn = $policy->coverage_ends_at->toDateString();
        $case = RenewalCase::firstOrCreate(['policy_id' => $policy->id, 'due_on' => $dueOn], ['tenant_id' => $policy->tenant_id, 'status' => 'DUE', 'attribution_snapshot' => []]);
        if ($case->wasRecentlyCreated) {
            $case->refresh();
            $this->machine->trail($case, 'OPENED', null, 'DUE', $actor);
        }
        DB::table('renewal_work_items')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id, 'renewal_due_on' => $dueOn,
            'status' => 'DUE', 'contact_attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $case;
    }

    /**
     * 2026-09-30: the client's decision not to renew, recorded by the intermediary (DUE/CONTACTED/QUOTED → DECLINED).
     * Trail DECLINED + audit + outbox; the broker work-list row gets the outcome.
     */
    public function decline(RenewalCase $case, string $reason, User $actor): RenewalCase
    {
        return DB::transaction(function () use ($case, $reason, $actor): RenewalCase {
            $case = RenewalCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if (! in_array($case->status, ['DUE', 'CONTACTED', 'QUOTED'], true)) {
                throw ValidationException::withMessages(['status' => __('leftover_actions.renewal.not_open')]);
            }
            $this->machine->move($case, 'DECLINED', 'DECLINED', $actor, ['reason' => $reason], ['closed_reason' => 'CUSTOMER_DECLINED']);
            DB::table('renewal_work_items')->where(['policy_id' => $case->policy_id, 'renewal_due_on' => $case->due_on->toDateString()])->whereNull('outcome')
                ->update(['outcome' => 'DECLINED', 'status' => 'DECLINED', 'updated_at' => now()]);
            $this->audit->record('renewal.declined', 'renewal_case', $case->id, ['policy_id' => $case->policy_id], $reason);
            $this->outbox->record('renewal.declined', 'renewal_case', $case->id, ['renewal_case_id' => $case->id, 'policy_id' => $case->policy_id]);

            return $case->refresh();
        });
    }

    public function complete(RenewalCase $case, Policy $successor): RenewalCase
    {
        return DB::transaction(function () use ($case, $successor): RenewalCase {
            $case = RenewalCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $successor = Policy::whereKey($successor->id)->lockForUpdate()->firstOrFail();
            // Continuity link: the successor must continue the renewed policy (previous_policy_id).
            if (! in_array($case->status, ['QUOTED', 'ISSUANCE_FAILED'], true)
                || $successor->tenant_id !== $case->tenant_id
                || $successor->previous_policy_id !== $case->policy_id
                || $successor->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['successor_policy_id' => __('wave5.successor_invalid')]);
            }

            $this->machine->move($case, 'RENEWED', 'RENEWED', null, ['successor_policy_id' => $successor->id], ['successor_policy_id' => $successor->id]);
            DB::table('renewal_work_items')->where(['policy_id' => $case->policy_id, 'renewal_due_on' => $case->due_on->toDateString()])->whereNull('outcome')
                ->update(['outcome' => 'RENEWED', 'updated_at' => now()]);
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
