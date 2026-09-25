<?php

declare(strict_types=1);

namespace App\Application\Claims\Parties\Http;

use App\Application\Claims\Parties\ClaimPartyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimInvolvedParty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-CLM-006 — staff API over claim parties: list / add / update / remove (dated, audited). */
final class ClaimPartyController
{
    public function __construct(private readonly ClaimPartyService $service, private readonly TenantContext $tenant) {}

    public function index(Request $r, string $claim): JsonResponse
    {
        return response()->json(['data' => $this->service->list($this->claim($claim), $r->boolean('include_removed'))]);
    }

    public function store(Request $r, string $claim): JsonResponse
    {
        $d = $r->validate(array_merge($this->rules(), [
            'role' => ['required', Rule::in(ClaimPartyService::ROLES)],
            'display_name' => 'required_without_all:party_id,partner_id|nullable|string|max:255',
            'party_id' => 'nullable|uuid',
            'partner_id' => 'nullable|uuid',
            'link_party' => 'sometimes|boolean',
            'party_type' => 'sometimes|in:INDIVIDUAL,ORGANIZATION',
            'date_of_birth' => 'nullable|date|before:today',
            'registration_number' => 'nullable|string|max:64',
        ]));

        return response()->json(['data' => $this->service->add($this->claim($claim), $d, $r->user())], 201);
    }

    public function update(Request $r, string $claim, string $party): JsonResponse
    {
        $d = $r->validate(array_merge($this->rules(), [
            'role' => ['sometimes', Rule::in(ClaimPartyService::ROLES)],
            'display_name' => 'sometimes|string|max:255',
            'reason' => 'required|string|min:5|max:500',
        ]));

        return response()->json(['data' => $this->service->update($this->row($claim, $party), $d, $r->user())]);
    }

    public function destroy(Request $r, string $claim, string $party): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:500', 'effective_to' => 'nullable|date']);

        return response()->json(['data' => $this->service->remove($this->row($claim, $party), $d['reason'], $r->user(), $d['effective_to'] ?? null)]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'contact_phone' => 'nullable|string|max:32',
            'contact_email' => 'nullable|email|max:255',
            'consent_given' => 'sometimes|boolean',
            'consent_basis' => ['nullable', Rule::in(ClaimPartyService::CONSENT_BASES)],
            'notes' => 'nullable|string|max:2000',
            'bank_name' => 'nullable|string|max:128',
            'bank_account_holder' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|min:6|max:64',
            'effective_from' => 'nullable|date',
        ];
    }

    private function claim(string $id): Claim
    {
        return Claim::where(['id' => $id, 'tenant_id' => $this->tenant->id()])->firstOrFail();
    }

    private function row(string $claim, string $party): ClaimInvolvedParty
    {
        return ClaimInvolvedParty::where(['id' => $party, 'claim_id' => $this->claim($claim)->id])->firstOrFail();
    }
}
