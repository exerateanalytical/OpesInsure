<?php

declare(strict_types=1);

namespace App\Application\Policies\Special\Http;

use App\Application\Policies\Special\CargoDeclarationService;
use App\Application\Policies\Special\LifeSurrenderService;
use App\Application\Policies\Special\PolicyScheduleService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-PRD-011 life & special products API (routes: "Batch 8-6" block in routes/api.php). */
final class SpecialPolicyController
{
    public function __construct(
        private readonly PolicyScheduleService $schedules,
        private readonly CargoDeclarationService $cargo,
        private readonly LifeSurrenderService $surrender,
        private readonly TenantContext $tenant,
    ) {}

    public function createProfile(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate(['kind' => 'required|string|in:'.implode(',', PolicyScheduleService::KINDS), 'terms' => 'nullable|array']);

        return response()->json(['data' => $this->schedules->createProfile($this->tenant->id(), $policy, $d['kind'], $d['terms'] ?? [], $r->user())], 201);
    }

    public function showProfile(string $policy): JsonResponse
    {
        return response()->json(['data' => $this->schedules->profileView($this->tenant->id(), $policy)]);
    }

    public function schedule(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate(['as_of' => 'nullable|date', 'history' => 'nullable|boolean']);

        return response()->json(['data' => $this->schedules->schedule($this->tenant->id(), $policy, $d['as_of'] ?? null, (bool) ($d['history'] ?? false))]);
    }

    public function addItem(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate([
            'item_type' => 'nullable|string|max:24',
            'item_key' => 'required|string|max:100',
            'display_name' => 'required|string|max:191',
            'category' => 'nullable|string|max:64',
            'party_id' => 'nullable|uuid',
            'risk_asset_id' => 'nullable|uuid',
            'facts' => 'nullable|array',
            'sum_insured_minor' => 'nullable|integer|min:0',
            'annual_premium_minor' => 'nullable|integer|min:0',
            'effective_from' => 'required|date',
        ]);

        return response()->json(['data' => $this->schedules->addItem($this->tenant->id(), $policy, $d, $r->user())], 201);
    }

    public function removeItem(Request $r, string $item): JsonResponse
    {
        $d = $r->validate(['effective_until' => 'required|date', 'reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->schedules->removeItem($this->tenant->id(), $item, \Carbon\CarbonImmutable::parse($d['effective_until'])->toDateString(), $d['reason'], $r->user())]);
    }

    public function declarations(string $policy): JsonResponse
    {
        return response()->json(['data' => $this->cargo->list($this->tenant->id(), $policy)]);
    }

    public function declare(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate([
            'conveyance' => 'required|string|in:'.implode(',', CargoDeclarationService::CONVEYANCES),
            'goods_description' => 'required|string|max:500',
            'origin' => 'required|string|max:120',
            'destination' => 'required|string|max:120',
            'shipment_date' => 'required|date',
            'insured_value_minor' => 'required|integer|min:1',
            'facts' => 'nullable|array',
        ]);

        return response()->json(['data' => $this->cargo->declare($this->tenant->id(), $policy, $d, $r->user())], 201);
    }

    public function cancelDeclaration(Request $r, string $declaration): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->cargo->cancel($this->tenant->id(), $declaration, $d['reason'], $r->user())]);
    }

    public function createScale(Request $r): JsonResponse
    {
        $d = $r->validate([
            'insurance_product_id' => 'required|uuid',
            'basis' => 'nullable|string|in:'.implode(',', LifeSurrenderService::BASES),
            'min_years_in_force' => 'nullable|integer|min:0|max:50',
            'factors_bps' => 'required|array|min:1',
            'factors_bps.*' => 'integer|min:0|max:10000',
            'surrender_charge_bps' => 'nullable|integer|min:0|max:10000',
            'source_reference' => 'nullable|string|max:191',
        ]);

        return response()->json(['data' => $this->surrender->createScale($this->tenant->id(), $d, $r->user())], 201);
    }

    public function activateScale(Request $r, string $scale): JsonResponse
    {
        return response()->json(['data' => $this->surrender->activateScale($this->tenant->id(), $scale, $r->user())]);
    }

    public function surrenderQuote(Request $r, string $policy): JsonResponse
    {
        $d = $r->validate([
            'as_of' => 'nullable|date',
            'basis_minor' => 'required|integer|min:0',
            'loans_outstanding_minor' => 'nullable|integer|min:0',
            'reason' => 'nullable|string|max:64',
        ]);

        return response()->json(['data' => $this->surrender->quote($this->tenant->id(), $policy, $d, $r->user())], 201);
    }
}
