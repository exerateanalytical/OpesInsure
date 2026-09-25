<?php

declare(strict_types=1);

namespace App\Application\Policies\Portability\Http;

use App\Application\Policies\Portability\PolicyPortabilityExportService;
use App\Application\Policies\Portability\PolicyPortfolioTransferService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** REQ-POL-009 — policy portfolio transfer (maker-checker, notice/consent) and portability export packs. */
final class PolicyPortabilityController
{
    public function __construct(private readonly PolicyPortfolioTransferService $transfers, private readonly PolicyPortabilityExportService $exports) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function selection(Request $r): array
    {
        return $r->validate([
            'scope' => ['required', Rule::in(PolicyPortfolioTransferService::SCOPES)], 'from_id' => 'required|uuid', 'to_id' => 'required|uuid',
            'policy_ids' => 'sometimes|nullable|array|max:5000', 'policy_ids.*' => 'uuid',
        ]);
    }

    public function preview(Request $r): JsonResponse
    {
        $d = $this->selection($r);

        return response()->json(['data' => $this->transfers->preview($this->tenant(), $d['scope'], $d['from_id'], $d['to_id'], $d['policy_ids'] ?? null)]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $this->selection($r) + $r->validate([
            'preview_hash' => 'required|string|size:64', 'reason_code' => ['required', Rule::in(PolicyPortfolioTransferService::REASONS)],
            'notice_mode' => ['required', Rule::in(['NOTICE', 'CONSENT'])], 'effective_at' => 'nullable|date', 'notes' => 'nullable|string|max:2000',
        ]);

        return response()->json(['data' => $this->transfers->request($this->tenant(), $d, $r->user())], 201);
    }

    public function index(Request $r): JsonResponse
    {
        $status = $r->query('status');
        $rows = DB::table('policy_portfolio_transfers')->where('tenant_id', $this->tenant())
            ->when(is_string($status), fn ($q) => $q->where('status', $status))->orderByDesc('requested_at')->limit(200)->get();

        return response()->json(['data' => $rows]);
    }

    public function show(string $transfer): JsonResponse
    {
        return response()->json(['data' => $this->transfers->find($this->tenant(), $transfer)]);
    }

    public function approve(Request $r, string $transfer): JsonResponse
    {
        $d = $r->validate(['notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->transfers->approve($this->tenant(), $transfer, $d['notes'] ?? null, $r->user())]);
    }

    public function reject(Request $r, string $transfer): JsonResponse
    {
        $d = $r->validate(['notes' => 'required|string|min:3|max:2000']);

        return response()->json(['data' => $this->transfers->reject($this->tenant(), $transfer, $d['notes'], $r->user())]);
    }

    public function consent(Request $r, string $transfer, string $policy): JsonResponse
    {
        abort_unless(Str::isUuid($transfer) && Str::isUuid($policy), 404);
        $d = $r->validate(['granted' => 'required|boolean', 'evidence' => 'required|string|min:3|max:255']);

        return response()->json(['data' => $this->transfers->recordConsent($this->tenant(), $transfer, $policy, (bool) $d['granted'], $d['evidence'], $r->user())]);
    }

    public function servicingHistory(string $policy): JsonResponse
    {
        $p = $this->policy($policy);

        return response()->json(['data' => $this->transfers->servicingHistory($this->tenant(), $p->id)]);
    }

    public function export(Request $r, string $policy): JsonResponse
    {
        $p = $this->policy($policy);
        $d = $r->validate([
            'purpose' => ['required', Rule::in(PolicyPortabilityExportService::PURPOSES)],
            'consent_reference' => 'required|string|min:3|max:191', 'recipient' => 'nullable|string|max:191',
        ]);
        $e = $this->exports->export($p, $d['purpose'], $d['consent_reference'], $d['recipient'] ?? null, $r->user());

        return response()->json(['data' => self::exportRow($e) + ['pack' => json_decode($e->pack, true)]], 201);
    }

    public function exports(string $policy): JsonResponse
    {
        $p = $this->policy($policy);
        $rows = DB::table('policy_portability_exports')->where('tenant_id', $this->tenant())->where('policy_id', $p->id)->orderByDesc('created_at')->get();

        return response()->json(['data' => $rows->map(fn ($e) => self::exportRow($e))->values()]);
    }

    public function pack(string $export): JsonResponse
    {
        $e = $this->exportRecord($export);

        return response()->json(['data' => self::exportRow($e) + ['pack' => json_decode($e->pack, true)]]);
    }

    public function pdf(string $export): StreamedResponse
    {
        $e = $this->exportRecord($export);
        abort_unless(Storage::disk('local')->exists($e->pdf_path), 404);

        return Storage::disk('local')->download($e->pdf_path, 'portability-'.$e->id.'.pdf', ['Content-Type' => 'application/pdf', 'X-Content-SHA256' => $e->pdf_sha256]);
    }

    private function policy(string $id): Policy
    {
        $p = Str::isUuid($id) ? Policy::where('tenant_id', $this->tenant())->find($id) : null;
        abort_unless($p, 404);

        return $p;
    }

    private function exportRecord(string $id): object
    {
        $e = Str::isUuid($id) ? DB::table('policy_portability_exports')->where('tenant_id', $this->tenant())->where('id', $id)->first() : null;
        abort_unless($e, 404);

        return $e;
    }

    private static function exportRow(object $e): array
    {
        return ['id' => $e->id, 'policy_id' => $e->policy_id, 'purpose' => $e->purpose, 'consent_reference' => $e->consent_reference, 'recipient' => $e->recipient,
            'schema_version' => (int) $e->schema_version, 'pack_sha256' => $e->pack_sha256, 'pdf_sha256' => $e->pdf_sha256, 'requested_by' => $e->requested_by, 'created_at' => $e->created_at];
    }
}
