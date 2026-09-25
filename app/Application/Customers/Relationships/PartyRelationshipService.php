<?php

declare(strict_types=1);

namespace App\Application\Customers\Relationships;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\Party;
use App\Models\Parties\OwnershipInterest;
use App\Models\Parties\PartyRelationship;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PTY-003 — party relationship graph (household, employer, group, corporate) and ownership interests for
 * ultimate beneficial ownership (ICE E5; feeds corporate KYC REQ-KYC-002). Ending a link keeps the row (status ENDED).
 */
final class PartyRelationshipService
{
    /** type => [label, symmetric, from party type (null = any), to party type (null = any)] */
    public const TYPES = [
        'HOUSEHOLD_MEMBER' => ['Household member', true, 'PERSON', 'PERSON'],
        'SPOUSE' => ['Spouse', true, 'PERSON', 'PERSON'],
        'PARENT_OF' => ['Parent of', false, 'PERSON', 'PERSON'],
        'LEGAL_GUARDIAN_OF' => ['Legal guardian of', false, 'PERSON', 'PERSON'],
        'EMPLOYEE_OF' => ['Employee of', false, 'PERSON', 'ORGANIZATION'],
        'DIRECTOR_OF' => ['Director / officer of', false, 'PERSON', 'ORGANIZATION'],
        'AUTHORIZED_SIGNATORY_OF' => ['Authorized signatory of', false, 'PERSON', 'ORGANIZATION'],
        'GROUP_MEMBER_OF' => ['Member of group / association', false, null, 'ORGANIZATION'],
        'SUBSIDIARY_OF' => ['Subsidiary of', false, 'ORGANIZATION', 'ORGANIZATION'],
        'BRANCH_OF' => ['Branch of', false, 'ORGANIZATION', 'ORGANIZATION'],
        // Owner decision 26 (2026-09-25): legal-arrangement / control roles that make a beneficial owner whatever
        // the percentage held. The "to" party is the organization / legal arrangement (trust, fiducie).
        'SETTLOR_OF' => ['Settlor of', false, null, 'ORGANIZATION'],
        'TRUSTEE_OF' => ['Trustee of', false, null, 'ORGANIZATION'],
        'PROTECTOR_OF' => ['Protector of', false, null, 'ORGANIZATION'],
        'BENEFICIARY_OF' => ['Beneficiary of (legal arrangement)', false, null, 'ORGANIZATION'],
        'CONTROLLING_PERSON_OF' => ['Other controlling person of', false, null, 'ORGANIZATION'],
    ];

    /** Relationship types that make the "from" party a beneficial owner of the "to" organization (decision 26). */
    public const CONTROLLING_ROLES = ['SETTLOR_OF' => 'SETTLOR', 'TRUSTEE_OF' => 'TRUSTEE', 'PROTECTOR_OF' => 'PROTECTOR',
        'BENEFICIARY_OF' => 'BENEFICIARY', 'CONTROLLING_PERSON_OF' => 'OTHER_CONTROLLING_PERSON'];

    public const INTEREST_TYPES = ['SHAREHOLDING', 'VOTING', 'CONTROL'];

    /** Percentage bases for beneficial ownership: capital (SHAREHOLDING) or voting rights (VOTING). CONTROL = other means. */
    public const OWNERSHIP_BASES = ['SHAREHOLDING' => 'CAPITAL', 'VOTING' => 'VOTING_RIGHTS'];

    /**
     * UBO threshold: owner decision 26 (2026-09-25) — an interest strictly greater than 25% (25% itself does not
     * qualify). Configurable (config parties.ubo_threshold_percent). threshold_verified stays false: the value is
     * the owner's decision; no CEMAC/CIMA legal text in the repository has been checked against it.
     */
    public const DEFAULT_UBO_THRESHOLD = 25.0;

