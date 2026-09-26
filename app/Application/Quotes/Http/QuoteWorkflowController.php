<?php

declare(strict_types=1);

namespace App\Application\Quotes\Http;

use App\Application\Identity\OwnershipScope;
use App\Application\Quotes\QuoteComparisonService;
use App\Application\Quotes\QuoteDocumentRenderer;
use App\Application\Quotes\QuoteMachine;
use App\Application\Quotes\QuotePremiumOverrideService;
use App\Application\Quotes\QuoteService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Quote;
use App\Models\SavedComparison;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Batch 6B quote workflow API (routes/quotes.php): WF-010…014, REQ-QUO-004/005, REQ-DST-003.
 * Customers act on their own quotes only (OwnershipScope); staff need the named permission.
 * POST v1/quotes, GET quotes/{q}, POST quotes/{q}/rate and offers/{o}/accept stay on routes/api.php (QuoteController,
 * QuoteLifecycleController) — this controller adds the missing steps only.
 */
final class QuoteWorkflowController
{
    public function __construct(private readonly QuoteService $quotes, private readonly OwnershipScope $own) {}

    public function amend(Request $r, string $quote): JsonResponse
    {
        $d = $r->validate(['risk_facts' => 'required|array']);
        $q = $this->quotes->amend($this->quote($r, $quote, 'quotes.manage'), $d['risk_facts'], $r->user());

        return response()->json(['data' => $this->quotes->envelope($q)]);
    }

    public function generate(Request $r, string $quote): JsonResponse
    {
        return response()->json(['data' => $this->quotes->envelope($this->quotes->generate($this->quote($r, $quote, 'quotes.manage'), $r->user()))]);
    }

    public function send(Request $r, string $quote): JsonResponse
    {
        $d = $r->validate(['channel' => ['required', Rule::in(QuoteService::SHARE_CHANNELS)], 'recipient' => 'nullable|string|max:191']);
        $out = $this->quotes->send($this->quote($r, $quote, 'quotes.send', staffOnly: true), $d['channel'], $d['recipient'] ?? null, $r->user());

        return response()->json(['data' => ['share_id' => $out['share_id'], 'channel' => $out['channel'], 'token' => $out['token'], 'link_path' => $out['link_path'],
            'expires_at' => $out['expires_at'], 'quote' => $out['quote']]], 201);
    }

    public function decline(Request $r, string $quote): JsonResponse
    {
        $d = $r->validate(['reason_code' => ['required', Rule::in(QuoteService::DECLINE_REASONS)], 'note' => 'nullable|string|max:1000']);

        return response()->json(['data' => $this->quotes->decline($this->quote($r, $quote, 'quotes.manage'), $d['reason_code'], $d['note'] ?? null, $r->user())]);
    }

    public function cancel(Request $r, string $quote): JsonResponse
    {
        $q = $this->quotes->cancel($this->quote($r, $quote, 'quotes.manage'), $r->user());

        return response()->json(['data' => ['id' => $q->id, 'status' => $q->status, 'state' => $q->lifecycle_state]]);
    }

    public function history(Request $r, string $quote): JsonResponse
    {
        $q = $this->quote($r, $quote, 'quotes.read');

        return response()->json(['data' => ['quote_id' => $q->id, 'state' => QuoteMachine::stateOf($q), 'status' => $q->status,
            'transitions' => DB::table('workflow_transition_history')->where(['machine' => QuoteMachine::NAME, 'subject_id' => $q->id])->orderBy('occurred_at')
                ->get(['event', 'from_state', 'to_state', 'actor_id', 'reason', 'occurred_at'])]]);
    }

