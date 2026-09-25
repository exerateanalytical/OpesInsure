<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Customers;

use App\Application\Customers\Matching\PartyMatcher;
use App\Application\Customers\Matching\PartyMergeService;
use App\Application\Customers\Relationships\PartyRelationshipService;
use App\Application\Customers\Roles\PartyRoleService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Parties\EntityMatchCandidate;
use App\Models\Parties\OwnershipInterest;
use App\Models\Parties\PartyMerge;
use App\Models\Parties\PartyRelationship;
use App\Models\Parties\PartyRole;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Batch 4A — REQ-PTY-002 roles, REQ-PTY-003 relationships/UBO graph, REQ-PTY-004 matching + maker-checker merge. */
final class PartyGoldenRecordController
{
    public function __construct(
        private readonly PartyRoleService $roles,
        private readonly PartyRelationshipService $links,
        private readonly PartyMatcher $matcher,
        private readonly PartyMergeService $merges,
    ) {}

    // ---- REQ-CRM-002 Customer 360 (scoped by DataScopeResolver)
    public function overview(Request $r, string $party, \App\Application\Customers\Customer360\Customer360Service $c360): JsonResponse
    {
        $data = $c360->overview(Party::findOrFail($party), $r->user());
        abort_if($data === null, 404);

        return response()->json(['data' => $data]);
    }

    // ---- REQ-PTY-002
    public function roleCatalogue(): JsonResponse
    {
        return response()->json(['data' => $this->roles->catalogue()]);
    }

    public function roles(Request $r, string $party): JsonResponse
    {
        $p = $this->party($r, $party);
        $d = $r->validate(['valid_at' => 'nullable|date', 'known_at' => 'nullable|date']);

        return response()->json(['data' => $this->roles->asOf($p->id, $d['valid_at'] ?? null, $d['known_at'] ?? null, $this->tenant())]);
    }

    public function assignRole(Request $r, string $party): JsonResponse
    {
        $p = $this->party($r, $party);
        $d = $r->validate(['role_code' => 'required|string|max:40', 'context_type' => 'nullable|string|max:40', 'context_id' => 'nullable|uuid',
            'valid_from' => 'nullable|date', 'valid_to' => 'nullable|date', 'details' => 'nullable|array']);

        return response()->json(['data' => $this->roles->assign($p, $d, $this->tenant(), $r->user()->id)], 201);
    }

    public function endRole(Request $r, string $role): JsonResponse
    {
        $row = PartyRole::where('tenant_id', $this->tenant())->findOrFail($role);
        $d = $r->validate(['valid_to' => 'required|date', 'reason' => 'required|string|min:3|max:500']);

        return response()->json(['data' => $this->roles->end($row, $d['valid_to'], $d['reason'], $r->user()->id)]);
    }

    public function contextRoles(Request $r): JsonResponse
    {
        $d = $r->validate(['context_type' => 'required|in:'.implode(',', PartyRoleService::CONTEXT_TYPES), 'context_id' => 'required|uuid']);

        return response()->json(['data' => $this->roles->forContext($d['context_type'], $d['context_id'], $this->tenant())]);
    }

    // ---- REQ-PTY-003
    public function relationships(Request $r, string $party): JsonResponse
    {
        $p = $this->party($r, $party);

        return response()->json(['data' => PartyRelationship::where(fn ($q) => $q->where('from_party_id', $p->id)->orWhere('to_party_id', $p->id))
            ->orderByDesc('created_at')->get()]);
    }

    public function link(Request $r, string $party): JsonResponse
    {
        $p = $this->party($r, $party);
        $d = $r->validate(['to_party_id' => 'required|uuid', 'type' => 'required|string|max:40', 'valid_from' => 'nullable|date', 'valid_to' => 'nullable|date', 'details' => 'nullable|array']);

        return response()->json(['data' => $this->links->link($p, $this->party($r, $d['to_party_id']), $d, $this->tenant(), $r->user()->id)], 201);
    }

    public function endLink(Request $r, string $relationship): JsonResponse
    {
        $rel = PartyRelationship::findOrFail($relationship);
        $this->party($r, $rel->from_party_id);
        $d = $r->validate(['reason' => 'required|string|min:3|max:500', 'valid_to' => 'nullable|date']);

        return response()->json(['data' => $this->links->endRelationship($rel, $d['reason'], $d['valid_to'] ?? null)]);
    }

    public function ownership(Request $r, string $party): JsonResponse
    {
        $p = $this->party($r, $party);

        return response()->json(['data' => [
            'owners' => OwnershipInterest::where('owned_party_id', $p->id)->orderByDesc('percentage')->get(),
            'holdings' => OwnershipInterest::where('owner_party_id', $p->id)->orderByDesc('percentage')->get(),
        ]]);
    }

    public function addOwnership(Request $r, string $party): JsonResponse
    {
        $owned = $this->party($r, $party);
        $d = $r->validate(['owner_party_id' => 'required|uuid', 'percentage' => 'required|numeric|gt:0|lte:100',
            'interest_type' => 'nullable|in:'.implode(',', PartyRelationshipService::INTEREST_TYPES), 'valid_from' => 'nullable|date', 'evidence_reference' => 'nullable|string|max:255']);

        return response()->json(['data' => $this->links->addOwnership($this->party($r, $d['owner_party_id']), $owned, $d, $this->tenant(), $r->user()->id)], 201);
    }