    private const MAX_DEPTH = 10;

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /** @param array{type: string, valid_from?: ?string, valid_to?: ?string, details?: array} $d */
    public function link(Party $from, Party $to, array $d, ?string $tenantId, ?string $actorId): PartyRelationship
    {
        $type = strtoupper((string) $d['type']);
        $def = self::TYPES[$type] ?? throw ValidationException::withMessages(['type' => 'Unknown relationship type.']);
        $this->assertLinkable($from, $to);
        if (($def[2] && $from->type !== $def[2]) || ($def[3] && $to->type !== $def[3])) {
            throw ValidationException::withMessages(['type' => "{$type} links a {$def[2]} to a {$def[3]}."]);
        }
        [$a, $b] = $def[1] && strcmp($from->id, $to->id) > 0 ? [$to, $from] : [$from, $to];   // symmetric: one row
        if (PartyRelationship::where(['from_party_id' => $a->id, 'to_party_id' => $b->id, 'type' => $type, 'status' => 'ACTIVE'])->exists()) {
            throw ValidationException::withMessages(['type' => 'This relationship already exists.']);
        }

        return DB::transaction(function () use ($a, $b, $type, $d, $tenantId, $actorId) {
            $rel = PartyRelationship::create(['tenant_id' => $tenantId, 'from_party_id' => $a->id, 'to_party_id' => $b->id, 'type' => $type,
                'valid_from' => $d['valid_from'] ?? now()->toDateString(), 'valid_to' => $d['valid_to'] ?? null, 'status' => 'ACTIVE',
                'details' => $d['details'] ?? [], 'created_by' => $actorId]);
            $this->audit->record('party.relationship_added', 'party', $a->id, ['relationship_id' => $rel->id, 'type' => $type, 'to_party_id' => $b->id]);
            $this->outbox->record('party.relationship_added', 'party', $a->id, ['relationship_id' => $rel->id, 'type' => $type, 'from_party_id' => $a->id, 'to_party_id' => $b->id]);

            return $rel;
        });
    }

    public function endRelationship(PartyRelationship $rel, string $reason, ?string $validTo = null): PartyRelationship
    {
        if ($rel->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['relationship' => 'This relationship already ended.']);
        }
        $rel->update(['status' => 'ENDED', 'valid_to' => $validTo ?? now()->toDateString()]);
        $this->audit->record('party.relationship_ended', 'party', $rel->from_party_id, ['relationship_id' => $rel->id, 'type' => $rel->type], $reason);

