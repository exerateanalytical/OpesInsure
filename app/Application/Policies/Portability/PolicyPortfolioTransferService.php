<?php

declare(strict_types=1);

namespace App\Application\Policies\Portability;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-POL-009 / ICE gap 36 — policy portfolio transfer (servicing moves).
 *
 * Extends the CRM portfolio transfer (REQ-CRM-003, customer attribution) to
 * the policies themselves:
 *  - INTERMEDIARY scope: in-force policies serviced by one agent/broker move
 *    to another (policies.servicing_partner_id). Current servicer = explicit
 *    servicing_partner_id, else the customer's ACTIVE attribution partner.
 *  - CARRIER scope: run-off / portfolio cession between insurers
 *    (policies.servicing_carrier_id). The issuing carrier_id, policy number
 *    and commission attribution are never rewritten.
 *
 * Maker-checker: request() freezes the selection behind a preview_hash
 * (PENDING_APPROVAL); approve() by a different user recomputes it and refuses
 * on drift. NOTICE mode applies every item at approval and records the notice;
 * CONSENT mode applies an item only once the policyholder's consent is
 * recorded (refusal excludes it). Every move is audited, written to the
 * append-only policy_servicing_events and emitted on the outbox.
 */
final class PolicyPortfolioTransferService
{
    public const SCOPES = ['INTERMEDIARY', 'CARRIER'];

    public const REASONS = ['PARTNER_EXIT', 'LICENCE_WITHDRAWN', 'MERGER', 'CARRIER_RUN_OFF', 'PORTFOLIO_CESSION', 'REORGANISATION', 'CUSTOMER_REQUEST', 'OTHER'];

