<?php

declare(strict_types=1);

namespace App\Application\Accumulation;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Reinsurance\TreatyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CAT-003 — catastrophe event management.
 *
 * Declare an event (peril, zones, date window) → link claims whose loss date falls in the window (claims.catastrophe_event_id)
 * → aggregate the event loss (claim loss = approved amount, else current reserve, else estimate) and estimate the
 * EXCESS_OF_LOSS recoverable on the aggregate from the treaties in force at the event start. The
 * `catastrophe.event.losses_aggregated` outbox event carries the event id so reinsurance recoveries can be raised per event.
 */
final class CatastropheEventService
{
    public function __construct(
        private readonly TreatyService $treaties,
        private readonly LargeLossNotifier $largeLoss,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function declare(string $tenantId, array $data, ?string $actorId = null): array
    {
        if (DB::table('catastrophe_events')->where('tenant_id', $tenantId)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Event code already exists.']);
        }
        $zoneIds = array_values(array_unique($data['zone_ids'] ?? []));
        if (DB::table('accumulation_zones')->where('tenant_id', $tenantId)->whereIn('id', $zoneIds)->count() !== count($zoneIds)) {
            throw ValidationException::withMessages(['zone_ids' => 'Unknown zone.']);
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $tenantId, $data, $zoneIds, $actorId) {
            DB::table('catastrophe_events')->insert(['id' => $id, 'tenant_id' => $tenantId, 'code' => $data['code'], 'name' => $data['name'], 'peril_code' => strtoupper($data['peril_code']),
                'zone_ids' => json_encode($zoneIds), 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at'], 'currency' => $data['currency'], 'status' => 'DECLARED',
                'declared_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('catastrophe.event.declared', 'catastrophe_event', $id, ['code' => $data['code'], 'peril_code' => $data['peril_code'], 'zone_ids' => $zoneIds]);
            $this->outbox->record('catastrophe.event.declared', 'catastrophe_event', $id, ['tenant_id' => $tenantId, 'code' => $data['code'], 'peril_code' => strtoupper($data['peril_code']),
                'zone_ids' => $zoneIds, 'starts_at' => $data['starts_at'], 'ends_at' => $data['ends_at']]);
        });

        return $this->show($tenantId, $id);
    }

    public function linkClaim(string $tenantId, string $eventId, string $claimId, ?string $actorId = null): array
    {
        return DB::transaction(function () use ($tenantId, $eventId, $claimId, $actorId) {
            $e = $this->event($tenantId, $eventId, true);
            if ($e->status !== 'DECLARED') {
                throw ValidationException::withMessages(['event' => 'Claims can only be linked to a DECLARED event.']);
            }
            $claim = DB::table('claims')->where('tenant_id', $tenantId)->where('id', $claimId)->first() ?? abort(404, 'Claim not found.');
            if ($claim->catastrophe_event_id !== null) {
                throw ValidationException::withMessages(['claim' => 'The claim is already linked to a catastrophe event.']);
            }
            $loss = strtotime((string) $claim->loss_occurred_at);
            if ($loss < strtotime((string) $e->starts_at) || $loss > strtotime((string) $e->ends_at)) {
                throw ValidationException::withMessages(['claim' => 'The loss date is outside the event window.']);
            }
            DB::table('catastrophe_event_claims')->insert(['id' => (string) Str::uuid(), 'event_id' => $eventId, 'claim_id' => $claimId, 'linked_by' => $actorId, 'created_at' => now()]);
            DB::table('claims')->where('id', $claimId)->update(['catastrophe_event_id' => $eventId]);
            $this->audit->record('catastrophe.event.claim_linked', 'catastrophe_event', $eventId, ['claim_id' => $claimId]);
            $this->outbox->record('catastrophe.event.claim_linked', 'catastrophe_event', $eventId, ['tenant_id' => $tenantId, 'claim_id' => $claimId]);
            $this->largeLoss->check($tenantId, $claimId);

            return $this->show($tenantId, $eventId);
        });
    }

    public function aggregate(string $tenantId, string $eventId): array
    {
        return DB::transaction(function () use ($tenantId, $eventId) {
            $e = $this->event($tenantId, $eventId, true);
            $claims = DB::table('claims')->where('tenant_id', $tenantId)->where('catastrophe_event_id', $eventId)->get();
            $gross = 0;
            foreach ($claims as $c) {
                if ($c->currency === $e->currency) {
                    $gross += self::claimLoss($c);
                }
            }
            $recoverable = $this->xlRecoverable($tenantId, $gross, $e->currency, substr((string) $e->starts_at, 0, 10));
            DB::table('catastrophe_events')->where('id', $eventId)->update(['gross_loss_minor' => $gross, 'recoverable_minor' => $recoverable, 'aggregated_at' => now(), 'updated_at' => now()]);
            $payload = ['tenant_id' => $tenantId, 'event_id' => $eventId, 'currency' => $e->currency, 'claim_count' => $claims->count(), 'gross_loss_minor' => $gross,
                'recoverable_minor' => $recoverable, 'net_loss_minor' => $gross - $recoverable];
            $this->audit->record('catastrophe.event.losses_aggregated', 'catastrophe_event', $eventId, $payload);
            $this->outbox->record('catastrophe.event.losses_aggregated', 'catastrophe_event', $eventId, $payload);

            return $this->show($tenantId, $eventId);
        });
    }

    public function close(string $tenantId, string $eventId, string $reason): array
    {
        $this->aggregate($tenantId, $eventId);
        DB::transaction(function () use ($tenantId, $eventId, $reason) {
            $e = $this->event($tenantId, $eventId, true);
            if ($e->status !== 'DECLARED') {
                throw ValidationException::withMessages(['event' => 'The event is already closed.']);
            }
            DB::table('catastrophe_events')->where('id', $eventId)->update(['status' => 'CLOSED', 'updated_at' => now()]);
            $this->audit->record('catastrophe.event.closed', 'catastrophe_event', $eventId, [], $reason);
            $this->outbox->record('catastrophe.event.closed', 'catastrophe_event', $eventId, ['tenant_id' => $tenantId, 'gross_loss_minor' => (int) $e->gross_loss_minor]);
        });

        return $this->show($tenantId, $eventId);
    }

    public function show(string $tenantId, string $eventId): array
    {
        $e = $this->event($tenantId, $eventId);
        $claims = DB::table('claims')->where('catastrophe_event_id', $eventId)->orderBy('claim_number')->get()
            ->map(fn ($c) => ['claim_id' => $c->id, 'claim_number' => $c->claim_number, 'policy_id' => $c->policy_id, 'loss_minor' => self::claimLoss($c), 'currency' => $c->currency])->all();

        return ['id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'peril_code' => $e->peril_code, 'zone_ids' => json_decode((string) $e->zone_ids, true) ?: [],
            'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at, 'currency' => $e->currency, 'status' => $e->status, 'gross_loss_minor' => (int) $e->gross_loss_minor,
            'recoverable_minor' => (int) $e->recoverable_minor, 'net_loss_minor' => (int) $e->gross_loss_minor - (int) $e->recoverable_minor, 'aggregated_at' => $e->aggregated_at, 'claims' => $claims];
    }

    public static function claimLoss(object $c): int
    {
        return (int) ($c->approved_amount_minor ?? $c->current_reserve_minor ?? $c->estimated_loss_minor ?? 0);
    }

    private function event(string $tenantId, string $id, bool $lock = false): object
    {
        $q = DB::table('catastrophe_events')->where('tenant_id', $tenantId)->where('id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? abort(404, 'Catastrophe event not found.');
    }

    /** Excess-of-loss layers in force at the event start applied to the aggregate event loss. */
    private function xlRecoverable(string $tenantId, int $loss, string $currency, string $date): int
    {
        $total = 0;
        foreach ($this->treaties->effectiveVersions($tenantId, $date, null, $currency) as $v) {
            if (($v['treaty_type'] ?? null) !== 'EXCESS_OF_LOSS') {
                continue;
            }
            foreach ((array) $v['layers'] as $layer) {
                $total += min(max($loss - (int) $layer['attachment_minor'], 0), (int) $layer['limit_minor']);
            }
        }

        return min($total, $loss);
    }
}