        return $rel->refresh();
    }

    /** @param array{percentage: float|string, interest_type?: string, valid_from?: ?string, evidence_reference?: ?string} $d */
    public function addOwnership(Party $owner, Party $owned, array $d, ?string $tenantId, ?string $actorId): OwnershipInterest
    {
        $this->assertLinkable($owner, $owned);
        if ($owned->type !== 'ORGANIZATION') {
            throw ValidationException::withMessages(['owned_party_id' => 'Only an organization can be owned.']);
        }
        $pct = (float) $d['percentage'];
        $kind = strtoupper($d['interest_type'] ?? 'SHAREHOLDING');
        if ($pct <= 0 || $pct > 100 || ! in_array($kind, self::INTEREST_TYPES, true)) {
            throw ValidationException::withMessages(['percentage' => 'Percentage must be > 0 and ≤ 100, with a known interest type.']);
        }

        return DB::transaction(function () use ($owner, $owned, $pct, $kind, $d, $tenantId, $actorId) {
            Party::whereKey($owned->id)->lockForUpdate()->first();
            $active = OwnershipInterest::where(['owned_party_id' => $owned->id, 'interest_type' => $kind, 'status' => 'ACTIVE']);
            if ((clone $active)->where('owner_party_id', $owner->id)->exists()) {
                throw ValidationException::withMessages(['owner_party_id' => 'This owner already has an active interest; end it and record the new one.']);
            }
            if ((float) $active->sum('percentage') + $pct > 100.0001) {
                throw ValidationException::withMessages(['percentage' => 'Active interests of this type would exceed 100%.']);
            }
            $oi = OwnershipInterest::create(['tenant_id' => $tenantId, 'owner_party_id' => $owner->id, 'owned_party_id' => $owned->id, 'percentage' => $pct,
                'interest_type' => $kind, 'valid_from' => $d['valid_from'] ?? now()->toDateString(), 'status' => 'ACTIVE',
                'evidence_reference' => $d['evidence_reference'] ?? null, 'created_by' => $actorId]);
            $this->audit->record('party.ownership_added', 'party', $owned->id, ['ownership_id' => $oi->id, 'owner_party_id' => $owner->id, 'percentage' => $pct, 'interest_type' => $kind]);
            $this->outbox->record('party.ownership_added', 'party', $owned->id, ['ownership_id' => $oi->id, 'owner_party_id' => $owner->id, 'owned_party_id' => $owned->id]);

            return $oi;
        });
    }

    public function endOwnership(OwnershipInterest $oi, string $reason): OwnershipInterest
    {
        if ($oi->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['ownership' => 'This interest already ended.']);
        }
        $oi->update(['status' => 'ENDED', 'valid_to' => now()->toDateString()]);
        $this->audit->record('party.ownership_ended', 'party', $oi->owned_party_id, ['ownership_id' => $oi->id], $reason);

        return $oi->refresh();
    }

    /**
     * Beneficial owners (owner decision 26, 2026-09-25): natural persons who
     *   - hold strictly more than the threshold (default 25%) of capital OR of voting rights, directly or
     *     indirectly (sum over every ownership path of the product of percentages; each basis evaluated alone), or
     *   - control by other means (an active CONTROL interest, any percentage, direct or through a legal person), or
     *   - are settlor, trustee, protector, beneficiary or other controlling person (party relationships).
     * A legal person in a control position or role is resolved to its own beneficial owners. Cycles are cut and
     * depth is bounded. Organizations reached with no recorded owner end up in "unresolved".
     *
     * $interestType: null = capital and voting (the decision); SHAREHOLDING / VOTING restrict the percentage basis
     * to one of them; CONTROL = control / roles only. Control and roles are always evaluated.
     *
     * @return array{threshold: float, threshold_rule: string, threshold_basis: string, threshold_verified: false, owners: list<array<string, mixed>>, unresolved: list<string>}
     */
    public function ultimateBeneficialOwners(Party $company, ?float $threshold = null, ?string $interestType = null): array
    {
        $threshold ??= (float) config('parties.ubo_threshold_percent', self::DEFAULT_UBO_THRESHOLD);
        $interestType = $interestType ? strtoupper($interestType) : null;
        $bases = $interestType === null ? array_keys(self::OWNERSHIP_BASES) : array_values(array_intersect([$interestType], array_keys(self::OWNERSHIP_BASES)));
        $found = [];
        $unresolved = [];
        $this->collectOwners($company->id, $threshold, $bases, [$company->id], $found, $unresolved, 0);

        $owners = [];
        foreach ($found as $id => $f) {
            $owners[] = ['party_id' => $id, 'display_name' => (string) Party::whereKey($id)->value('display_name'),
                'effective_percentage' => $f['effective'] ? round(max($f['effective']), 4) : 0.0, 'effective_by_basis' => $f['effective'],
                'grounds' => array_values(array_unique($f['grounds'])), 'roles' => array_values(array_unique($f['roles'])), 'paths' => $f['paths']];
        }
        usort($owners, fn ($a, $b) => [$b['effective_percentage'], $a['display_name']] <=> [$a['effective_percentage'], $b['display_name']]);
        $unresolved = array_values(array_unique(array_diff($unresolved, array_keys($found))));

        return ['threshold' => $threshold, 'threshold_rule' => 'GREATER_THAN', 'threshold_basis' => 'OWNER_DECISION_2026-09-25#26',
            'threshold_verified' => false, 'owners' => $owners, 'unresolved' => $unresolved];
    }

    /** Strictly greater than the threshold (decision 26); rounding noise at exactly the threshold does not qualify. */
    public static function exceedsThreshold(float $pct, float $threshold): bool
    {
        return round($pct, 4) > $threshold + 1e-9;
    }

    /**
     * @param  list<string>  $bases
     * @param  list<string>  $path
     * @param  array<string, array<string, mixed>>  $found
     * @param  list<string>  $unresolved
     */
    private function collectOwners(string $orgId, float $threshold, array $bases, array $path, array &$found, array &$unresolved, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) {
            return;
        }
        // 1. Capital / voting rights: each basis separately, direct + indirect.
        foreach ($bases as $type) {
            $totals = [];
            $paths = [];
            $this->walkOwnership($orgId, $type, 1.0, $path, $totals, $paths, $unresolved);
            foreach ($totals as $pid => $share) {
                if (self::exceedsThreshold($share * 100, $threshold)) {
                    $this->addOwner($found, $pid, self::OWNERSHIP_BASES[$type], $paths[$pid], null, [self::OWNERSHIP_BASES[$type] => round($share * 100, 4)]);
                }
            }
        }
        // 2. Control by other means (CONTROL interests, any percentage).
        foreach (OwnershipInterest::where(['owned_party_id' => $orgId, 'status' => 'ACTIVE', 'interest_type' => 'CONTROL'])->pluck('owner_party_id') as $cid) {
            $this->resolveController((string) $cid, 'CONTROL_OTHER_MEANS', null, $threshold, $bases, $path, $found, $unresolved, $depth);
        }
        // 3. Settlor, trustee, protector, beneficiary, other controlling person.
        $roles = PartyRelationship::where(['to_party_id' => $orgId, 'status' => 'ACTIVE'])->whereIn('type', array_keys(self::CONTROLLING_ROLES))->get(['from_party_id', 'type']);
        foreach ($roles as $r) {
            $this->resolveController($r->from_party_id, 'CONTROLLING_ROLE', self::CONTROLLING_ROLES[$r->type], $threshold, $bases, $path, $found, $unresolved, $depth);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $found
     * @param  list<list<string>>  $paths
     * @param  array<string, float>  $effective
     */
    private function addOwner(array &$found, string $pid, string $ground, array $paths, ?string $role, array $effective = []): void
    {
        $found[$pid] ??= ['effective' => [], 'grounds' => [], 'roles' => [], 'paths' => []];
        $found[$pid]['grounds'][] = $ground;
        if ($role) {
            $found[$pid]['roles'][] = $role;
        }
        foreach ($effective as $basis => $pct) {
            $found[$pid]['effective'][$basis] = max($pct, $found[$pid]['effective'][$basis] ?? 0);
        }
        array_push($found[$pid]['paths'], ...$paths);
    }

    /**
     * @param  list<string>  $bases
     * @param  list<string>  $path
     * @param  array<string, array<string, mixed>>  $found
     * @param  list<string>  $unresolved
     */
    private function resolveController(string $pid, string $ground, ?string $role, float $threshold, array $bases, array $path, array &$found, array &$unresolved, int $depth): void
    {
        $party = in_array($pid, $path, true) ? null : Party::find($pid);
        if (! $party) {
            return;
        }
        if ($party->type === 'PERSON') {
            $this->addOwner($found, $pid, $ground, [[...$path, $pid]], $role);

            return;
        }
        // A legal person in a control position: its own beneficial owners control through it.
        $inner = [];
        $this->collectOwners($pid, $threshold, $bases, [...$path, $pid], $inner, $unresolved, $depth + 1);
        foreach ($inner as $n => $f) {
            $this->addOwner($found, $n, $ground.'_INDIRECT', $f['paths'], $role);
            foreach ($f['grounds'] as $g) {
                $found[$n]['grounds'][] = $g.'_OF_CONTROLLER';
            }
            array_push($found[$n]['roles'], ...$f['roles']);
        }
        if ($inner === []) {
            $unresolved[] = $pid;
        }
    }

    /**
     * @param  list<string>  $path
     * @param  array<string, float>  $totals
     * @param  array<string, list<list<string>>>  $paths
     * @param  list<string>  $unresolved
     */
    private function walkOwnership(string $ownedId, string $type, float $factor, array $path, array &$totals, array &$paths, array &$unresolved): void
    {
        foreach (OwnershipInterest::where(['owned_party_id' => $ownedId, 'status' => 'ACTIVE', 'interest_type' => $type])->get() as $r) {
            if (in_array($r->owner_party_id, $path, true) || count($path) >= self::MAX_DEPTH) {
                continue;
            }
            $eff = $factor * ((float) $r->percentage / 100);
            $owner = Party::find($r->owner_party_id);
            if ($owner?->type === 'PERSON') {
                $totals[$owner->id] = ($totals[$owner->id] ?? 0) + $eff;
                $paths[$owner->id][] = [...$path, $owner->id];
            } elseif ($owner) {
                $hasOwners = OwnershipInterest::where(['owned_party_id' => $owner->id, 'status' => 'ACTIVE', 'interest_type' => $type])->exists();
                $hasOwners ? $this->walkOwnership($owner->id, $type, $eff, [...$path, $owner->id], $totals, $paths, $unresolved) : $unresolved[] = $owner->id;
            }
        }
    }

    /**
     * Breadth-first neighbourhood of a party: relationships + ownership edges, up to $depth hops.
     *
     * @return array{nodes: list<array{id: string, type: string, display_name: string, status: string}>, edges: list<array{id: string, kind: string, type: string, from: string, to: string, percentage?: float}>}
     */
    public function graph(Party $root, int $depth = 2): array
    {
        $depth = max(1, min($depth, 4));
        $seen = [$root->id => true];
        $frontier = [$root->id];
        $edges = [];
        for ($i = 0; $i < $depth && $frontier; $i++) {
            $next = [];
            $rels = PartyRelationship::where('status', 'ACTIVE')->where(fn ($q) => $q->whereIn('from_party_id', $frontier)->orWhereIn('to_party_id', $frontier))->get();
            foreach ($rels as $r) {
                $edges[$r->id] = ['id' => $r->id, 'kind' => 'relationship', 'type' => $r->type, 'from' => $r->from_party_id, 'to' => $r->to_party_id];
                $next[] = $r->from_party_id;
                $next[] = $r->to_party_id;
            }
            $own = OwnershipInterest::where('status', 'ACTIVE')->where(fn ($q) => $q->whereIn('owner_party_id', $frontier)->orWhereIn('owned_party_id', $frontier))->get();
            foreach ($own as $o) {
                $edges[$o->id] = ['id' => $o->id, 'kind' => 'ownership', 'type' => $o->interest_type, 'from' => $o->owner_party_id, 'to' => $o->owned_party_id, 'percentage' => (float) $o->percentage];
                $next[] = $o->owner_party_id;
                $next[] = $o->owned_party_id;
            }
            $frontier = [];
            foreach (array_unique($next) as $id) {
                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $frontier[] = $id;
                }
            }
        }
        $nodes = Party::whereIn('id', array_keys($seen))->get(['id', 'type', 'display_name', 'status'])
            ->map(fn ($p) => ['id' => $p->id, 'type' => $p->type, 'display_name' => $p->display_name, 'status' => $p->status])->values()->all();

        return ['nodes' => $nodes, 'edges' => array_values($edges)];
    }

    private function assertLinkable(Party $a, Party $b): void
    {
        if ($a->id === $b->id) {
            throw ValidationException::withMessages(['to_party_id' => 'A party cannot be linked to itself.']);
        }
        if ($a->merged_into_id || $b->merged_into_id) {
            throw ValidationException::withMessages(['to_party_id' => 'A merged party cannot be linked; use the surviving party.']);
        }
    }
}
