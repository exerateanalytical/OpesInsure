<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Ledger;

use App\Application\Ledger\Journals\ManualJournalService;
use App\Application\Ledger\Journals\TrialBalanceService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Batch 10-7 REQ-ACC-002: manual journal maker-checker lifecycle and trial balance. */
final class ManualJournalController
{
    public function __construct(private ManualJournalService $journals) {}

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'reference_type' => 'required|string|max:64', 'reference_id' => 'required|uuid', 'currency' => 'required|string|size:3',
            'reason_code' => 'required|string|max:64', 'description' => 'nullable|string|max:500', 'journal_date' => 'nullable|date_format:Y-m-d',
            'lines' => 'required|array|min:2|max:50', 'lines.*.account_id' => 'required|uuid|exists:ledger_accounts,id',
            'lines.*.debit_minor' => 'required_without:lines.*.credit_minor|integer|min:0', 'lines.*.credit_minor' => 'required_without:lines.*.debit_minor|integer|min:0',
            'lines.*.dimensions' => 'nullable|array',
        ]);
        $id = $this->journals->createDraft($this->tenant(), $r->user()->id, $d, $r->header('X-Request-Id', (string) Str::uuid()));

        return $this->show($id, 201);
    }

    public function validateJournal(Request $r, string $journal): JsonResponse
    {
        $this->journals->validate($this->tenant(), $journal, $r->user()->id);

        return $this->show($journal);
    }

    public function approve(Request $r, string $journal): JsonResponse
    {
        $this->journals->approve($this->tenant(), $journal, $r->user()->id);

        return $this->show($journal);
    }

    public function reject(Request $r, string $journal): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64']);
        $this->journals->reject($this->tenant(), $journal, $r->user()->id, $d['reason_code']);

        return $this->show($journal);
    }

    public function post(Request $r, string $journal): JsonResponse
    {
        $this->journals->post($this->tenant(), $journal, $r->user()->id);

        return $this->show($journal);
    }

    public function reverse(Request $r, string $journal): JsonResponse
    {
        $d = $r->validate(['reason_code' => 'required|string|max:64', 'notes' => 'required|string|min:20|max:2000']);
        $mirror = $this->journals->reverse($this->tenant(), $journal, $r->user()->id, $d['reason_code'], $r->header('X-Request-Id', (string) Str::uuid()));

        return response()->json(['data' => ['original_id' => $journal, 'reversal_id' => $mirror, 'status' => 'REVERSED']]);
    }

    public function trialBalance(Request $r, TrialBalanceService $tb): JsonResponse
    {
        $d = $r->validate(['currency' => 'nullable|string|size:3', 'as_of' => 'nullable|date_format:Y-m-d']);

        return response()->json(['data' => $tb->compute($this->tenant(), $d['currency'] ?? 'XAF', $d['as_of'] ?? null)]);
    }

    private function show(string $id, int $status = 200): JsonResponse
    {
        $row = DB::table('journals')->where('id', $id)->where('tenant_id', $this->tenant())->first();
        abort_unless($row, 404);

        return response()->json(['data' => ['journal' => $row, 'lines' => DB::table('journal_lines')->where('journal_id', $id)->get()]], $status);
    }

    private function tenant(): string
    {
        $id = app(TenantContext::class)->id();
        abort_unless($id, 403);

        return $id;
    }
}
