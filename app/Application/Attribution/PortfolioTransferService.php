<?php

declare(strict_types=1);

namespace App\Application\Attribution;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CRM-003 / WF-079 — portfolio transfer between intermediaries.
 *
 * Works on the canonical origin lock (customer_attributions, one ACTIVE row per
 * party). preview() lists exactly what would move and returns a preview_hash;
 * execute() refuses unless the same hash is presented, so the book cannot
 * drift between what the operator saw and what is moved. Customers with an
 * OPEN / UNDER_REVIEW attribution dispute are excluded (disputes are resolved
 * through AttributionService, never overridden by a bulk move).
 *
 * Per-customer history stays in attribution_events (type TRANSFERRED) — the
 * same log disputes/reassignments already use; portfolio_transfers is only the
 * batch header.
 */
final class PortfolioTransferService
{
    public const REASONS = ['PARTNER_EXIT', 'LICENCE_WITHDRAWN', 'MERGER', 'REORGANISATION', 'CUSTOMER_REQUEST', 'OTHER'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /**
     * @param  list<string>|null  $partyIds  subset of the book; null = whole book
     * @return array{from_partner_id: string, to_partner_id: string, customers: list<array>, excluded: list<array>, customer_count: int, policy_count: int, preview_hash: string}
     */
    public function preview(string $tenantId, string $fromId, string $toId, ?array $partyIds = null): array
    {
        [$from, $to] = [$this->partner($tenantId, $fromId, 'from_partner_id', false), $this->partner($tenantId, $toId, 'to_partner_id', true)];
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_partner_id' => ['Choose a different intermediary to receive the portfolio.']]);
        }

        $rows = DB::table('customer_attributions as a')->join('parties as p', 'p.id', '=', 'a.party_id')
            ->where('a.partner_id', $from->id)->where('a.status', 'ACTIVE')
            ->when($partyIds !== null, fn ($q) => $q->whereIn('a.party_id', $partyIds))
            ->orderBy('p.display_name')->orderBy('a.party_id')
            ->get(['a.id as attribution_id', 'a.party_id', 'p.display_name']);
        $disputed = DB::table('attribution_disputes')->whereIn('attribution_id', $rows->pluck('attribution_id'))->whereIn('status', ['OPEN', 'UNDER_REVIEW'])->pluck('attribution_id')->all();

        $moving = $rows->reject(fn ($r) => in_array($r->attribution_id, $disputed, true))->values();
        $excluded = $rows->filter(fn ($r) => in_array($r->attribution_id, $disputed, true))->values();
        $policies = DB::table('policies')->where('tenant_id', $tenantId)->whereIn('party_id', $moving->pluck('party_id'))->whereIn('status', ['ACTIVE', 'ISSUED'])
            ->selectRaw('party_id, count(*) as n')->groupBy('party_id')->pluck('n', 'party_id');

        $ids = $moving->pluck('party_id')->sort()->values()->all();

        return [
            'from_partner_id' => $from->id,
            'to_partner_id' => $to->id,
            'customers' => $moving->map(fn ($r) => ['party_id' => $r->party_id, 'display_name' => $r->display_name, 'attribution_id' => $r->attribution_id, 'active_policies' => (int) ($policies[$r->party_id] ?? 0)])->all(),
            'excluded' => $excluded->map(fn ($r) => ['party_id' => $r->party_id, 'display_name' => $r->display_name, 'reason' => 'OPEN_DISPUTE'])->all(),
            'customer_count' => count($ids),
            'policy_count' => (int) $policies->sum(),
            'preview_hash' => hash('sha256', $from->id.'|'.$to->id.'|'.implode(',', $ids)),
        ];
    }

    /** @param list<string>|null $partyIds */
    public function execute(string $tenantId, string $fromId, string $toId, ?array $partyIds, string $previewHash, string $reason, ?string $notes, User $actor): object
    {
        return DB::transaction(function () use ($tenantId, $fromId, $toId, $partyIds, $previewHash, $reason, $notes, $actor) {
            DB::table('customer_attributions')->where('partner_id', $fromId)->where('status', 'ACTIVE')->lockForUpdate()->get(['id']);
            $preview = $this->preview($tenantId, $fromId, $toId, $partyIds);
            if (! hash_equals($preview['preview_hash'], $previewHash)) {
                throw ValidationException::withMessages(['preview_hash' => ['The portfolio changed since the preview. Preview it again before transferring.']]);
            }
            if ($preview['customer_count'] === 0) {
                throw ValidationException::withMessages(['from_partner_id' => ['There are no customers to transfer.']]);
            }
            $to = Partner::findOrFail($toId);
            $id = (string) Str::uuid();
            $now = now();
            DB::table('portfolio_transfers')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'from_partner_id' => $fromId, 'to_partner_id' => $toId, 'status' => 'EXECUTED',
                'reason_code' => $reason, 'notes' => $notes, 'party_ids' => json_encode(array_column($preview['customers'], 'party_id')),
                'customer_count' => $preview['customer_count'], 'policy_count' => $preview['policy_count'], 'preview_hash' => $previewHash,
                'executed_by' => $actor->id, 'executed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($preview['customers'] as $c) {
                DB::table('customer_attributions')->where('id', $c['attribution_id'])->update([
                    'partner_id' => $toId, 'origin_type' => $to->type === 'AGENT' ? 'AGENT' : 'BROKER', 'evidence_reference' => 'TRANSFER:'.$id, 'updated_at' => $now,
                ]);
                DB::table('attribution_events')->insert([
                    'id' => (string) Str::uuid(), 'attribution_id' => $c['attribution_id'], 'type' => 'TRANSFERRED', 'from_partner_id' => $fromId,
                    'to_partner_id' => $toId, 'dispute_id' => null, 'reason_code' => $reason, 'notes' => $notes ?? 'Portfolio transfer '.$id, 'actor_id' => $actor->id, 'occurred_at' => $now,
                ]);
                $this->outbox->record('customer.attribution.changed', 'customer_attribution', $c['attribution_id'], ['attribution_id' => $c['attribution_id'], 'resolution' => 'TRANSFERRED', 'partner_id' => $toId, 'portfolio_transfer_id' => $id]);
            }
            $this->audit->record('attribution.portfolio.transferred', 'portfolio_transfer', $id, ['from_partner_id' => $fromId, 'to_partner_id' => $toId, 'customer_count' => $preview['customer_count'], 'policy_count' => $preview['policy_count']], $reason);
            $this->outbox->record('partner.portfolio.transferred', 'portfolio_transfer', $id, ['from_partner_id' => $fromId, 'to_partner_id' => $toId, 'customer_count' => $preview['customer_count']]);

            return DB::table('portfolio_transfers')->find($id);
        });
    }

    private function partner(string $tenantId, string $id, string $field, bool $mustBeActive): Partner
    {
        $p = Str::isUuid($id) ? Partner::where('tenant_id', $tenantId)->whereIn('type', ['AGENT', 'BROKER'])->find($id) : null;
        if (! $p || ($mustBeActive && $p->status !== 'ACTIVE')) {
            throw ValidationException::withMessages([$field => [$mustBeActive ? 'Choose an active agent or broker in this organisation.' : 'Choose an agent or broker in this organisation.']]);
        }

        return $p;
    }
}
