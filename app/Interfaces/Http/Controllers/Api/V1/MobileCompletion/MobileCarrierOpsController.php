<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Audit\AuditWriter;
use App\Application\FinancialDistribution\MobileCarrierFinanceService;
use App\Application\Underwriting\UnderwritingService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingReferralTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The insurer/carrier portal (app/carrier/*) in the shapes the app renders. */
final class MobileCarrierOpsController
{
    public function __construct(private MobileCarrierFinanceService $finance, private AuditWriter $audit) {}

    public function dashboard(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $base = $this->finance->dashboard($t);
        $xaf = fn ($minor) => number_format(((int) $minor) / 100, 0, '.', ' ').' FCFA';
        $referrals = UnderwritingCase::where('tenant_id', $t)->whereIn('status', ['QUEUED', 'IN_REVIEW', 'AWAITING_INFORMATION'])->count();
        $issuance = DB::table('policy_issuance_requests')->where('tenant_id', $t)->whereIn('status', ['REQUESTED', 'CARRIER_REVIEW', 'PENDING'])->count();
        $claims = Claim::where('tenant_id', $t)->whereIn('status', ['SUBMITTED', 'ACKNOWLEDGED', 'EVIDENCE_PENDING', 'ASSESSMENT', 'CARRIER_REVIEW', 'DISPUTED'])->count();
        $net = (int) collect($base['settlements_net_by_currency'] ?? [])->sum('net_amount_minor');

        return response()->json(['data' => $base + ['metrics' => [
            ['label' => 'Underwriting referrals', 'value' => (string) $referrals, 'tone' => $referrals > 0 ? 'warning' : 'success'],
            ['label' => 'Issuance queue', 'value' => (string) $issuance, 'tone' => $issuance > 0 ? 'warning' : 'success'],
            ['label' => 'Open claims', 'value' => (string) $claims, 'tone' => 'info'],
            ['label' => 'Policies in force', 'value' => (string) DB::table('policies')->where('tenant_id', $t)->where('status', 'ACTIVE')->count(), 'tone' => 'success'],
            ['label' => 'Settlements (net)', 'value' => $xaf($net), 'tone' => 'neutral'],
        ]]]);
    }

    public function referrals(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $cases = UnderwritingCase::with(['proposal.party', 'proposal.offer.product', 'proposal.offer.quote', 'referrals'])->where('tenant_id', $t)->orderByRaw("CASE status WHEN 'QUEUED' THEN 0 WHEN 'IN_REVIEW' THEN 1 WHEN 'AWAITING_INFORMATION' THEN 2 ELSE 3 END")->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $cases->map(fn ($c) => $this->referralOf($c))->values()]);
    }

    public function referral(string $id, Request $request): JsonResponse
    {
        $c = UnderwritingCase::with(['proposal.party', 'proposal.offer.product', 'proposal.offer.quote', 'referrals', 'decisions'])->where('tenant_id', app(TenantContext::class)->id())->findOrFail($id);

        return response()->json(['data' => $this->referralOf($c)]);
    }

    public function decideReferral(string $id, Request $request, UnderwritingService $underwriting): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:APPROVE,DECLINE,MORE_INFORMATION', 'note' => 'required|string|min:5|max:4000']);
        $c = UnderwritingCase::with('referrals')->where('tenant_id', app(TenantContext::class)->id())->findOrFail($id);
        if ($data['decision'] === 'MORE_INFORMATION') {
            $c->update(['status' => 'AWAITING_INFORMATION', 'assigned_to' => $c->assigned_to ?? $request->user()->id]);
            $c->referrals()->where('status', 'OPEN')->update(['status' => 'WAITING', 'resolution_notes' => $data['note']]);
            $this->audit->record('underwriting.more_information', 'underwriting_case', $c->id, []);
        } else {
            foreach ($c->referrals()->whereIn('status', ['OPEN', 'WAITING', 'IN_PROGRESS'])->get() as $task) {
                $task->update(['status' => 'RESOLVED', 'resolution_notes' => $data['note'], 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);
            }
            if ($c->status === 'AWAITING_INFORMATION') {
                $c->update(['status' => 'IN_REVIEW']);
            }
            $underwriting->decide($c->refresh(), ['decision' => $data['decision'] === 'APPROVE' ? 'APPROVED' : 'DECLINED', 'reason_code' => $data['decision'] === 'APPROVE' ? 'UNDERWRITER_APPROVED' : 'UNDERWRITER_DECLINED', 'notes' => str_pad($data['note'], 20, '.')], $request->user());
        }

        return response()->json(['data' => $this->referralOf($c->refresh()->load(['proposal.party', 'proposal.offer.product', 'proposal.offer.quote', 'referrals']))]);
    }

    public function issuance(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $rows = DB::table('policy_issuance_requests')->join('proposals', 'proposals.id', '=', 'policy_issuance_requests.proposal_id')->leftJoin('parties', 'parties.id', '=', 'proposals.party_id')
            ->where('policy_issuance_requests.tenant_id', $t)->select('policy_issuance_requests.*', 'proposals.proposal_number', 'parties.display_name')->orderByDesc('policy_issuance_requests.created_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => $r->id, 'reference' => $r->proposal_number ?? substr($r->id, 0, 8), 'subject' => ($r->display_name ?? 'Customer').' · issuance', 'status' => $r->status,
            'priority' => in_array($r->status, ['REQUESTED', 'CARRIER_REVIEW', 'PENDING'], true) ? 'HIGH' : 'NORMAL', 'submitted_at' => \Carbon\Carbon::parse($r->created_at)->toIso8601String(),
        ])->values()]);
    }

    public function claims(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $rows = Claim::with('claimant')->where('tenant_id', $t)->orderByRaw("CASE WHEN status IN ('CARRIER_REVIEW','DISPUTED') THEN 0 WHEN status IN ('SUBMITTED','ACKNOWLEDGED','EVIDENCE_PENDING','ASSESSMENT') THEN 1 ELSE 2 END")->orderByDesc('submitted_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (Claim $c) => [
            'id' => $c->id, 'reference' => $c->claim_number, 'subject' => ($c->claimant?->display_name ?? 'Claimant').' · '.($c->loss_details['incident']['incident_type'] ?? 'claim'), 'status' => $c->status,
            'priority' => $c->priority ?? 'NORMAL', 'submitted_at' => $c->submitted_at?->toIso8601String(),
        ])->values()]);
    }

    public function settlements(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $rows = $this->finance->settlements($t, 100)->getCollection();

        return response()->json(['data' => $rows->map(fn ($s) => [
            'id' => $s->id, 'period' => \Carbon\Carbon::parse($s->period_start)->format('M Y'), 'gross_premium_minor' => (int) DB::table('settlement_items')->where('settlement_batch_id', $s->id)->sum('gross_premium_minor'),
            'net_payable_minor' => (int) $s->net_amount_minor, 'currency' => $s->currency, 'status' => $s->status,
        ])->values()]);
    }

    private function referralOf(UnderwritingCase $c): array
    {
        $offer = $c->proposal?->offer;

        return [
            'id' => $c->id, 'quote_id' => $offer?->quote_id ?? '', 'customer_name' => $c->proposal?->party?->display_name ?? 'Customer', 'product' => $offer?->product?->name ?? ($offer?->quote?->line_code ?? 'Product'),
            'reason' => implode(', ', array_map(fn ($r) => ucfirst(strtolower(str_replace('_', ' ', $r))), $c->referral_reasons ?? [])) ?: 'Manual review',
            'status' => $c->status, 'premium_minor' => (int) ($offer?->total_minor ?? 0), 'submitted_at' => $c->created_at?->toIso8601String(),
            'decision_note' => $c->referrals?->whereNotNull('resolution_notes')->last()?->resolution_notes,
        ];
    }
}
