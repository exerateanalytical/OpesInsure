<?php

declare(strict_types=1);

namespace App\Application\Stickers;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\CarrierScopeResolver;
use App\Models\Policy;
use App\Models\StickerStock;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-POL-007 (WF-032/033, SCF §47) — the motor sticker custody chain
 *     CARRIER → BROKER (tenant) → BRANCH (tenant_branches) → AGENT (user) → POLICY.
 *
 * Every move is a handover of named serials one level along the chain (ALLOCATE downwards, RETURN upwards):
 * initiated by the giver (stickers go IN_TRANSIT), then accepted by the receiver — never the initiator; an AGENT
 * handover is accepted by that agent — or rejected / cancelled (stickers go back). Every step writes
 * sticker_custody_events. Reconciliation compares a physical count against the ledger for one holder and marks
 * missing / damaged stickers. A sticker is assigned to a motor policy from the issuing tenant's stock; an
 * agent-held sticker only by that agent (or a holder of stickers.assign.any).
 *
 * Owner decision (Batch 7 wiring): staff linked to one insurer (CarrierScopeResolver) move, reconcile and assign
 * stickers of their own carrier only; broker / tenant staff are not carrier-scoped.
 */
final class StickerCustodyService
{
    public const LEVELS = ['CARRIER', 'BROKER', 'BRANCH', 'AGENT'];

    public const MOTOR_LINES = ['AUTO', 'AUTOMOBILE', 'MOTOR'];

    public function __construct(private readonly AuditWriter $audit, private readonly CarrierScopeResolver $carrierScope) {}

    /** Carrier staff act on their own carrier's stickers only (owner decision). */
    public function assertCarrierScope(User $actor, string $carrierId, string $tenantId): void
    {
        $own = $this->carrierScope->carrierIdFor($actor, $tenantId);
        if ($own !== null && $own !== $carrierId) {
            throw new AuthorizationException('You can only handle stickers of your own insurer.');
        }
    }

    /**
     * @param  array{level: string, tenant_id?: ?string, branch_id?: ?string, user_id?: ?string}  $holder
     * @return array{level: string, tenant_id: ?string, branch_id: ?string, user_id: ?string}
     */
    public function holder(array $holder, string $contextTenantId): array
    {
        $level = strtoupper((string) ($holder['level'] ?? ''));
        if (! in_array($level, self::LEVELS, true)) {
            throw ValidationException::withMessages(['level' => 'Unknown custody level.']);
        }
        if ($level === 'CARRIER') {
            return ['level' => 'CARRIER', 'tenant_id' => null, 'branch_id' => null, 'user_id' => null];
        }
        // Broker, branch and agent custody is always inside the caller's own tenant.
        $h = ['level' => $level, 'tenant_id' => $contextTenantId, 'branch_id' => null, 'user_id' => null];
        if ($level === 'BRANCH') {
            $ok = ! empty($holder['branch_id']) && DB::table('tenant_branches')->where(['id' => $holder['branch_id'], 'tenant_id' => $contextTenantId, 'status' => 'ACTIVE'])->whereNull('deleted_at')->exists();
            if (! $ok) {
                throw ValidationException::withMessages(['branch_id' => 'The branch must be an active branch of this tenant.']);
            }
            $h['branch_id'] = $holder['branch_id'];
        }
        if ($level === 'AGENT') {
            $m = ! empty($holder['user_id']) ? DB::table('tenant_memberships')->where(['tenant_id' => $contextTenantId, 'user_id' => $holder['user_id'], 'status' => 'ACTIVE'])->first() : null;
            if (! $m) {
                throw ValidationException::withMessages(['user_id' => 'The agent must be an active member of this tenant.']);
            }
            $h['user_id'] = $holder['user_id'];
            $h['branch_id'] = $m->branch_id ?? null;
        }

        return $h;
    }

