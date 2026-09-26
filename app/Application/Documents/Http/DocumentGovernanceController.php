<?php

declare(strict_types=1);

namespace App\Application\Documents\Http;

use App\Application\Documents\DocumentOrigin;
use App\Application\Documents\Intake\DocumentIntakeService;
use App\Application\Documents\Retention\DocumentAccessLog;
use App\Application\Documents\Retention\DocumentDestructionService;
use App\Application\Documents\Retention\LegalHoldService;
use App\Application\Documents\Retention\RetentionScheduleService;
use App\Application\Documents\Signatures\SignatureService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Batch 8-9: REQ-DOC-008/009/010/012 staff + signer API. */
final class DocumentGovernanceController
{
    public function __construct(private TenantContext $tenant) {}

    // --- REQ-DOC-008 ---
    public function thirdPartyEvidence(Request $r): JsonResponse
    {
        $d = $r->validate(['claim_id' => 'nullable|uuid', 'policy_id' => 'nullable|uuid']);

        return response()->json(['data' => DocumentOrigin::thirdPartyEvidence($this->tenant->id(), $d['claim_id'] ?? null, $d['policy_id'] ?? null)]);
    }

    // --- REQ-DOC-010 intake ---
    public function intakeIndex(Request $r): JsonResponse
    {
        $q = DB::table('document_intake_items')->where('tenant_id', $this->tenant->id())->when($r->query('status'), fn ($q, $s) => $q->where('status', $s));

        return response()->json(['data' => $q->orderByDesc('received_at')->limit(200)->get()]);
    }

    public function intakeReceive(Request $r, DocumentIntakeService $s): JsonResponse
    {
        $d = $r->validate([
            'document_id' => 'required|uuid', 'channel' => 'required|in:UPLOAD,EMAIL,POST,PORTAL,API,SCAN', 'declared_type_code' => 'nullable|string|max:96',
            'original_filename' => 'nullable|string|max:255', 'origin' => 'nullable|string|max:16', 'stage' => 'nullable|in:'.implode(',', DocumentOrigin::STAGES),
            'subject_type' => 'nullable|string|max:32', 'subject_id' => 'nullable|uuid',
        ]);

        return response()->json(['data' => $s->receive($this->tenant->id(), $d, $r->user())], 201);
    }

    public function intakeClassify(Request $r, string $item, DocumentIntakeService $s): JsonResponse
    {
        $d = $r->validate(['document_type_code' => 'required|string|max:96']);

        return response()->json(['data' => $s->classify($this->tenant->id(), $item, $d['document_type_code'], $r->user())]);
    }

