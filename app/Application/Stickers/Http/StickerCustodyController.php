<?php

declare(strict_types=1);

namespace App\Application\Stickers\Http;

use App\Application\Stickers\StickerCustodyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use App\Models\StickerStock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** REQ-POL-007 — sticker custody chain API: inventory, handovers (allocate / return), reconciliation, assign-to-policy. */
final class StickerCustodyController
{
    public function __construct(private readonly StickerCustodyService $stickers) {}

    public function inventory(Request $r): JsonResponse
    {
        $d = $r->validate(['carrier_id' => 'required|uuid|exists:carriers,id']);

        return response()->json(['data' => $this->stickers->inventory($d['carrier_id'], $this->tenantId())]);
    }

    public function history(string $serial): JsonResponse
    {
        $s = StickerStock::where('serial_number', $serial)->firstOrFail();
        $visible = DB::table('sticker_custody_events')->where('sticker_stock_id', $s->id)
            ->where(fn ($q) => $q->where('from_tenant_id', $this->tenantId())->orWhere('to_tenant_id', $this->tenantId()))->exists();
        abort_unless($visible || $s->custodian_tenant_id === $this->tenantId(), 404);

        return response()->json(['data' => ['sticker' => $s->only(['id', 'serial_number', 'carrier_id', 'batch_number', 'status', 'custody_level', 'custodian_tenant_id',
            'custodian_branch_id', 'custodian_user_id', 'assigned_policy_id', 'assigned_at']),
            'events' => DB::table('sticker_custody_events')->where('sticker_stock_id', $s->id)->orderBy('occurred_at')->get()]]);
    }

    public function handovers(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => ['nullable', Rule::in(['PENDING', 'ACCEPTED', 'REJECTED', 'CANCELLED'])]]);
        $rows = DB::table('sticker_handovers')->where(fn ($q) => $q->where('from_tenant_id', $this->tenantId())->orWhere('to_tenant_id', $this->tenantId()))
            ->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $rows]);
    }

    public function initiate(Request $r): JsonResponse
    {
        $d = $r->validate([
            'carrier_id' => 'required|uuid|exists:carriers,id',
            'from' => 'required|array', 'from.level' => ['required', Rule::in(StickerCustodyService::LEVELS)], 'from.branch_id' => 'nullable|uuid', 'from.user_id' => 'nullable|uuid',
            'to' => 'required|array', 'to.level' => ['required', Rule::in(StickerCustodyService::LEVELS)], 'to.branch_id' => 'nullable|uuid', 'to.user_id' => 'nullable|uuid',
            'serial_numbers' => 'required|array|min:1|max:5000', 'serial_numbers.*' => 'required|string|max:100|distinct',
            'notes' => 'nullable|string|max:1000',
        ]);
        // Releasing carrier-held stock (or returning to the carrier) is a carrier-side act.
        if (($d['from']['level'] === 'CARRIER' || $d['to']['level'] === 'CARRIER') && ! $r->user()->hasPermission('stickers.allocate.carrier')) {
            throw new AuthorizationException('Allocating or returning carrier sticker stock requires stickers.allocate.carrier.');
        }
        $from = $this->stickers->holder($d['from'], $this->tenantId());
        $to = $this->stickers->holder($d['to'], $this->tenantId());
        // An agent hands back only their own stock unless they manage the chain.
        if ($from['level'] === 'AGENT' && $from['user_id'] !== $r->user()->id && ! $r->user()->hasPermission('stickers.allocate')) {
            throw new AuthorizationException('Only the agent holding these stickers can return them.');
        }
        if ($from['level'] !== 'AGENT' && ! $r->user()->hasPermission('stickers.allocate')) {
            throw new AuthorizationException('Allocating stickers requires stickers.allocate.');
        }

        return response()->json(['data' => $this->stickers->initiateHandover($d['carrier_id'], $from, $to, $d['serial_numbers'], $r->user(), $d['notes'] ?? null)], 201);
    }

    public function accept(Request $r, string $handover): JsonResponse
    {
        return response()->json(['data' => $this->stickers->accept($handover, $r->user(), $this->tenantId())]);
    }

    public function reject(Request $r, string $handover): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->stickers->reject($handover, $r->user(), $this->tenantId(), $d['reason'])]);
    }

    public function cancel(Request $r, string $handover): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:500']);

        return response()->json(['data' => $this->stickers->reject($handover, $r->user(), $this->tenantId(), $d['reason'], true)]);
    }

    public function reconcile(Request $r): JsonResponse
    {
        $d = $r->validate([
            'carrier_id' => 'required|uuid|exists:carriers,id',
            'holder' => 'required|array', 'holder.level' => ['required', Rule::in(['BROKER', 'BRANCH', 'AGENT'])], 'holder.branch_id' => 'nullable|uuid', 'holder.user_id' => 'nullable|uuid',
            'counted_serials' => 'present|array|max:5000', 'counted_serials.*' => 'string|max:100|distinct',
            'damaged_serials' => 'nullable|array', 'damaged_serials.*' => 'string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);
        $holder = $this->stickers->holder($d['holder'], $this->tenantId());

        return response()->json(['data' => $this->stickers->reconcile($d['carrier_id'], $holder, $d['counted_serials'], $d['damaged_serials'] ?? [], $r->user(), $d['notes'] ?? null)], 201);
    }

    public function assign(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate(['serial_number' => 'required|string|max:100']);
        $p = Policy::where('tenant_id', $this->tenantId())->findOrFail($policy);
        $s = $this->stickers->assignToPolicy($p, $d['serial_number'], $r->user());

        return response()->json(['data' => $s->only(['id', 'serial_number', 'status', 'custody_level', 'assigned_policy_id', 'assigned_at'])], 201);
    }

    private function tenantId(): string
    {
        return app(TenantContext::class)->id();
    }
}