    public const ELIGIBLE_STATUSES = ['ACTIVE', 'ISSUED', 'SUSPENDED'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /**
     * @param  list<string>|null  $policyIds
     * @return array{scope: string, from_id: string, to_id: string, policies: list<array>, excluded: list<array>, policy_count: int, preview_hash: string}
     */
    public function preview(string $tenantId, string $scope, string $fromId, string $toId, ?array $policyIds = null): array
    {
        $this->assertEnds($tenantId, $scope, $fromId, $toId);
        $rows = $this->currentBook($tenantId, $scope, $fromId, $policyIds);
        $pending = DB::table('policy_portfolio_transfer_items as i')->join('policy_portfolio_transfers as t', 't.id', '=', 'i.transfer_id')
            ->whereIn('i.policy_id', $rows->pluck('id'))->whereIn('i.status', ['PENDING', 'AWAITING_CONSENT'])
            ->whereIn('t.status', ['PENDING_APPROVAL', 'AWAITING_CONSENT'])->pluck('t.id', 'i.policy_id');

        $moving = $rows->reject(fn ($r) => isset($pending[$r->id]))->values();
        $ids = $moving->pluck('id')->sort()->values()->all();

        return [
            'scope' => $scope, 'from_id' => $fromId, 'to_id' => $toId,
            'policies' => $moving->map(fn ($r) => ['policy_id' => $r->id, 'policy_number' => $r->policy_number, 'party_id' => $r->party_id, 'status' => $r->status])->all(),
            'excluded' => $rows->filter(fn ($r) => isset($pending[$r->id]))->map(fn ($r) => ['policy_id' => $r->id, 'reason' => 'IN_PENDING_TRANSFER', 'transfer_id' => $pending[$r->id]])->values()->all(),
            'policy_count' => count($ids),
            'preview_hash' => hash('sha256', $scope.'|'.$fromId.'|'.$toId.'|'.implode(',', $ids)),
        ];
    }

    /** Maker: freeze the previewed selection for approval. */
    public function request(string $tenantId, array $d, User $maker): object
    {
        return DB::transaction(function () use ($tenantId, $d, $maker) {
            $p = $this->preview($tenantId, $d['scope'], $d['from_id'], $d['to_id'], $d['policy_ids'] ?? null);
            $this->assertHash($p, $d['preview_hash']);
            if ($p['policy_count'] === 0) {
                throw ValidationException::withMessages(['from_id' => ['There are no policies to transfer.']]);
            }
            $id = (string) Str::uuid();
            $now = now();
            DB::table('policy_portfolio_transfers')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'scope' => $d['scope'], 'from_id' => $d['from_id'], 'to_id' => $d['to_id'],
                'status' => 'PENDING_APPROVAL', 'reason_code' => $d['reason_code'], 'notes' => $d['notes'] ?? null,
                'notice_mode' => $d['notice_mode'], 'effective_at' => isset($d['effective_at']) ? CarbonImmutable::parse($d['effective_at']) : $now,
                'policy_ids' => json_encode(array_column($p['policies'], 'policy_id')), 'policy_count' => $p['policy_count'], 'preview_hash' => $p['preview_hash'],
                'requested_by' => $maker->id, 'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $consent = $d['notice_mode'] === 'CONSENT' ? 'PENDING' : 'NOT_REQUIRED';
            foreach ($p['policies'] as $pol) {
                DB::table('policy_portfolio_transfer_items')->insert([
                    'id' => (string) Str::uuid(), 'transfer_id' => $id, 'policy_id' => $pol['policy_id'], 'party_id' => $pol['party_id'],
                    'from_id' => $d['from_id'], 'status' => 'PENDING', 'consent_status' => $consent, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->audit->record('policy.portfolio_transfer.requested', 'policy_portfolio_transfer', $id, ['scope' => $d['scope'], 'from_id' => $d['from_id'], 'to_id' => $d['to_id'], 'policy_count' => $p['policy_count'], 'notice_mode' => $d['notice_mode']], $d['reason_code']);
            $this->outbox->record('policy.portfolio_transfer.requested', 'policy_portfolio_transfer', $id, ['scope' => $d['scope'], 'from_id' => $d['from_id'], 'to_id' => $d['to_id'], 'policy_count' => $p['policy_count']]);

            return $this->find($tenantId, $id);
        });
    }

    /** Checker: a different user approves; the book must not have drifted. */
    public function approve(string $tenantId, string $transferId, ?string $notes, User $checker): object
    {
        return DB::transaction(function () use ($tenantId, $transferId, $notes, $checker) {
            $t = $this->lockPending($tenantId, $transferId, $checker);
            $ids = json_decode($t->policy_ids, true);
            $current = $this->currentBook($tenantId, $t->scope, $t->from_id, $ids)->pluck('id')->sort()->values()->all();
            if (hash('sha256', $t->scope.'|'.$t->from_id.'|'.$t->to_id.'|'.implode(',', $current)) !== $t->preview_hash) {
                throw ValidationException::withMessages(['transfer' => ['The portfolio changed since the request. Reject it and request the transfer again.']]);
            }
            $this->assertEnds($tenantId, $t->scope, $t->from_id, $t->to_id);
            $now = now();
            $consent = $t->notice_mode === 'CONSENT';
            DB::table('policy_portfolio_transfers')->where('id', $t->id)->update([
                'status' => $consent ? 'AWAITING_CONSENT' : 'COMPLETED', 'decided_by' => $checker->id, 'decided_at' => $now,
                'decision_notes' => $notes, 'completed_at' => $consent ? null : $now, 'updated_at' => $now,
            ]);
            $items = DB::table('policy_portfolio_transfer_items')->where('transfer_id', $t->id)->get();
            foreach ($items as $item) {
                if ($consent) {
                    DB::table('policy_portfolio_transfer_items')->where('id', $item->id)->update(['status' => 'AWAITING_CONSENT', 'notice_sent_at' => $now, 'updated_at' => $now]);
                    $this->outbox->record('policy.portfolio_transfer.consent_requested', 'policy', $item->policy_id, ['transfer_id' => $t->id, 'party_id' => $item->party_id, 'scope' => $t->scope, 'to_id' => $t->to_id]);
                } else {
                    $this->applyItem($t, $item, $checker, true);
                }
            }
            $this->audit->record('policy.portfolio_transfer.approved', 'policy_portfolio_transfer', $t->id, ['policy_count' => $items->count(), 'notice_mode' => $t->notice_mode, 'maker' => $t->requested_by], $notes);
            $this->outbox->record('policy.portfolio_transfer.approved', 'policy_portfolio_transfer', $t->id, ['scope' => $t->scope, 'from_id' => $t->from_id, 'to_id' => $t->to_id, 'policy_count' => $items->count(), 'notice_mode' => $t->notice_mode]);

            return $this->find($tenantId, $t->id);
        });
    }

    public function reject(string $tenantId, string $transferId, string $notes, User $checker): object
    {
        return DB::transaction(function () use ($tenantId, $transferId, $notes, $checker) {
            $t = $this->lockPending($tenantId, $transferId, $checker);
            $now = now();
            DB::table('policy_portfolio_transfers')->where('id', $t->id)->update(['status' => 'REJECTED', 'decided_by' => $checker->id, 'decided_at' => $now, 'decision_notes' => $notes, 'updated_at' => $now]);
            DB::table('policy_portfolio_transfer_items')->where('transfer_id', $t->id)->update(['status' => 'EXCLUDED', 'updated_at' => $now]);
            $this->audit->record('policy.portfolio_transfer.rejected', 'policy_portfolio_transfer', $t->id, ['maker' => $t->requested_by], $notes);
            $this->outbox->record('policy.portfolio_transfer.rejected', 'policy_portfolio_transfer', $t->id, ['scope' => $t->scope]);

            return $this->find($tenantId, $t->id);
        });
    }

    /** CONSENT mode: record the policyholder's answer for one policy. */
    public function recordConsent(string $tenantId, string $transferId, string $policyId, bool $granted, string $evidence, User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $transferId, $policyId, $granted, $evidence, $actor) {
            $t = DB::table('policy_portfolio_transfers')->where('tenant_id', $tenantId)->where('id', $transferId)->lockForUpdate()->first();
            abort_unless($t, 404);
            $item = DB::table('policy_portfolio_transfer_items')->where('transfer_id', $t->id)->where('policy_id', $policyId)->first();
            abort_unless($item, 404);
            if ($t->status !== 'AWAITING_CONSENT' || $item->status !== 'AWAITING_CONSENT') {
                throw ValidationException::withMessages(['consent' => ['This policy is not awaiting customer consent.']]);
            }
            $now = now();
            DB::table('policy_portfolio_transfer_items')->where('id', $item->id)->update([
                'consent_status' => $granted ? 'GRANTED' : 'REFUSED', 'consent_evidence' => $evidence, 'consent_recorded_by' => $actor->id,
                'consent_recorded_at' => $now, 'status' => $granted ? 'AWAITING_CONSENT' : 'EXCLUDED', 'updated_at' => $now,
            ]);
            $this->audit->record('policy.portfolio_transfer.consent_recorded', 'policy', $policyId, ['transfer_id' => $t->id, 'granted' => $granted, 'evidence' => $evidence]);
            if ($granted) {
                $this->applyItem($t, DB::table('policy_portfolio_transfer_items')->find($item->id), $actor, false);
            }
            if (! DB::table('policy_portfolio_transfer_items')->where('transfer_id', $t->id)->where('status', 'AWAITING_CONSENT')->exists()) {
                DB::table('policy_portfolio_transfers')->where('id', $t->id)->update(['status' => 'COMPLETED', 'completed_at' => $now, 'updated_at' => $now]);
            }

            return $this->find($tenantId, $t->id);
        });
    }

    public function find(string $tenantId, string $id): object
    {
        $t = Str::isUuid($id) ? DB::table('policy_portfolio_transfers')->where('tenant_id', $tenantId)->where('id', $id)->first() : null;
        abort_unless($t, 404);
        $t->items = DB::table('policy_portfolio_transfer_items')->where('transfer_id', $t->id)->orderBy('created_at')->get();

        return $t;
    }

    /** @return Collection<int, object> */
    public function servicingHistory(string $tenantId, string $policyId): Collection
    {
        return DB::table('policy_servicing_events')->where('tenant_id', $tenantId)->where('policy_id', $policyId)->orderBy('occurred_at')->get();
    }

    private function applyItem(object $t, object $item, User $actor, bool $notice): void
    {
        $now = now();
        $column = $t->scope === 'CARRIER' ? 'servicing_carrier_id' : 'servicing_partner_id';
        DB::table('policies')->where('id', $item->policy_id)->update([$column => $t->to_id, 'updated_at' => $now]);
        DB::table('policy_portfolio_transfer_items')->where('id', $item->id)->update(['status' => 'APPLIED', 'applied_at' => $now, 'notice_sent_at' => $item->notice_sent_at ?? ($notice ? $now : null), 'updated_at' => $now]);
        DB::table('policy_servicing_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $t->tenant_id, 'policy_id' => $item->policy_id, 'transfer_id' => $t->id, 'scope' => $t->scope,
            'from_id' => $item->from_id, 'to_id' => $t->to_id, 'effective_at' => $t->effective_at, 'actor_id' => $actor->id, 'occurred_at' => $now,
        ]);
        // Consumers (notifications) send the policyholder the transfer notice from this event.
        $this->outbox->record('policy.servicing.transferred', 'policy', $item->policy_id, [
            'transfer_id' => $t->id, 'scope' => $t->scope, 'from_id' => $item->from_id, 'to_id' => $t->to_id, 'party_id' => $item->party_id,
            'effective_at' => CarbonImmutable::parse($t->effective_at)->toIso8601String(), 'customer_notice' => true,
        ]);
    }

    private function lockPending(string $tenantId, string $id, User $checker): object
    {
        $t = Str::isUuid($id) ? DB::table('policy_portfolio_transfers')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first() : null;
        abort_unless($t, 404);
        if ($t->status !== 'PENDING_APPROVAL') {
            throw ValidationException::withMessages(['transfer' => ['This transfer is no longer pending approval.']]);
        }
        if ($t->requested_by === $checker->id) {
            throw ValidationException::withMessages(['transfer' => ['The requester cannot approve or reject their own transfer (maker-checker).']]);
        }

        return $t;
    }

    /** @param list<string>|null $policyIds */
    private function currentBook(string $tenantId, string $scope, string $fromId, ?array $policyIds): Collection
    {
        $q = DB::table('policies as p')->where('p.tenant_id', $tenantId)->whereIn('p.status', self::ELIGIBLE_STATUSES)
            ->when($policyIds !== null, fn ($q) => $q->whereIn('p.id', $policyIds));
        if ($scope === 'CARRIER') {
            $q->whereRaw('COALESCE(p.servicing_carrier_id, p.carrier_id) = ?', [$fromId]);
        } else {
            $q->where(fn ($w) => $w->where('p.servicing_partner_id', $fromId)->orWhere(fn ($x) => $x->whereNull('p.servicing_partner_id')
                ->whereExists(fn ($e) => $e->from('customer_attributions as a')->whereColumn('a.party_id', 'p.party_id')->where('a.status', 'ACTIVE')->where('a.partner_id', $fromId))));
        }

        return $q->orderBy('p.policy_number')->orderBy('p.id')->get(['p.id', 'p.policy_number', 'p.party_id', 'p.status']);
    }

    private function assertEnds(string $tenantId, string $scope, string $fromId, string $toId): void
    {
        if ($fromId === $toId) {
            throw ValidationException::withMessages(['to_id' => ['Choose a different recipient.']]);
        }
        if ($scope === 'CARRIER') {
            $to = DB::table('carriers')->where('id', $toId)->first();
            if (! DB::table('carriers')->where('id', $fromId)->exists() || ! $to || $to->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['to_id' => ['Choose an existing source carrier and an active receiving carrier.']]);
            }

            return;
        }
        $partners = DB::table('partners')->where('tenant_id', $tenantId)->whereIn('type', ['AGENT', 'BROKER'])->whereIn('id', [$fromId, $toId])->get()->keyBy('id');
        if (! isset($partners[$fromId]) || ! isset($partners[$toId]) || $partners[$toId]->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['to_id' => ['Choose an agent or broker of this organisation and an active receiving intermediary.']]);
        }
    }

    private function assertHash(array $preview, string $hash): void
    {
        if (! hash_equals($preview['preview_hash'], $hash)) {
            throw ValidationException::withMessages(['preview_hash' => ['The portfolio changed since the preview. Preview it again.']]);
        }
    }
}
