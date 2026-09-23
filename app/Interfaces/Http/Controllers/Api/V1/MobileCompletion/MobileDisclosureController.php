<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Underwriting\ProposalService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Proposal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's quote/questions and quote/terms screens speak a
 * "disclosure session" contract (GET /proposals/{id}/disclosure,
 * PUT .../disclosure/answers, POST .../disclosure/submit, POST .../terms).
 * This adapts that onto ProposalService's answer/attest/submit so the
 * underlying workflow, history and referral logic stay the platform's.
 */
final class MobileDisclosureController
{
    public function session(string $proposal, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->owned($proposal, $request))]);
    }

    public function answers(string $proposal, Request $request, ProposalService $service): JsonResponse
    {
        $data = $request->validate(['answers' => 'required|array']);
        $p = $this->owned($proposal, $request);
        if (in_array($p->status, ['DISCLOSURES_PENDING', 'DRAFT'], true)) {
            $p = $service->answer($p, $this->normalise($p, $data['answers']), $request->user());
        } else {
            $p->update(['disclosures' => $this->normalise($p, $data['answers'])]);
            $p->disclosureResponse?->update(['answers' => $p->disclosures]);
        }

        return response()->json(['data' => $this->present($p->refresh())]);
    }

    public function submit(string $proposal, Request $request, ProposalService $service): JsonResponse
    {
        $p = $this->owned($proposal, $request);
        if (! $p->attested_at) {
            $p = $service->attest($p, $request->user());
        }

        return response()->json(['data' => $this->present($p->refresh())]);
    }

    /** Accepting the terms is what actually submits the proposal for (straight-through) underwriting. */
    public function terms(string $proposal, Request $request, ProposalService $service): JsonResponse
    {
        $data = $request->validate(['accepted' => 'required|accepted']);
        $p = $this->owned($proposal, $request);
        if (! $p->attested_at) {
            $p = $service->attest($p, $request->user());
        }
        if ($p->status === 'DOCUMENTS_PENDING') {
            $p = $service->submit($p, $request->user());
        }

        return response()->json(['data' => ['proposal_id' => $p->id, 'status' => $p->status, 'accepted' => true, 'accepted_at' => now()->toIso8601String()]]);
    }

    private function owned(string $id, Request $request): Proposal
    {
        $p = Proposal::where('tenant_id', app(TenantContext::class)->id())->findOrFail($id);
        abort_unless($p->party_id === $request->user()->party_id || $p->created_by === $request->user()->id, 403);

        return $p->load(['disclosureSchema', 'disclosureResponse', 'underwritingCase.referrals']);
    }

    /** Booleans arrive as true/false or "true"/"false"/"yes"/"no" from the form. */
    private function normalise(Proposal $p, array $answers): array
    {
        $out = [];
        foreach ($p->disclosureSchema->questions ?? [] as $q) {
            if (! array_key_exists($q['code'], $answers)) {
                continue;
            }
            $v = $answers[$q['code']];
            $out[$q['code']] = ($q['type'] ?? 'boolean') === 'boolean' ? filter_var($v, FILTER_VALIDATE_BOOLEAN) : (string) $v;
        }

        return $out;
    }

    private function present(Proposal $p): array
    {
        $answers = $p->disclosures ?? [];
        $locale = app()->getLocale() === 'fr' ? 'fr' : 'en';

        return [
            'id' => $p->disclosureSchema?->id ?? $p->id, 'proposal_id' => $p->id, 'status' => $p->status,
            'questions' => collect($p->disclosureSchema?->questions ?? [])->map(fn ($q) => [
                'id' => $q['code'], 'label' => is_array($q['label']) ? ($q['label'][$locale] ?? reset($q['label'])) : $q['label'], 'type' => $q['type'] ?? 'boolean', 'required' => (bool) ($q['required'] ?? true),
                'answer' => $answers[$q['code']] ?? null,
            ])->values(),
            'referral_reason' => $p->underwritingCase?->referrals?->first()?->reason_code ?? ($p->disclosureResponse?->referral_flags[0] ?? null),
        ];
    }
}