    public function document(Request $r, string $quote, QuoteDocumentRenderer $renderer): Response
    {
        $q = $this->quote($r, $quote, 'quotes.read');
        if (! in_array(QuoteMachine::stateOf($q), ['GENERATED', 'SENT', 'VIEWED', 'ACCEPTED'], true)) {
            abort(422, __('quotes.not_generated'));
        }

        return response($renderer->pdf($q), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.($q->quote_number ?? $q->id).'.pdf"']);
    }

    public function openShare(string $token): JsonResponse
    {
        return response()->json(['data' => $this->quotes->openShare($token)]);
    }

    // ---- REQ-QUO-005 premium override (BRK-035)

    public function requestOverride(Request $r, string $quote, string $offer, QuotePremiumOverrideService $svc): JsonResponse
    {
        $d = $r->validate(['premium_minor' => 'required|integer|min:1', 'reason_code' => ['required', Rule::in(QuotePremiumOverrideService::REASONS)], 'justification' => 'required|string|min:10|max:2000']);
        $q = $this->quote($r, $quote, 'quotes.premium_override.request', staffOnly: true);

        return response()->json(['data' => $svc->request($q, $q->offers()->findOrFail($offer), (int) $d['premium_minor'], $d['reason_code'], $d['justification'], $r->user())], 201);
    }

    public function decideOverride(Request $r, string $quote, string $offer, string $override, QuotePremiumOverrideService $svc): JsonResponse
    {
        $d = $r->validate(['decision' => 'required|in:APPROVED,REJECTED', 'note' => 'nullable|string|max:1000|required_if:decision,REJECTED']);
        $q = $this->quote($r, $quote, 'quotes.premium_override.approve', staffOnly: true);

        return response()->json(['data' => $svc->decide($q, $q->offers()->findOrFail($offer), $override, $d['decision'] === 'APPROVED', $d['note'] ?? null, $r->user())]);
    }

    public function applyOverride(Request $r, string $quote, string $offer, string $override, QuotePremiumOverrideService $svc): JsonResponse
    {
        $q = $this->quote($r, $quote, 'quotes.premium_override.approve', staffOnly: true);

        return response()->json(['data' => $svc->applyEffective($q, $q->offers()->findOrFail($offer), $override)]);
    }

    // ---- REQ-DST-003 quote comparisons (SCREEN_TO_API_MATRIX quote-comparisons)

    public function storeComparison(Request $r, QuoteComparisonService $svc): JsonResponse
    {
        $d = $r->validate(['quote_id' => 'required|uuid', 'offer_ids' => 'nullable|array|max:'.QuoteComparisonService::MAX, 'offer_ids.*' => 'uuid']);
        $cmp = $svc->save($this->quote($r, $d['quote_id'], 'quotes.read'), array_values($d['offer_ids'] ?? []), $r->user());

        return response()->json(['data' => $svc->present($cmp)], 201);
    }

    public function comparisons(Request $r, QuoteComparisonService $svc): JsonResponse
    {
        $rows = SavedComparison::where(['tenant_id' => $this->tenant(), 'user_id' => $r->user()->getKey()])
            ->when($r->query('quote_id'), fn ($q, $id) => $q->where('quote_request_id', $id))
            ->whereIn('quote_request_id', Quote::where('tenant_id', $this->tenant())->select('id'))->latest()->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($c) => $svc->present($c))->values()]);
    }

    public function showComparison(Request $r, string $comparison, QuoteComparisonService $svc): JsonResponse
    {
        $cmp = SavedComparison::where(['tenant_id' => $this->tenant(), 'user_id' => $r->user()->getKey()])->findOrFail($comparison);

        return response()->json(['data' => $svc->present($cmp)]);
    }

    // ---- scoping

    private function quote(Request $r, string $id, string $permission, bool $staffOnly = false): Quote
    {
        /** @var User $user */
        $user = $r->user();
        $owner = $this->own->isOwnerScoped($user);
        if (($staffOnly && $owner) || (! $owner && ! $user->hasPermission($permission))) {
            abort(403, 'Permission denied.');
        }

        $quote = $this->own->apply(Quote::where('tenant_id', $this->tenant()), $user)->findOrFail($id);
        // Owner decision: partners act only on quotes of clients in their own book.
        app(\App\Application\Partners\PartnerBook::class)->assertInBook($user, $quote->party_id);

        return $quote;
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