    public function endOwnership(Request $r, string $interest): JsonResponse
    {
        $oi = OwnershipInterest::findOrFail($interest);
        $this->party($r, $oi->owned_party_id);
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);

        return response()->json(['data' => $this->links->endOwnership($oi, $d['reason'])]);
    }

    public function ubo(Request $r, string $party): JsonResponse
    {
        $p = $this->party($r, $party);
        $d = $r->validate(['threshold' => 'nullable|numeric|gt:0|lte:100', 'interest_type' => 'nullable|in:'.implode(',', PartyRelationshipService::INTEREST_TYPES)]);
        abort_unless($p->type === 'ORGANIZATION', 422, 'UBO applies to organizations.');

        return response()->json(['data' => $this->links->ultimateBeneficialOwners($p, isset($d['threshold']) ? (float) $d['threshold'] : null, $d['interest_type'] ?? 'SHAREHOLDING')]);
    }

    public function graph(Request $r, string $party): JsonResponse
    {
        $p = $this->party($r, $party);

        return response()->json(['data' => $this->links->graph($p, (int) $r->integer('depth', 2))]);
    }

    // ---- REQ-PTY-004
    public function scan(Request $r, string $party): JsonResponse
    {
        $p = $this->party($r, $party);

        return response()->json(['data' => $this->matcher->scan($p, $this->tenant(), $r->user())]);
    }

    public function candidates(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => 'nullable|in:OPEN,DISMISSED,MERGE_REQUESTED,MERGED', 'band' => 'nullable|in:HIGH,MEDIUM,LOW']);
        $q = EntityMatchCandidate::where('status', $d['status'] ?? 'OPEN')->when($d['band'] ?? null, fn ($q, $b) => $q->where('band', $b));
        if (! $this->global($r)) {
            $visible = fn ($col) => fn ($x) => $x->whereIn($col, \App\Models\TenantCustomer::where('tenant_id', $this->tenant())->select('party_id'));
            $q->where($visible('party_a_id'))->where($visible('party_b_id'));
        }
        $page = $q->orderByDesc('score')->paginate(min($r->integer('per_page', 25), 100));
        $names = Party::whereIn('id', $page->getCollection()->flatMap(fn ($c) => [$c->party_a_id, $c->party_b_id])->unique())->pluck('display_name', 'id');
        $page->getCollection()->each(fn ($c) => $c->setAttribute('party_a_name', $names[$c->party_a_id] ?? null)->setAttribute('party_b_name', $names[$c->party_b_id] ?? null));

        return response()->json(['data' => $page]);
    }

    public function dismiss(Request $r, string $candidate): JsonResponse
    {
        $c = EntityMatchCandidate::findOrFail($candidate);
        $this->party($r, $c->party_a_id);
        $this->party($r, $c->party_b_id);
        $d = $r->validate(['note' => 'required|string|min:3|max:500']);

        return response()->json(['data' => $this->matcher->dismiss($c, $r->user()->id, $d['note'])]);
    }

    public function requestMerge(Request $r): JsonResponse
    {
        $d = $r->validate(['survivor_party_id' => 'required|uuid', 'merged_party_id' => 'required|uuid|different:survivor_party_id', 'candidate_id' => 'nullable|uuid',
            'survivorship' => 'nullable|array', 'survivorship.*' => 'in:SURVIVOR,MERGED', 'reason' => 'nullable|string|max:500']);
        $candidate = isset($d['candidate_id']) ? EntityMatchCandidate::findOrFail($d['candidate_id']) : null;
        $m = $this->merges->request($this->party($r, $d['survivor_party_id']), $this->party($r, $d['merged_party_id']), $r->user(),
            $d['survivorship'] ?? [], $d['reason'] ?? null, $candidate);

        return response()->json(['data' => $m], 201);
    }

    public function showMerge(Request $r, string $merge): JsonResponse
    {
        $m = PartyMerge::findOrFail($merge);
        $this->party($r, $m->merged_party_id);

        return response()->json(['data' => $m]);
    }

    public function decideMerge(Request $r, string $merge): JsonResponse
    {
        $m = PartyMerge::findOrFail($merge);
        $this->party($r, $m->merged_party_id);
        $d = $r->validate(['decision' => 'required|in:APPROVED,REJECTED', 'note' => 'nullable|string|max:500|required_if:decision,REJECTED']);
        $m = $d['decision'] === 'APPROVED' ? $this->merges->approveMerge($m, $r->user(), $d['note'] ?? null) : $this->merges->rejectMerge($m, $r->user(), $d['note']);

        return response()->json(['data' => $m]);
    }

    public function unmerge(Request $r, string $merge): JsonResponse
    {
        $m = PartyMerge::findOrFail($merge);
        $this->party($r, $m->merged_party_id);
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);

        return response()->json(['data' => $this->merges->unmerge($m, $r->user(), $d['reason'])]);
    }

    /** Same visibility as PartyController: platform/compliance admins see every party, others their tenant's customers. */
    private function party(Request $r, string $id): Party
    {
        $p = Party::findOrFail($id);
        abort_unless($this->global($r) || $p->customers()->where('tenant_id', $this->tenant())->exists(), 404);

        return $p;
    }

    private function global(Request $r): bool
    {
        return $r->user()->memberships()->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'])->exists();
    }

    private function tenant(): ?string
    {
        return app(TenantContext::class)->id();
    }
}
