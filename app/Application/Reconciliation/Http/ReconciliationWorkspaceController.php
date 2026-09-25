<?php

declare(strict_types=1);

namespace App\Application\Reconciliation\Http;

use App\Application\Reconciliation\ManualMatchService;
use App\Application\Reconciliation\OutcomeClassifier;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Batch 9-5 — REQ-PAY-007 unmatched-items workspace API. */
final class ReconciliationWorkspaceController
{
    public function __construct(private TenantContext $tenant, private ManualMatchService $matches) {}

    public function search(Request $r): JsonResponse
    {
        $f = $r->validate([
            'status' => 'nullable|in:EXCEPTION,MATCHED,IGNORED,ADJUSTMENT_REQUIRED', 'outcome' => ['nullable', Rule::in(OutcomeClassifier::OUTCOMES)],
            'reference' => 'nullable|string|max:160', 'import_id' => 'nullable|uuid', 'min_minor' => 'nullable|integer|min:0', 'max_minor' => 'nullable|integer|min:0',
            'refund_candidate' => 'nullable|boolean', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json($this->matches->search($this->tenant->id(), $f));
    }

    public function candidates(string $item): JsonResponse
    {
        $i = $this->matches->item($this->tenant->id(), $item);

        return response()->json(['data' => ['item' => $i, 'candidates' => $this->matches->candidates($this->tenant->id(), $i)]]);
    }

    public function requestMatch(Request $r, string $item): JsonResponse
    {
        $d = $r->validate(['matched_id' => 'required|uuid', 'notes' => 'required|string|min:20|max:2000']);

        return response()->json(['data' => $this->matches->request($this->tenant->id(), $this->matches->item($this->tenant->id(), $item), $d, $r->user())], 201);
    }

    public function decideMatch(Request $r, string $match): JsonResponse
    {
        $d = $r->validate(['decision' => 'required|in:APPROVE,REJECT', 'note' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->matches->decide($this->tenant->id(), $match, $d['decision'] === 'APPROVE', $d['note'], $r->user())]);
    }
}
