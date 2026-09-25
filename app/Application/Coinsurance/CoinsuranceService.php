<?php

declare(strict_types=1);

namespace App\Application\Coinsurance;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\FinancialDistribution\ReinsuranceReference;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-COI-001 — co-insurance (FRP Part III COI-001..008, MPS §41).
 *
 * One apériteur (LEAD) + followers; shares in basis points totalling 10000
 * unless the arrangement allows partial placement. Premium, claim, commission,
 * reserve and settlement amounts are apportioned by share (optionally overridden
 * per basis), integer minor units, rounding residual to the lead. Co-insurance is
 * never reinsurance: nothing here reads or writes reinsurance/cession tables.
 *
 * Read-only against policies: an arrangement may reference a policy of the same
 * tenant; policy issuance/claims code calls activeForPolicy() + apportion() when wired.
 */
final class CoinsuranceService
{
    public const ROLES = ['LEAD', 'FOLLOWER'];

    public const BASES = ['PREMIUM', 'CLAIM', 'COMMISSION', 'RESERVE', 'SETTLEMENT'];

    public const LEAD_RIGHTS = ['POLICY_ADMINISTRATION', 'DOCUMENT_ISSUANCE', 'PREMIUM_COLLECTION', 'CLAIMS_HANDLING', 'CLAIMS_SETTLEMENT_AUTHORITY', 'SETTLEMENT_ADMINISTRATION'];

    public const FULL_BPS = 10000;

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /** @param array{reference:string,policy_id?:?string,currency?:string,allow_partial_placement?:bool,lead_rights?:array,effective_from:string,effective_until?:?string,participants:array} $data */
    public function create(string $tenantId, array $data, User $actor): array
    {
        $participants = $this->normaliseParticipants($data['participants'] ?? []);
        $partial = (bool) ($data['allow_partial_placement'] ?? false);
        $this->validateShares($participants, $partial);
        $rights = array_values(array_unique(array_map('strtoupper', $data['lead_rights'] ?? ['POLICY_ADMINISTRATION', 'DOCUMENT_ISSUANCE', 'CLAIMS_HANDLING'])));
        if ($bad = array_diff($rights, self::LEAD_RIGHTS)) {
            throw ValidationException::withMessages(['lead_rights' => 'Unknown lead rights: '.implode(', ', $bad)]);
        }
        if (! empty($data['policy_id']) && ! DB::table('policies')->where('id', $data['policy_id'])->where('tenant_id', $tenantId)->exists()) {
            throw ValidationException::withMessages(['policy_id' => 'Policy not found in this tenant.']);
        }
        $carrierIds = array_column($participants, 'carrier_id');
        if (DB::table('carriers')->whereIn('id', $carrierIds)->count() !== count($carrierIds)) {
            throw ValidationException::withMessages(['participants' => 'Every participant must be a registered carrier.']);
        }
        if (DB::table('coinsurance_arrangements')->where('tenant_id', $tenantId)->where('reference', $data['reference'])->exists()) {
            throw ValidationException::withMessages(['reference' => 'Reference already used.']);
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $tenantId, $data, $participants, $partial, $rights, $actor) {
            DB::table('coinsurance_arrangements')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'policy_id' => $data['policy_id'] ?? null, 'reference' => $data['reference'],
                'currency' => $data['currency'] ?? 'XAF', 'status' => 'DRAFT', 'allow_partial_placement' => $partial,
                'lead_rights' => json_encode($rights), 'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null,
                'created_by' => $actor->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($participants as $p) {
                DB::table('coinsurance_participants')->insert([
                    'id' => (string) Str::uuid(), 'arrangement_id' => $id, 'carrier_id' => $p['carrier_id'], 'role' => $p['role'],
                    'share_bps' => $p['share_bps'], 'share_overrides_bps' => json_encode((object) $p['share_overrides_bps']),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit->record('coinsurance.arrangement.created', 'coinsurance_arrangement', $id, ['reference' => $data['reference'], 'participants' => $participants]);
            $this->outbox->record('coinsurance.arrangement.created', 'coinsurance_arrangement', $id, ['tenant_id' => $tenantId, 'reference' => $data['reference']]);
        });

        return $this->show($tenantId, $id);
    }

    /** Maker-checker: the creator cannot activate; one ACTIVE arrangement per policy. */
    public function activate(string $tenantId, string $id, User $actor): array
    {
        DB::transaction(function () use ($tenantId, $id, $actor) {
            $a = $this->lockedArrangement($tenantId, $id);
            if ($a->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => "Only DRAFT arrangements can be activated (is {$a->status})."]);
            }
            if ($a->created_by === $actor->id) {
                throw ValidationException::withMessages(['activated_by' => 'Maker-checker: the creator cannot activate the arrangement.']);
            }
            if ($a->policy_id && DB::table('coinsurance_arrangements')->where('policy_id', $a->policy_id)->where('status', 'ACTIVE')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['policy_id' => 'The policy already has an active co-insurance arrangement.']);
            }
            DB::table('coinsurance_arrangements')->where('id', $id)->update(['status' => 'ACTIVE', 'activated_by' => $actor->id, 'activated_at' => now(), 'version' => $a->version + 1, 'updated_at' => now()]);
            $this->audit->record('coinsurance.arrangement.activated', 'coinsurance_arrangement', $id, ['reference' => $a->reference], null, ['old' => ['status' => 'DRAFT'], 'new' => ['status' => 'ACTIVE']]);
            $this->outbox->record('coinsurance.arrangement.activated', 'coinsurance_arrangement', $id, ['tenant_id' => $tenantId, 'policy_id' => $a->policy_id]);
        });

