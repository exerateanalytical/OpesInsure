<?php

declare(strict_types=1);

namespace App\Application\Claims\Limits\Http;

use App\Application\Claims\Limits\LimitLedger;
use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Batch 11 C4 — REQ-CLM-004 read API over policy limits and the limit movement ledger. */
final class LimitLedgerController
{
    public function __construct(private TenantContext $tenant, private LimitLedger $ledger, private PolicyChronologyWriter $chronology) {}

    /** GET v1/policies/{policy}/limits?coverage=RC&as_of=… — all limits (or one coverage) at a business date. */
    public function policy(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate(['coverage' => 'nullable|string|max:64', 'as_of' => 'nullable|date']);
        $p = Policy::where('tenant_id', $this->tenant->id())->whereKey($policy)->first() ?? abort(404);
        $asOf = isset($d['as_of']) ? Carbon::parse($d['as_of']) : now();

        if (isset($d['coverage'])) {
            return response()->json(['data' => $this->ledger->remaining($p, $d['coverage'], $asOf)]);
        }
        $version = $this->chronology->asOf($p->id, $asOf);
        $limits = $version === null ? [] : DB::table('policy_limits')->where('policy_version_id', $version->id)->where('limit_type', '<>', 'DEDUCTIBLE')
            ->orderBy('limit_type')->orderBy('id')->get()->map(fn ($l) => $this->ledger->present($l))->all();

        return response()->json(['data' => ['policy_version_id' => $version?->id, 'limits' => $limits]]);
    }

    /** GET v1/claims/{claim}/limits — the claim's per-limit balances and its movement history. */
    public function claim(string $claim): JsonResponse
    {
        $c = Claim::where('tenant_id', $this->tenant->id())->whereKey($claim)->first() ?? abort(404);
        $movements = DB::table('policy_limit_movements')->where('claim_id', $c->id)->orderBy('occurred_at')->orderBy('id')
            ->get(['id', 'group_id', 'policy_limit_id', 'movement_type', 'amount_minor', 'reserved_delta_minor', 'consumed_delta_minor',
                'reserved_after_minor', 'consumed_after_minor', 'currency', 'reverses_movement_id', 'reference_type', 'reference_id', 'reason', 'occurred_at']);

        return response()->json(['data' => ['claim_id' => $c->id, 'balances' => $this->ledger->claimBalances($c), 'movements' => $movements]]);
    }
}
