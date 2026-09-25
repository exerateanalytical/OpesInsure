<?php

declare(strict_types=1);

namespace App\Application\Claims\Recovery\Http;

use App\Application\Claims\Recovery\Litigation\LegalMatterService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Agent C15 — REQ-REC-002 litigation matters (LITIGATION case type). */
final class LegalMatterController
{
    public function __construct(private TenantContext $tenant, private LegalMatterService $service) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['claim_id' => 'nullable|uuid', 'status' => 'nullable|in:ACTIVE,CONCLUDED']);

        return response()->json(['data' => DB::table('legal_matters')->where('tenant_id', $this->tenant->id())
            ->when($d['claim_id'] ?? null, fn ($q, $v) => $q->where('claim_id', $v))->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')->limit(200)->get()]);
    }

    public function show(string $matter): JsonResponse
    {
        return response()->json(['data' => $this->service->find($this->tenant->id(), $matter)]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'role' => ['required', Rule::in(LegalMatterService::ROLES)], 'court' => 'required|string|max:255', 'court_reference' => 'nullable|string|max:120',
            'jurisdiction' => 'nullable|string|size:2', 'lawyer_party_id' => 'nullable|uuid|exists:parties,id', 'opposing_party_name' => 'nullable|string|max:255',
            'claim_id' => 'nullable|uuid', 'claim_recovery_id' => 'nullable|uuid', 'claimed_amount_minor' => 'nullable|integer|min:0', 'currency' => 'nullable|string|size:3', 'title' => 'nullable|string|max:255',
        ]);

        return response()->json(['data' => $this->service->open($this->tenant->id(), $d, $r->user())], 201);
    }

    public function hearing(Request $r, string $matter): JsonResponse
    {
        $d = $r->validate(['scheduled_at' => 'required|date', 'location' => 'nullable|string|max:255', 'purpose' => 'nullable|string|max:255']);

        return response()->json(['data' => $this->service->scheduleHearing($this->tenant->id(), $matter, $d, $r->user())], 201);
    }

    public function recordHearing(Request $r, string $hearing): JsonResponse
    {
        $d = $r->validate(['status' => ['required', Rule::in(LegalMatterService::HEARING_RESULTS)], 'result' => 'nullable|string|max:4000']);

        return response()->json(['data' => $this->service->recordHearing($this->tenant->id(), $hearing, $d['status'], $d['result'] ?? null, $r->user())]);
    }

    public function deadline(Request $r, string $matter): JsonResponse
    {
        $d = $r->validate(['description' => 'required|string|max:255', 'due_at' => 'required|date']);

        return response()->json(['data' => $this->service->addDeadline($this->tenant->id(), $matter, $d['description'], $d['due_at'], $r->user())], 201);
    }

    public function completeDeadline(Request $r, string $deadline): JsonResponse
    {
        return response()->json(['data' => $this->service->completeDeadline($this->tenant->id(), $deadline, $r->user())]);
    }

    public function cost(Request $r, string $matter): JsonResponse
    {
        $d = $r->validate([
            'cost_type' => ['required', Rule::in(LegalMatterService::COST_TYPES)], 'amount_minor' => 'required|integer|min:1', 'currency' => 'nullable|string|size:3',
            'payee_party_id' => 'nullable|uuid|exists:parties,id', 'description' => 'nullable|string|max:255', 'incurred_on' => 'nullable|date', 'due_at' => 'nullable|date',
        ]);

        return response()->json(['data' => $this->service->addCost($this->tenant->id(), $matter, $d, $r->user())], 201);
    }

    public function outcome(Request $r, string $matter): JsonResponse
    {
        $d = $r->validate(['outcome' => ['required', Rule::in(LegalMatterService::OUTCOMES)], 'amount_minor' => 'nullable|integer|min:0', 'notes' => 'nullable|string|max:4000']);

        return response()->json(['data' => $this->service->recordOutcome($this->tenant->id(), $matter, $d['outcome'], $d['amount_minor'] ?? null, $d['notes'] ?? null, $r->user())]);
    }
}