    /** @param list<string> $serials */
    public function initiateHandover(string $carrierId, array $from, array $to, array $serials, User $actor, ?string $notes = null): object
    {
        $diff = array_search($to['level'], self::LEVELS, true) - array_search($from['level'], self::LEVELS, true);
        if (abs($diff) !== 1) {
            throw ValidationException::withMessages(['to' => 'Stickers move one level along the chain carrier → broker → branch → agent (or back).']);
        }
        if ($from === $to) {
            throw ValidationException::withMessages(['to' => 'The receiver must differ from the giver.']);
        }
        $this->assertCarrierScope($actor, $carrierId, (string) ($from['tenant_id'] ?? $to['tenant_id']));

        return DB::transaction(function () use ($carrierId, $from, $to, $serials, $actor, $notes, $diff): object {
            $stock = $this->heldBy($carrierId, $from)->whereIn('serial_number', $serials)->where('status', 'IN_STOCK')->lockForUpdate()->get();
            $missing = array_values(array_diff($serials, $stock->pluck('serial_number')->all()));
            if ($missing !== []) {
                throw ValidationException::withMessages(['serial_numbers' => 'Not in stock with the giver: '.implode(', ', array_slice($missing, 0, 20))]);
            }
            $id = (string) Str::uuid();
            DB::table('sticker_handovers')->insert([
                'id' => $id, 'carrier_id' => $carrierId, 'direction' => $diff > 0 ? 'ALLOCATE' : 'RETURN', 'status' => 'PENDING', 'quantity' => $stock->count(), 'notes' => $notes,
                'from_level' => $from['level'], 'from_tenant_id' => $from['tenant_id'], 'from_branch_id' => $from['branch_id'], 'from_user_id' => $from['user_id'],
                'to_level' => $to['level'], 'to_tenant_id' => $to['tenant_id'], 'to_branch_id' => $to['branch_id'], 'to_user_id' => $to['user_id'],
                'initiated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('sticker_handover_items')->insert($stock->map(fn ($s) => ['sticker_handover_id' => $id, 'sticker_stock_id' => $s->id])->all());
            StickerStock::whereIn('id', $stock->pluck('id'))->update(['status' => 'IN_TRANSIT', 'updated_at' => now()]);
            $this->events($stock, 'HANDOVER_INITIATED', $from, $to, $actor, 'HANDOVER', ['sticker_handover_id' => $id]);
            $this->audit->record('sticker.handover.initiated', 'sticker_handover', $id, ['from' => $from['level'], 'to' => $to['level'], 'quantity' => $stock->count()]);

            return $this->handover($id);
        });
    }

    public function accept(string $handoverId, User $actor, string $contextTenantId): object
    {
        return DB::transaction(function () use ($handoverId, $actor, $contextTenantId): object {
            $h = $this->pending($handoverId, $contextTenantId);
            $this->assertCarrierScope($actor, (string) $h->carrier_id, $contextTenantId);
            if ($h->initiated_by === $actor->id) {
                throw ValidationException::withMessages(['handover' => 'The receiver must acknowledge the handover; the initiator cannot accept it.']);
            }
            if ($h->to_level === 'AGENT' && $h->to_user_id !== $actor->id) {
                throw ValidationException::withMessages(['handover' => 'Only the receiving agent can accept this handover.']);
            }
            $to = ['level' => $h->to_level, 'tenant_id' => $h->to_tenant_id, 'branch_id' => $h->to_branch_id, 'user_id' => $h->to_user_id];
            $stock = $this->items($h->id);
            StickerStock::whereIn('id', $stock->pluck('id'))->update(['status' => 'IN_STOCK', 'custody_level' => $to['level'], 'custodian_tenant_id' => $to['tenant_id'],
                'custodian_branch_id' => $to['branch_id'], 'custodian_user_id' => $to['user_id'], 'updated_at' => now()]);
            $this->events($stock, 'HANDOVER_ACCEPTED', $this->fromOf($h), $to, $actor, 'HANDOVER', ['sticker_handover_id' => $h->id]);

            return $this->decide($h, 'ACCEPTED', $actor, null);
        });
    }

    public function reject(string $handoverId, User $actor, string $contextTenantId, string $reason, bool $cancel = false): object
    {
        return DB::transaction(function () use ($handoverId, $actor, $contextTenantId, $reason, $cancel): object {
            $h = $this->pending($handoverId, $contextTenantId);
            $this->assertCarrierScope($actor, (string) $h->carrier_id, $contextTenantId);
            if ($cancel && $h->initiated_by !== $actor->id) {
                throw ValidationException::withMessages(['handover' => 'Only the initiator can cancel a handover.']);
            }
            if (! $cancel && $h->initiated_by === $actor->id) {
                throw ValidationException::withMessages(['handover' => 'The initiator cancels, the receiver rejects.']);
            }
            $stock = $this->items($h->id);
            StickerStock::whereIn('id', $stock->pluck('id'))->update(['status' => 'IN_STOCK', 'updated_at' => now()]);
            $this->events($stock, $cancel ? 'HANDOVER_CANCELLED' : 'HANDOVER_REJECTED', $this->fromOf($h), $this->fromOf($h), $actor, mb_substr($reason, 0, 64), ['sticker_handover_id' => $h->id]);

            return $this->decide($h, $cancel ? 'CANCELLED' : 'REJECTED', $actor, $reason);
        });
    }

    /**
     * @param  list<string>  $counted  serials physically present
     * @param  list<string>  $damaged  serials present but unusable
     */
    public function reconcile(string $carrierId, array $holder, array $counted, array $damaged, User $actor, ?string $notes = null): object
    {
        $this->assertCarrierScope($actor, $carrierId, (string) $holder['tenant_id']);
        return DB::transaction(function () use ($carrierId, $holder, $counted, $damaged, $actor, $notes): object {
            $expected = $this->heldBy($carrierId, $holder)->where('status', 'IN_STOCK')->lockForUpdate()->get();
            $expectedSerials = $expected->pluck('serial_number')->all();
            $missing = array_values(array_diff($expectedSerials, $counted));
            $unexpected = array_values(array_diff($counted, $expectedSerials));
            $damaged = array_values(array_intersect($damaged, $counted, $expectedSerials));
            $id = (string) Str::uuid();
            DB::table('sticker_reconciliations')->insert([
                'id' => $id, 'carrier_id' => $carrierId, 'level' => $holder['level'], 'tenant_id' => $holder['tenant_id'], 'branch_id' => $holder['branch_id'], 'user_id' => $holder['user_id'],
                'expected_count' => count($expectedSerials), 'counted_count' => count(array_unique($counted)), 'missing_serials' => json_encode($missing),
                'unexpected_serials' => json_encode($unexpected), 'damaged_serials' => json_encode($damaged),
                'status' => $missing === [] && $unexpected === [] && $damaged === [] ? 'BALANCED' : 'DISCREPANCY', 'notes' => $notes,
                'performed_by' => $actor->id, 'performed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (['MISSING' => $missing, 'DAMAGED' => $damaged] as $status => $serials) {
                $rows = $expected->whereIn('serial_number', $serials)->values();
                if ($rows->isNotEmpty()) {
                    StickerStock::whereIn('id', $rows->pluck('id'))->update(['status' => $status, 'updated_at' => now()]);
                    $this->events($rows, 'RECONCILED_'.$status, $holder, $holder, $actor, 'RECONCILIATION', ['sticker_reconciliation_id' => $id]);
                }
            }
            $this->audit->record('sticker.reconciliation.recorded', 'sticker_reconciliation', $id, ['level' => $holder['level'], 'missing' => count($missing), 'unexpected' => count($unexpected), 'damaged' => count($damaged)]);

            return $this->reconciliation($id);
        });
    }

    /** Assigns an in-stock sticker of the policy's carrier and tenant to a motor policy (custody level POLICY). */
    public function assignToPolicy(Policy $policy, string $serial, User $actor): StickerStock
    {
        $this->assertCarrierScope($actor, (string) $policy->carrier_id, (string) $policy->tenant_id);
        return DB::transaction(function () use ($policy, $serial, $actor): StickerStock {
            $policy->loadMissing('proposal.offer.quote');
            $line = strtoupper((string) $policy->proposal?->offer?->quote?->line_code);
            if (! in_array($line, self::MOTOR_LINES, true)) {
                throw ValidationException::withMessages(['policy_id' => 'Motor stickers are assigned to motor policies only.']);
            }
            if (! in_array($policy->status, ['ACTIVE', 'EXPIRING'], true)) {
                throw ValidationException::withMessages(['policy_id' => 'The policy must be in force to receive a sticker.']);
            }
            if (StickerStock::where('assigned_policy_id', $policy->id)->where('status', 'ASSIGNED')->exists()) {
                throw ValidationException::withMessages(['policy_id' => 'This policy already has a sticker assigned.']);
            }
            $s = StickerStock::where(['serial_number' => $serial, 'carrier_id' => $policy->carrier_id, 'custodian_tenant_id' => $policy->tenant_id, 'status' => 'IN_STOCK'])
                ->whereIn('custody_level', ['BROKER', 'BRANCH', 'AGENT'])->lockForUpdate()->first();
            if (! $s) {
                throw ValidationException::withMessages(['sticker_serial_number' => __('wave5.sticker_unavailable')]);
            }
            if ($s->custody_level === 'AGENT' && $s->custodian_user_id !== $actor->id && ! $actor->hasPermission('stickers.assign.any')) {
                throw ValidationException::withMessages(['sticker_serial_number' => 'This sticker is in another agent\'s custody.']);
            }
            $from = ['level' => $s->custody_level, 'tenant_id' => $s->custodian_tenant_id, 'branch_id' => $s->custodian_branch_id, 'user_id' => $s->custodian_user_id];
            $s->update(['status' => 'ASSIGNED', 'custody_level' => 'POLICY', 'assigned_policy_id' => $policy->id, 'assigned_at' => now()]);
            $this->events(collect([$s]), 'ASSIGNED_TO_POLICY', $from, ['level' => 'POLICY', 'tenant_id' => $policy->tenant_id, 'branch_id' => null, 'user_id' => null],
                $actor, 'POLICY_ISSUANCE', ['policy_id' => $policy->id]);
            $this->audit->record('sticker.assigned_to_policy', 'sticker_stock', $s->id, ['policy_id' => $policy->id, 'serial_number' => $serial]);

            return $s->refresh();
        });
    }

    /** Stock counts per custody level / status for a carrier (optionally one tenant's chain). */
    public function inventory(string $carrierId, ?string $tenantId): array
    {
        return StickerStock::where('carrier_id', $carrierId)
            ->when($tenantId, fn ($q) => $q->where(fn ($w) => $w->where('custodian_tenant_id', $tenantId)->orWhere('custody_level', 'CARRIER')))
            ->selectRaw('custody_level, status, custodian_branch_id, custodian_user_id, count(*) as quantity')
            ->groupBy('custody_level', 'status', 'custodian_branch_id', 'custodian_user_id')->orderBy('custody_level')->get()->toArray();
    }

    public function heldBy(string $carrierId, array $holder): \Illuminate\Database\Eloquent\Builder
    {
        $q = StickerStock::where('carrier_id', $carrierId)->where('custody_level', $holder['level']);
        foreach (['tenant_id' => 'custodian_tenant_id', 'branch_id' => 'custodian_branch_id', 'user_id' => 'custodian_user_id'] as $k => $col) {
            $holder[$k] === null ? $q->whereNull($col) : $q->where($col, $holder[$k]);
        }

        return $q;
    }

    public function handover(string $id): object
    {
        $h = DB::table('sticker_handovers')->find($id);
        $h->serial_numbers = $this->items($id)->pluck('serial_number')->all();

        return $h;
    }

    public function reconciliation(string $id): object
    {
        $r = DB::table('sticker_reconciliations')->find($id);
        foreach (['missing_serials', 'unexpected_serials', 'damaged_serials'] as $k) {
            $r->{$k} = json_decode((string) $r->{$k}, true);
        }

        return $r;
    }

    private function pending(string $id, string $contextTenantId): object
    {
        $h = DB::table('sticker_handovers')->where('id', $id)->lockForUpdate()->first();
        if (! $h || ($h->from_tenant_id !== $contextTenantId && $h->to_tenant_id !== $contextTenantId)) {
            abort(404);
        }
        if ($h->status !== 'PENDING') {
            throw ValidationException::withMessages(['handover' => 'This handover is already '.$h->status.'.']);
        }

        return $h;
    }

    private function items(string $handoverId): Collection
    {
        return StickerStock::whereIn('id', DB::table('sticker_handover_items')->where('sticker_handover_id', $handoverId)->pluck('sticker_stock_id'))->get();
    }

    private function fromOf(object $h): array
    {
        return ['level' => $h->from_level, 'tenant_id' => $h->from_tenant_id, 'branch_id' => $h->from_branch_id, 'user_id' => $h->from_user_id];
    }

    private function decide(object $h, string $status, User $actor, ?string $reason): object
    {
        DB::table('sticker_handovers')->where('id', $h->id)->update(['status' => $status, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $reason, 'updated_at' => now()]);
        $this->audit->record('sticker.handover.'.strtolower($status), 'sticker_handover', $h->id, ['quantity' => $h->quantity], $reason);

        return $this->handover($h->id);
    }

    private function events(Collection $stock, string $type, array $from, array $to, User $actor, string $reason, array $extra = []): void
    {
        $now = now();
        DB::table('sticker_custody_events')->insert($stock->map(fn ($s) => [
            'id' => (string) Str::uuid(), 'sticker_stock_id' => $s->id, 'event_type' => $type, 'reason_code' => $reason, 'actor_id' => $actor->id, 'occurred_at' => $now,
            'from_level' => $from['level'], 'to_level' => $to['level'], 'from_tenant_id' => $from['tenant_id'], 'to_tenant_id' => $to['tenant_id'],
            'from_branch_id' => $from['branch_id'], 'to_branch_id' => $to['branch_id'], 'from_user_id' => $from['user_id'], 'to_user_id' => $to['user_id'],
            'policy_id' => $extra['policy_id'] ?? null, 'sticker_handover_id' => $extra['sticker_handover_id'] ?? null, 'sticker_reconciliation_id' => $extra['sticker_reconciliation_id'] ?? null,
        ])->all());
    }
}