    public function intakeReject(Request $r, string $item, DocumentIntakeService $s): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $s->reject($this->tenant->id(), $item, $d['reason'], $r->user())]);
    }

    // --- REQ-DOC-009 access log / retention / legal hold / destruction ---
    public function accessLog(Request $r, string $document, DocumentAccessLog $log): JsonResponse
    {
        $doc = Document::where('tenant_id', $this->tenant->id())->whereKey($document)->firstOrFail();
        $log->authorize($r->user(), $doc, 'AUDIT_VIEW', 'ACCESS_LOG_REVIEW');

        return response()->json(['data' => $log->history($doc->id)]);
    }

    public function retentionIndex(): JsonResponse
    {
        $tid = $this->tenant->id();

        return response()->json(['data' => DB::table('retention_schedules')->where(fn ($q) => $q->where('tenant_id', $tid)->orWhereNull('tenant_id'))->orderBy('code')->get()]);
    }

    public function retentionDraft(Request $r, RetentionScheduleService $s): JsonResponse
    {
        $d = $r->validate([
            'code' => 'required|string|max:64', 'document_type_code' => 'nullable|string|max:96', 'document_group' => 'nullable|string|max:40',
            'security_level' => 'nullable|string|max:32', 'retention_years' => 'required|integer|min:1|max:200',
            'trigger_event' => 'nullable|in:ISSUED_AT,CREATED_AT,VALID_UNTIL', 'disposition' => 'nullable|in:DESTROY,REVIEW', 'legal_basis' => 'required|string|max:2000',
            'retention_class' => 'nullable|string|max:32', 'legal_hold_override' => 'nullable|boolean', 'destruction_method' => 'nullable|string|max:40',
            'effective_from' => 'nullable|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
        ]);

        return response()->json(['data' => $s->draft($this->tenant->id(), $d, $r->user())], 201);
    }

    public function retentionApprove(Request $r, string $schedule, RetentionScheduleService $s): JsonResponse
    {
        return response()->json(['data' => $s->approve($this->tenant->id(), $schedule, $r->user())]);
    }

    public function retentionStatus(Request $r, string $document, RetentionScheduleService $s, LegalHoldService $h): JsonResponse
    {
        $doc = Document::where('tenant_id', $this->tenant->id())->whereKey($document)->firstOrFail();
        $from = $s->disposableFrom($doc);

        return response()->json(['data' => [
            'document_id' => $doc->id, 'schedule' => $s->scheduleFor($doc), 'disposable_from' => $from?->toDateString(),
            'legal_holds' => $h->activeFor($doc), 'destroyed_at' => DB::table('documents')->where('id', $doc->id)->value('destroyed_at'),
        ]]);
    }

    public function holdIndex(Request $r): JsonResponse
    {
        $q = DB::table('legal_holds')->where('tenant_id', $this->tenant->id())
            ->when($r->query('subject_type'), fn ($q, $v) => $q->where('subject_type', $v))
            ->when($r->query('subject_id'), fn ($q, $v) => $q->where('subject_id', $v))
            ->when($r->boolean('active'), fn ($q) => $q->whereNull('released_at'));

        return response()->json(['data' => $q->orderByDesc('placed_at')->limit(500)->get()]);
    }

    public function holdPlace(Request $r, LegalHoldService $s): JsonResponse
    {
        $d = $r->validate([
            'subject_type' => 'required|string|max:32', 'subject_id' => 'required|uuid', 'reason_code' => 'required|string|max:64',
            'notes' => 'required|string|max:5000', 'case_id' => 'nullable|uuid', 'hold_until' => 'nullable|date',
        ]);

        return response()->json(['data' => $s->place($this->tenant->id(), $d, $r->user())], 201);
    }

    public function holdRelease(Request $r, string $hold, LegalHoldService $s): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $s->release($this->tenant->id(), $hold, $d['reason'], $r->user())]);
    }

    public function destructionRequest(Request $r, string $document, DocumentDestructionService $s): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $s->request($this->tenant->id(), $document, $d['reason'], $r->user())], 201);
    }

    public function destructionDecide(Request $r, string $request, DocumentDestructionService $s): JsonResponse
    {
        $d = $r->validate(['approve' => 'required|boolean', 'note' => 'required|string|max:2000']);

        return response()->json(['data' => $s->decide($this->tenant->id(), $request, (bool) $d['approve'], $d['note'], $r->user())]);
    }

    // --- REQ-DOC-012 e-signature ---
    public function signatureCreate(Request $r, SignatureService $s): JsonResponse
    {
        $d = $r->validate([
            'document_id' => 'required|uuid', 'consent_text' => 'required|string|max:5000', 'provider' => 'nullable|string|max:32', 'expires_at' => 'nullable|date|after:now',
            'signers' => 'required|array|min:1|max:20', 'signers.*.user_id' => 'nullable|uuid', 'signers.*.party_id' => 'nullable|uuid',
            'signers.*.name' => 'required|string|max:160', 'signers.*.role' => 'required|string|max:32', 'signers.*.order' => 'nullable|integer|min:1|max:50',
        ]);

        return response()->json(['data' => $s->request($this->tenant->id(), $d, $r->user())], 201);
    }

    public function signatureShow(string $request, SignatureService $s): JsonResponse
    {
        $data = $s->show($request);
        abort_unless($data['tenant_id'] === $this->tenant->id(), 404);

        return response()->json(['data' => $data]);
    }

    public function signatureCancel(Request $r, string $request, SignatureService $s): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $s->cancel($this->tenant->id(), $request, $r->user(), $d['reason'])]);
    }

    /** Signer endpoints: identity is the authenticated user; no tenant membership needed (customers sign too). */
    public function sign(Request $r, string $request, SignatureService $s): JsonResponse
    {
        $d = $r->validate(['consent_accepted' => 'required|accepted']);

        return response()->json(['data' => $s->sign($request, $r->user(), ['consent_accepted' => (bool) $d['consent_accepted'], 'ip' => $r->ip(), 'user_agent' => $r->userAgent()])]);
    }

    public function decline(Request $r, string $request, SignatureService $s): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $s->decline($request, $r->user(), $d['reason'])]);
    }
}
