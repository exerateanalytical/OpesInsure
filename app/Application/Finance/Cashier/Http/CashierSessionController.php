<?php

declare(strict_types=1);

namespace App\Application\Finance\Cashier\Http;

use App\Application\Finance\Cashier\CashierSessionService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Batch 9-7: REQ-PAY-010 cashier session API. */
final class CashierSessionController
{
    public function __construct(private TenantContext $tenant) {}

    public function index(Request $r): JsonResponse
    {
        $q = DB::table('cashier_sessions')->where('tenant_id', $this->tenant->id())
            ->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($r->query('branch_id'), fn ($q, $b) => $q->where('branch_id', $b));

        return response()->json(['data' => $q->orderByDesc('opened_at')->limit(200)->get()]);
    }

    public function show(string $session, CashierSessionService $s): JsonResponse
    {
        $row = $s->find($this->tenant->id(), $session);

        return response()->json(['data' => [
            'session' => $row, 'totals' => $s->totals($row->id),
            'collections' => DB::table('cashier_collections')->where('cashier_session_id', $row->id)->orderBy('collected_at')->get(),
        ]]);
    }

    public function open(Request $r, CashierSessionService $s): JsonResponse
    {
        $d = $r->validate(['branch_id' => 'required|uuid', 'opening_float_minor' => 'required|integer|min:0', 'currency' => 'nullable|string|size:3']);

        return response()->json(['data' => $s->open($this->tenant->id(), $d['branch_id'], (int) $d['opening_float_minor'], $d['currency'] ?? 'XAF', $r->user())], 201);
    }

    public function collect(Request $r, string $session, CashierSessionService $s): JsonResponse
    {
        $d = $r->validate([
            'method' => 'required|in:'.implode(',', CashierSessionService::METHODS), 'amount_minor' => 'required|integer|min:1', 'currency' => 'nullable|string|size:3',
            'payer_party_id' => 'nullable|uuid', 'payer_name' => 'nullable|string|max:255', 'payment_intent_id' => 'nullable|uuid', 'financial_obligation_id' => 'nullable|uuid',
            'cheque_number' => 'nullable|string|max:64', 'cheque_bank' => 'nullable|string|max:128', 'reference' => 'nullable|string|max:128',
        ]);

        return response()->json(['data' => $s->collect($this->tenant->id(), $session, $d, $r->user())], 201);
    }

    public function close(Request $r, string $session, CashierSessionService $s): JsonResponse
    {
        $d = $r->validate(['counted_cash_minor' => 'required|integer|min:0', 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $s->close($this->tenant->id(), $session, (int) $d['counted_cash_minor'], $d['notes'] ?? null, $r->user())]);
    }

    public function decide(Request $r, string $session, CashierSessionService $s): JsonResponse
    {
        $d = $r->validate(['decision' => 'required|in:APPROVE,REJECT', 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $s->decide($this->tenant->id(), $session, $d['decision'] === 'APPROVE', $d['notes'] ?? null, $r->user())]);
    }
}
