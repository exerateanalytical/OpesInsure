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
 * This adapts that onto ProposalService's answer/attest/declare/submit so the
 * underlying workflow, history and referral logic stay the platform's
 * (REQ-DUP-007: no business rules here). Questions are the PROPOSAL-stage
 * question set frozen on the proposal (ProposalService::questions).
 */
final class MobileDisclosureController
{
    public function session(string $proposal, Request $request, ProposalService $service): JsonResponse
    {
        return response()->json(['data' => $this->present($service, $this->owned($proposal, $request))]);
    }

    public function answers(string $proposal, Request $request, ProposalService $service): JsonResponse
    {
        $data = $request->validate(['answers' => 'required|array']);
        $p = $this->owned($proposal, $request);
        // Pre-submission (and information-required) answers go through the service: validation, referral flags,
        // hash; changing answers resets the attestation. Submitted proposals are immutable (422 disclosures_locked).
        $p = $service->answer($p, \App\Application\Underwriting\Proposal\ProposalQuestions::normalise($service->questions($p), $data['answers']), $request->user());

        return response()->json(['data' => $this->present($service, $this->owned($p->id, $request))]);
    }

    public function submit(string $proposal, Request $request, ProposalService $service): JsonResponse
    {
        $p = $this->owned($proposal, $request);
        if (! $p->attested_at) {
            $p = $service->attest($p, $request->user(), [], 'MOBILE', $this->evidence($request));
        }

        return response()->json(['data' => $this->present($service, $this->owned($p->id, $request))]);
    }

    /** Accepting the terms is what actually submits the proposal for (straight-through) underwriting. */
    public function terms(string $proposal, Request $request, ProposalService $service): JsonResponse
    {
        $data = $request->validate(['accepted' => 'required|accepted']);
        $p = $this->owned($proposal, $request);
        // Only the proposal's own customer accepts its contract terms (owner rule 2026-09-30): an agent/broker who
        // created the application sends the web acceptance link instead (ProposalAcceptanceLinks).
        abort_unless($p->party_id !== null && $p->party_id === $request->user()->party_id, 403, __('acceptance.only_customer'));
        if (! $p->attested_at) {
            $p = $service->attest($p, $request->user(), [], 'MOBILE', $this->evidence($request));
        }
        $service->declare($p, 'TERMS_ACCEPTANCE', $request->user(), 'MOBILE', $this->evidence($request));
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

    /** @return array{ip: ?string, user_agent: ?string} */
    private function evidence(Request $request): array
    {
        return ['ip' => $request->ip(), 'user_agent' => $request->userAgent()];
    }

    private function present(ProposalService $service, Proposal $p): array
    {
        $answers = $p->disclosures ?? [];
        $locale = app()->getLocale() === 'fr' ? 'fr' : 'en';

        return [
            'id' => $p->question_set_id ?? $p->disclosure_schema_version_id ?? $p->id, 'proposal_id' => $p->id, 'status' => $p->status,
            'questions' => collect($service->questions($p))->map(fn ($q) => [
                'id' => $q['code'], 'label' => is_array($q['label']) ? ($q['label'][$locale] ?? reset($q['label'])) : $q['label'], 'type' => $q['type'] ?? 'boolean', 'required' => (bool) ($q['required'] ?? true),
                'answer' => $answers[$q['code']] ?? null,
            ] + self::inputContract($q))->values(),
            'referral_reason' => $p->underwritingCase?->referrals?->first()?->reason_code ?? ($p->disclosureResponse?->referral_flags[0] ?? null),
        ];
    }

    /**
     * Selection-first contract for disclosure questions (InputFieldContract):
     * input kind, and for select questions the master-data source / options and
     * allow_other. Booleans stay booleans; admin-authored text questions are
     * flagged free_text so the audit can list them.
     */
    private static function inputContract(array $q): array
    {
        $f = \App\Application\MasterData\InputFieldContract::field(array_filter([
            'key' => $q['code'], 'type' => $q['type'] ?? 'boolean', 'source' => $q['source'] ?? null, 'parent_field' => $q['parent_field'] ?? null,
            'other_allowed' => $q['other_allowed'] ?? null, 'options' => $q['options'] ?? null, 'min' => $q['min'] ?? null, 'max' => $q['max'] ?? null,
            'free_text' => isset($q['free_text_reason']) ? ['reason' => $q['free_text_reason']] : null,
        ], fn ($v) => $v !== null));

        return array_intersect_key($f, array_flip(['input', 'source', 'allow_other', 'options', 'min', 'max', 'free_text']));
    }
}
