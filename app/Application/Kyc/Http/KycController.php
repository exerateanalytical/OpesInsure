<?php

declare(strict_types=1);

namespace App\Application\Kyc\Http;

use App\Application\Kyc\KycGate;
use App\Application\Kyc\KycRequirementService;
use App\Application\Kyc\KycService;
use App\Application\Kyc\Models\ScreeningCheck;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Document;
use App\Models\KycSubmission;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * REQ-KYC-001..003 staff KYC API: review queue, maker-checker review, screening, levels, remediation.
 * No status writes here (REQ-ARC-008): every change goes through KycService.
 */
final class KycController
{
    public function __construct(private readonly KycService $kyc) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => 'nullable|string|max:24', 'party_id' => 'nullable|uuid', 'subject_kind' => 'nullable|in:INDIVIDUAL,CORPORATE',
            'kyc_level' => 'nullable|in:SIMPLIFIED,STANDARD,ENHANCED', 'per_page' => 'nullable|integer|min:1|max:100']);
        $page = KycSubmission::where('tenant_id', $this->tenant())
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', strtoupper($v)))
            ->when($d['party_id'] ?? null, fn ($q, $v) => $q->where('party_id', $v))
            ->when($d['subject_kind'] ?? null, fn ($q, $v) => $q->where('subject_kind', $v))
            ->when($d['kyc_level'] ?? null, fn ($q, $v) => $q->where('kyc_level', $v))
            ->orderByRaw('submitted_at NULLS LAST')->orderBy('created_at')
            ->paginate($d['per_page'] ?? 25, ['id', 'party_id', 'status', 'subject_kind', 'kyc_level', 'screening_status', 'case_id', 'submitted_at', 'approved_at', 'expires_at', 'created_at']);

        return response()->json($page);
    }

    public function show(string $submission): JsonResponse
    {
        return $this->ok($this->find($submission));
    }

    public function expiring(Request $r): JsonResponse
    {
        $days = (int) ($r->validate(['days' => 'nullable|integer|min:1|max:366'])['days'] ?? config('kyc.expiry_warning_days', 30));
        $rows = KycSubmission::where('tenant_id', $this->tenant())->where('status', 'APPROVED')->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays($days))->whereNull('superseded_by_submission_id')->orderBy('expires_at')
            ->get(['id', 'party_id', 'kyc_level', 'expires_at', 'expiry_basis']);

        return response()->json(['data' => $rows, 'meta' => ['days' => $days]]);
    }

    public function requirements(Request $r, KycRequirementService $req): JsonResponse
    {
        $d = $r->validate(['subject_kind' => ['required', Rule::in(KycRequirementService::KINDS)], 'kyc_level' => ['required', Rule::in(KycRequirementService::LEVELS)]]);

        return response()->json(['data' => $req->requirements($this->tenant(), $d['subject_kind'], $d['kyc_level'])]);
    }

    public function partyStatus(string $party, KycGate $gate): JsonResponse
    {
        $p = $this->party($party);
        $tenant = $this->tenant();

        return response()->json(['data' => $gate->status($tenant, $p->id) + ['gate_mode' => $gate->mode($tenant)]]);
    }

    /** Staff-assisted onboarding (e.g. corporate, WF-004): draft for a tenant customer. */
    public function open(string $party): JsonResponse
    {
        $s = $this->kyc->draftFor($this->party($party), $this->tenant());

        return $this->ok($s, $s->wasRecentlyCreated ? 201 : 200);
    }

    public function attach(Request $r, string $submission): JsonResponse
    {
        $d = $r->validate(['document_id' => 'required|uuid', 'purpose' => 'required|string|max:64']);
        $s = $this->find($submission);
        $doc = Document::where('tenant_id', $s->tenant_id)->whereKey($d['document_id'])->first()
            ?? throw new ApiProblemException('DOCUMENT_NOT_FOUND', 404, 'Document not found in this tenant.');

        return $this->ok($this->kyc->attachDocument($s, $doc, $d['purpose'], $r->user()), 201);
    }

    public function submit(Request $r, string $submission): JsonResponse
    {
        return $this->ok($this->kyc->submit($this->find($submission), $r->validate(['notes' => 'nullable|string|max:2000'])['notes'] ?? null, $r->user()));
    }

    public function startReview(Request $r, string $submission): JsonResponse
    {
        return $this->ok($this->kyc->startReview($this->find($submission), $r->user()));
    }

    public function requestInformation(Request $r, string $submission): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return $this->ok($this->kyc->requestInformation($this->find($submission), $d['reason'], $r->user()));
    }

    public function level(Request $r, string $submission): JsonResponse
    {
        $d = $r->validate(['kyc_level' => ['required', Rule::in(KycRequirementService::LEVELS)], 'reason' => 'required|string|max:2000']);

        return $this->ok($this->kyc->setLevel($this->find($submission), $d['kyc_level'], $d['reason'], $r->user()));
    }

    public function screening(Request $r, string $submission, string $check): JsonResponse
    {
        $d = $r->validate(['status' => 'required|in:CLEAR,POSSIBLE_MATCH,CONFIRMED_MATCH', 'list_reference' => 'required|string|max:255', 'notes' => 'nullable|string|max:2000']);
        $s = $this->find($submission);
        $c = ScreeningCheck::where('tenant_id', $s->tenant_id)->whereKey($check)->first() ?? throw new ApiProblemException('SCREENING_NOT_FOUND', 404, 'Screening check not found.');

        return $this->ok($this->kyc->recordScreening($s, $c, $d, $r->user()));
    }

    public function recommend(Request $r, string $submission): JsonResponse
    {
        $d = $r->validate(['outcome' => 'required|in:APPROVE,REJECT', 'rationale' => 'required|string|max:2000']);

        return $this->ok($this->kyc->recommend($this->find($submission), $d['outcome'], $d['rationale'], $r->user()));
    }

    public function decide(Request $r, string $submission): JsonResponse
    {
        $d = $r->validate(['confirm' => 'required|boolean', 'reason' => 'required|string|max:2000']);

        return $this->ok($this->kyc->decide($this->find($submission), $r->boolean('confirm'), $d['reason'], $r->user()));
    }

    public function remediate(Request $r, string $submission): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return $this->ok($this->kyc->remediate($this->find($submission), $d['reason'], $r->user()), 201);
    }

    private function ok(KycSubmission $s, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->kyc->present($s->fresh(), true)], $status);
    }

    private function find(string $id): KycSubmission
    {
        return KycSubmission::where('tenant_id', $this->tenant())->whereKey($id)->first()
            ?? throw new ApiProblemException('KYC_SUBMISSION_NOT_FOUND', 404, 'KYC submission not found.');
    }

    /** The party must be a customer of the current tenant (or already have KYC here). */
    private function party(string $id): Party
    {
        $tenant = $this->tenant();
        $known = DB::table('tenant_customers')->where('tenant_id', $tenant)->where('party_id', $id)->exists()
            || KycSubmission::where('tenant_id', $tenant)->where('party_id', $id)->exists();

        return ($known ? Party::find($id) : null) ?? throw new ApiProblemException('PARTY_NOT_FOUND', 404, 'Party not found in this tenant.');
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
