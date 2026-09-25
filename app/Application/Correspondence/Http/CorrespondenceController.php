<?php

declare(strict_types=1);

namespace App\Application\Correspondence\Http;

use App\Application\Cases\Models\WorkCase;
use App\Application\Correspondence\CorrespondenceService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** REQ-COR-001 correspondence register API. Rows on a case the caller cannot see are hidden (confidentiality). */
final class CorrespondenceController
{
    public function __construct(private readonly CorrespondenceService $svc) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['case_id' => 'nullable|uuid', 'direction' => 'nullable|in:INBOUND,OUTBOUND', 'status' => 'nullable|string|max:16',
            'subject_type' => 'nullable|string|max:64', 'subject_id' => 'nullable|uuid', 'per_page' => 'nullable|integer|min:1|max:100']);
        $tenant = $this->tenant();
        $q = DB::table('correspondence_register')->where('tenant_id', $tenant)
            ->where(fn ($q) => $q->whereNull('case_id')->orWhereIn('case_id', WorkCase::query()->where('tenant_id', $tenant)->select('id')))
            ->when($d['case_id'] ?? null, fn ($q, $v) => $q->where('case_id', $v))
            ->when($d['direction'] ?? null, fn ($q, $v) => $q->where('direction', $v))
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($d['subject_type'] ?? null, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($d['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->orderByDesc('created_at');

        return response()->json($q->paginate($d['per_page'] ?? 25));
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'direction' => 'required|in:INBOUND,OUTBOUND', 'channel' => ['required', Rule::in(CorrespondenceService::CHANNELS)],
            'counterparty_type' => ['required', Rule::in(CorrespondenceService::COUNTERPARTY_TYPES)], 'counterparty_name' => 'required|string|max:200',
            'counterparty_party_id' => 'nullable|uuid|exists:parties,id', 'counterparty_contact' => 'nullable|string|max:255',
            'case_id' => 'nullable|uuid', 'subject_type' => 'nullable|string|max:64', 'subject_id' => 'nullable|uuid|required_with:subject_type',
            'subject_line' => 'required|string|max:255', 'summary' => 'nullable|string|max:10000', 'external_reference' => 'nullable|string|max:120',
            'document_id' => 'nullable|uuid', 'notification_delivery_id' => 'nullable|uuid', 'communication_log_id' => 'nullable|uuid',
            'received_at' => 'nullable|date|before_or_equal:now', 'idempotency_key' => 'nullable|string|max:100',
        ]);
        $d['idempotency_key'] ??= $r->header('Idempotency-Key');
        $existing = ! empty($d['idempotency_key']) && DB::table('correspondence_register')->where('tenant_id', $this->tenant())->where('idempotency_key', $d['idempotency_key'])->exists();

        return response()->json(['data' => $this->svc->register($this->tenant(), $d, $r->user())], $existing ? 200 : 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenant();
        $row = DB::table('correspondence_register')->where('tenant_id', $tenant)->where('id', $id)
            ->where(fn ($q) => $q->whereNull('case_id')->orWhereIn('case_id', WorkCase::query()->where('tenant_id', $tenant)->select('id')))->first();
        abort_unless($row, 404);

        return response()->json(['data' => $row]);
    }

    public function dispatch(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['proof_type' => ['required', Rule::in(CorrespondenceService::PROOF_TYPES)], 'proof_reference' => 'nullable|string|max:160',
            'proof_document_id' => 'nullable|uuid', 'dispatched_at' => 'nullable|date|before_or_equal:now', 'delivered_at' => 'nullable|date|after_or_equal:dispatched_at|before_or_equal:now']);

        return response()->json(['data' => $this->svc->recordDispatch($this->tenant(), $id, $d, $r->user())]);
    }

    public function outcome(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['delivered' => 'required|boolean', 'reason' => 'nullable|string|max:1000']);

        return response()->json(['data' => $this->svc->recordOutcome($this->tenant(), $id, $r->boolean('delivered'), $d['reason'] ?? null, $r->user())]);
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