        return $this->show($tenantId, $id);
    }

    public function terminate(string $tenantId, string $id, string $reason, User $actor): array
    {
        DB::transaction(function () use ($tenantId, $id, $reason) {
            $a = $this->lockedArrangement($tenantId, $id);
            if ($a->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['status' => "Only ACTIVE arrangements can be terminated (is {$a->status})."]);
            }
            DB::table('coinsurance_arrangements')->where('id', $id)->update(['status' => 'TERMINATED', 'terminated_at' => now(), 'version' => $a->version + 1, 'updated_at' => now()]);
            $this->audit->record('coinsurance.arrangement.terminated', 'coinsurance_arrangement', $id, ['reference' => $a->reference], $reason, ['old' => ['status' => 'ACTIVE'], 'new' => ['status' => 'TERMINATED']]);
            $this->outbox->record('coinsurance.arrangement.terminated', 'coinsurance_arrangement', $id, ['tenant_id' => $tenantId, 'reason' => $reason]);
        });

        return $this->show($tenantId, $id);
    }

    /** For later wiring by policy/claims/finance code. */
    public function activeForPolicy(string $tenantId, string $policyId): ?array
    {
        $id = DB::table('coinsurance_arrangements')->where('tenant_id', $tenantId)->where('policy_id', $policyId)->where('status', 'ACTIVE')->value('id');

        return $id ? $this->show($tenantId, $id) : null;
    }

    /**
     * Pure share computation (no persistence). Integer minor units; each participant gets
     * floor(|total| × share / 10000) with the sign of total; under full placement the
     * rounding residual goes to the lead so lines sum exactly to total. Under partial
     * placement the unplaced portion is returned as unplaced_minor.
     *
     * @return array{basis:string,total_minor:int,lines:list<array{carrier_id:string,role:string,share_bps:int,amount_minor:int}>,unplaced_minor:int}
     */
    public function compute(array $arrangement, string $basis, int $totalMinor): array
    {
        $basis = strtoupper($basis);
        if (! in_array($basis, self::BASES, true)) {
            throw ValidationException::withMessages(['basis' => 'Unknown apportionment basis.']);
        }
        $sign = $totalMinor < 0 ? -1 : 1;
        $abs = abs($totalMinor);
        $lines = [];
        $sumBps = 0;
        $allocated = 0;
        foreach ($arrangement['participants'] as $p) {
            $bps = (int) ($p['share_overrides_bps'][$basis] ?? $p['share_bps']);
            $amount = intdiv($abs * $bps, self::FULL_BPS);
            $sumBps += $bps;
            $allocated += $amount;
            $lines[] = ['carrier_id' => $p['carrier_id'], 'role' => $p['role'], 'share_bps' => $bps, 'amount_minor' => $amount];
        }
        $placedAbs = intdiv($abs * $sumBps, self::FULL_BPS);
        if ($sumBps === self::FULL_BPS) {
            $placedAbs = $abs;
        }
        $residual = $placedAbs - $allocated;
        foreach ($lines as &$l) {
            if ($l['role'] === 'LEAD') {
                $l['amount_minor'] += $residual;
            }
            $l['amount_minor'] *= $sign;
        }
        unset($l);

        return ['basis' => $basis, 'total_minor' => $totalMinor, 'lines' => $lines, 'unplaced_minor' => $sign * ($abs - $placedAbs)];
    }

    /** Persisted, idempotent apportionment of an amount against an ACTIVE arrangement. */
    public function apportion(string $tenantId, string $id, string $basis, int $totalMinor, string $sourceType, ?string $sourceId, string $idempotencyKey, ?User $actor = null): array
    {
        $existing = DB::table('coinsurance_apportionments')->where('tenant_id', $tenantId)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ($existing->arrangement_id !== $id || $existing->basis !== strtoupper($basis) || (int) $existing->total_minor !== $totalMinor) {
                throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key reused with a different request.']);
            }

            return $this->apportionmentRow($existing, true);
        }
        $arrangement = $this->show($tenantId, $id);
        if ($arrangement['status'] !== 'ACTIVE') {
            throw ValidationException::withMessages(['status' => 'Amounts can only be apportioned on an ACTIVE arrangement.']);
        }
        $result = $this->compute($arrangement, $basis, $totalMinor);
        $rowId = (string) Str::uuid();
        DB::transaction(function () use ($rowId, $tenantId, $arrangement, $result, $sourceType, $sourceId, $idempotencyKey, $actor) {
            DB::table('coinsurance_apportionments')->insert([
                'id' => $rowId, 'tenant_id' => $tenantId, 'arrangement_id' => $arrangement['id'], 'arrangement_version' => $arrangement['version'],
                'basis' => $result['basis'], 'source_type' => $sourceType, 'source_id' => $sourceId, 'total_minor' => $result['total_minor'],
                'currency' => $arrangement['currency'], 'lines' => json_encode(['lines' => $result['lines'], 'unplaced_minor' => $result['unplaced_minor']]),
                'idempotency_key' => $idempotencyKey, 'recorded_by' => $actor?->id, 'created_at' => now(),
            ]);
            $this->audit->record('coinsurance.apportioned', 'coinsurance_arrangement', $arrangement['id'], ['apportionment_id' => $rowId, 'basis' => $result['basis'], 'total_minor' => $result['total_minor'], 'source_type' => $sourceType, 'source_id' => $sourceId]);
            $this->outbox->record('coinsurance.apportioned', 'coinsurance_arrangement', $arrangement['id'], ['tenant_id' => $tenantId, 'apportionment_id' => $rowId] + $result);
        });

        return $this->apportionmentRow(DB::table('coinsurance_apportionments')->where('id', $rowId)->first(), false);
    }

    public function show(string $tenantId, string $id): array
    {
        $a = DB::table('coinsurance_arrangements')->where('tenant_id', $tenantId)->where('id', $id)->first();
        if (! $a) {
            abort(404);
        }
        $participants = DB::table('coinsurance_participants')->where('arrangement_id', $id)->orderByRaw("role = 'LEAD' DESC")->orderByDesc('share_bps')->get()
            ->map(fn ($p) => ['id' => $p->id, 'carrier_id' => $p->carrier_id, 'role' => $p->role, 'master_data_role' => ReinsuranceReference::coinsuranceRole($p->role), 'share_bps' => (int) $p->share_bps,
                'share_overrides_bps' => (array) json_decode($p->share_overrides_bps, true)])->all();

        return ['id' => $a->id, 'kind' => 'COINSURANCE', 'reference' => $a->reference, 'policy_id' => $a->policy_id, 'currency' => $a->currency, 'status' => $a->status,
            'allow_partial_placement' => (bool) $a->allow_partial_placement, 'lead_rights' => json_decode($a->lead_rights, true),
            'effective_from' => $a->effective_from, 'effective_until' => $a->effective_until, 'created_by' => $a->created_by, 'activated_by' => $a->activated_by,
            'version' => (int) $a->version, 'placed_bps' => array_sum(array_column($participants, 'share_bps')), 'participants' => $participants];
    }

    public function list(string $tenantId, ?string $policyId = null): array
    {
        return DB::table('coinsurance_arrangements')->where('tenant_id', $tenantId)->when($policyId, fn ($q) => $q->where('policy_id', $policyId))
            ->orderByDesc('created_at')->limit(200)->pluck('id')->map(fn ($id) => $this->show($tenantId, $id))->all();
    }

    public function apportionments(string $tenantId, string $id): array
    {
        $this->show($tenantId, $id);

        return DB::table('coinsurance_apportionments')->where('tenant_id', $tenantId)->where('arrangement_id', $id)->orderBy('created_at')->get()
            ->map(fn ($r) => $this->apportionmentRow($r, false))->all();
    }

    private function apportionmentRow(object $r, bool $replayed): array
    {
        $l = json_decode($r->lines, true);

        return ['id' => $r->id, 'arrangement_id' => $r->arrangement_id, 'arrangement_version' => (int) $r->arrangement_version, 'basis' => $r->basis,
            'source_type' => $r->source_type, 'source_id' => $r->source_id, 'total_minor' => (int) $r->total_minor, 'currency' => $r->currency,
            'lines' => $l['lines'], 'unplaced_minor' => (int) $l['unplaced_minor'], 'idempotency_key' => $r->idempotency_key, 'replayed' => $replayed];
    }

    private function lockedArrangement(string $tenantId, string $id): object
    {
        return DB::table('coinsurance_arrangements')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
    }

    private function normaliseParticipants(array $raw): array
    {
        $out = [];
        foreach ($raw as $p) {
            $role = ReinsuranceReference::coinsuranceRole((string) ($p['role'] ?? '')) ?? strtoupper((string) ($p['role'] ?? ''));
            $overrides = [];
            foreach (($p['share_overrides_bps'] ?? []) as $basis => $bps) {
                $basis = strtoupper((string) $basis);
                if (! in_array($basis, self::BASES, true) || ! is_int($bps) || $bps < 0 || $bps > self::FULL_BPS) {
                    throw ValidationException::withMessages(['participants' => "Invalid share override {$basis}."]);
                }
                $overrides[$basis] = $bps;
            }
            $out[] = ['carrier_id' => (string) ($p['carrier_id'] ?? ''), 'role' => $role, 'share_bps' => (int) ($p['share_bps'] ?? 0), 'share_overrides_bps' => $overrides];
        }

        return $out;
    }

    private function validateShares(array $participants, bool $partial): void
    {
        if (count($participants) < 2) {
            throw ValidationException::withMessages(['participants' => 'Co-insurance needs a lead insurer and at least one co-insurer.']);
        }
        $roles = array_count_values(array_column($participants, 'role'));
        if (($roles['LEAD'] ?? 0) !== 1 || count($roles) > 2 || ! isset($roles['FOLLOWER'])) {
            throw ValidationException::withMessages(['participants' => 'Exactly one LEAD (apériteur) and one or more FOLLOWER participants are required.']);
        }
        $carriers = array_column($participants, 'carrier_id');
        if (count(array_unique($carriers)) !== count($carriers)) {
            throw ValidationException::withMessages(['participants' => 'A carrier can participate only once.']);
        }
        foreach ($participants as $p) {
            if ($p['share_bps'] <= 0 || $p['share_bps'] > self::FULL_BPS) {
                throw ValidationException::withMessages(['participants' => 'Each share must be between 1 and 10000 basis points.']);
            }
        }
        foreach (array_merge([null], self::BASES) as $basis) {
            $sum = array_sum(array_map(fn ($p) => $basis === null ? $p['share_bps'] : ($p['share_overrides_bps'][$basis] ?? $p['share_bps']), $participants));
            if ($partial ? $sum > self::FULL_BPS : $sum !== self::FULL_BPS) {
                $label = $basis ?? 'participation';
                throw ValidationException::withMessages(['participants' => $partial
                    ? "{$label} shares exceed 100% ({$sum} bps)."
                    : "{$label} shares must total exactly 100% (10000 bps), got {$sum}."]);
            }
        }
    }
}
