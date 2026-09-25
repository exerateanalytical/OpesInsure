<?php

declare(strict_types=1);

namespace App\Application\Attribution\Http;

use App\Application\Attribution\PolicyAttributionResolver;
use App\Application\Attribution\PortfolioTransferService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** REQ-CRM-003 — portfolio transfer (preview + execute + history) and commission-ready policy attribution. */
final class PortfolioController
{
    public function __construct(private readonly PortfolioTransferService $transfers, private readonly PolicyAttributionResolver $resolver) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function input(Request $r, bool $execute): array
    {
        $rules = ['from_partner_id' => 'required|uuid', 'to_partner_id' => 'required|uuid', 'party_ids' => 'sometimes|nullable|array|max:5000', 'party_ids.*' => 'uuid'];
        if ($execute) {
            $rules += ['preview_hash' => 'required|string|size:64', 'reason_code' => ['required', Rule::in(PortfolioTransferService::REASONS)], 'notes' => 'nullable|string|max:2000'];
        }

        return $r->validate($rules);
    }

    public function preview(Request $r): JsonResponse
    {
        $d = $this->input($r, false);

        return response()->json(['data' => $this->transfers->preview($this->tenant(), $d['from_partner_id'], $d['to_partner_id'], $d['party_ids'] ?? null)]);
    }

    public function execute(Request $r): JsonResponse
    {
        $d = $this->input($r, true);
        $t = $this->transfers->execute($this->tenant(), $d['from_partner_id'], $d['to_partner_id'], $d['party_ids'] ?? null, $d['preview_hash'], $d['reason_code'], $d['notes'] ?? null, $r->user());

        return response()->json(['data' => self::transfer($t)], 201);
    }

    public function index(Request $r): JsonResponse
    {
        $partner = $r->query('partner_id');
        $rows = DB::table('portfolio_transfers')->where('tenant_id', $this->tenant())
            ->when(is_string($partner) && Str::isUuid($partner), fn ($q) => $q->where(fn ($w) => $w->where('from_partner_id', $partner)->orWhere('to_partner_id', $partner)))
            ->orderByDesc('executed_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn ($t) => self::transfer($t))->values()]);
    }

    public function show(string $transfer): JsonResponse
    {
        $t = Str::isUuid($transfer) ? DB::table('portfolio_transfers')->where('tenant_id', $this->tenant())->where('id', $transfer)->first() : null;
        abort_unless($t, 404);

        return response()->json(['data' => self::transfer($t) + ['party_ids' => json_decode($t->party_ids, true)]]);
    }

    /** Ownership history of one customer within this tenant (origin lock + every change). */
    public function customerHistory(string $party): JsonResponse
    {
        abort_unless(Str::isUuid($party), 404);
        $attributions = DB::table('customer_attributions as a')->join('partners as p', 'p.id', '=', 'a.partner_id')
            ->where('a.party_id', $party)->where('p.tenant_id', $this->tenant())->get(['a.*']);
        abort_if($attributions->isEmpty(), 404);
        $events = DB::table('attribution_events')->whereIn('attribution_id', $attributions->pluck('id'))->orderBy('occurred_at')->get();

        return response()->json(['data' => ['attributions' => $attributions, 'events' => $events]]);
    }

    public function policyAttribution(string $policy): JsonResponse
    {
        $p = Str::isUuid($policy) ? Policy::where('tenant_id', $this->tenant())->find($policy) : null;
        abort_unless($p, 404);

        return response()->json(['data' => ['policy_id' => $p->id] + $this->resolver->forPolicy($p)]);
    }

    private static function transfer(object $t): array
    {
        return ['id' => $t->id, 'from_partner_id' => $t->from_partner_id, 'to_partner_id' => $t->to_partner_id, 'status' => $t->status, 'reason_code' => $t->reason_code,
            'notes' => $t->notes, 'customer_count' => (int) $t->customer_count, 'policy_count' => (int) $t->policy_count, 'executed_by' => $t->executed_by,
            'executed_at' => Carbon::parse($t->executed_at)->toIso8601String()];
    }
}
