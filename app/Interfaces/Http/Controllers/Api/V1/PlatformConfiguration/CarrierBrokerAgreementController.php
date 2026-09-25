<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\PlatformConfiguration;

use App\Application\CarrierOperations\Agreements\CarrierBrokerAgreementService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * REQ-SEED-004 / REQ-DUP-023 — carrier ↔ broker distribution agreements and product permissions.
 * The platform tenant sees every agreement; any other tenant only agreements of its own partners.
 */
final class CarrierBrokerAgreementController
{
    public function __construct(private readonly CarrierBrokerAgreementService $agreements, private readonly TenantContext $tenant) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['carrier_id' => 'nullable|uuid', 'partner_id' => 'nullable|uuid', 'status' => 'nullable|string|max:24']);
        $q = DB::table('carrier_broker_agreements as a')->join('partners as p', 'p.id', '=', 'a.partner_id')
            ->when(! $this->isPlatform(), fn ($q) => $q->where('p.tenant_id', $this->tenant->id()))
            ->when($d['carrier_id'] ?? null, fn ($q, $v) => $q->where('a.carrier_id', $v))
            ->when($d['partner_id'] ?? null, fn ($q, $v) => $q->where('a.partner_id', $v))
            ->when($d['status'] ?? null, fn ($q, $v) => $q->where('a.status', $v))
            ->orderByDesc('a.effective_from')->select('a.*');

        return response()->json(['data' => $q->limit(500)->get()]);
    }

    public function show(string $agreement): JsonResponse
    {
        $this->authorizeAgreement($agreement);

        return response()->json(['data' => $this->agreements->find($agreement)]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate(['carrier_id' => 'required|uuid|exists:carriers,id', 'partner_id' => 'required|uuid|exists:partners,id',
            'agreement_number' => 'required|string|max:80|unique:carrier_broker_agreements,agreement_number', 'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from', 'territories' => 'sometimes|array', 'territories.*' => 'string|max:32',
            'channels' => 'sometimes|array', 'channels.*' => 'string|max:32']);
        $this->authorizePartner($d['partner_id']);

        return response()->json(['data' => $this->agreements->create($r->user(), $d)], 201);
    }

    public function setProduct(Request $r, string $agreement): JsonResponse
    {
        $this->authorizeAgreement($agreement);
        $d = $r->validate(['line_code' => 'required|string|max:32', 'insurance_product_id' => 'nullable|uuid', 'can_quote' => 'sometimes|boolean', 'can_bind' => 'sometimes|boolean',
            'can_collect_premium' => 'sometimes|boolean', 'requires_carrier_approval' => 'sometimes|boolean', 'commission_rule_version_id' => 'nullable|uuid',
            'commission_basis_points' => 'nullable|integer|min:0|max:10000', 'status' => 'sometimes|in:ACTIVE,INACTIVE', 'reason' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->agreements->setProduct($r->user(), $agreement, $d)]);
    }

    public function transition(Request $r, string $agreement, string $action): JsonResponse
    {
        $this->authorizeAgreement($agreement);
        $d = $r->validate(['reason' => 'required|string|min:5|max:2000']);
        $to = ['activate' => 'ACTIVE', 'suspend' => 'SUSPENDED', 'terminate' => 'TERMINATED'][$action];

        return response()->json(['data' => $this->agreements->transition($r->user(), $agreement, $to, $d['reason'])]);
    }

    public function permits(Request $r): JsonResponse
    {
        $d = $r->validate(['partner_id' => 'required|uuid', 'carrier_id' => 'required|uuid', 'line_code' => 'required|string|max:32', 'insurance_product_id' => 'nullable|uuid',
            'action' => 'required|in:'.implode(',', array_keys(CarrierBrokerAgreementService::ACTIONS)), 'on' => 'nullable|date']);
        $this->authorizePartner($d['partner_id']);

        return response()->json(['data' => $this->agreements->permits($d['partner_id'], $d['carrier_id'], $d['line_code'], $d['insurance_product_id'] ?? null, $d['action'], $d['on'] ?? null)]);
    }

    private function authorizeAgreement(string $agreement): void
    {
        $partnerId = DB::table('carrier_broker_agreements')->where('id', $agreement)->value('partner_id');
        abort_if($partnerId === null, 404);
        $this->authorizePartner($partnerId);
    }

    private function authorizePartner(string $partnerId): void
    {
        if (! $this->isPlatform()) {
            abort_unless(DB::table('partners')->where(['id' => $partnerId, 'tenant_id' => $this->tenant->id()])->exists(), 404);
        }
    }

    private function isPlatform(): bool
    {
        return DB::table('tenants')->where('id', $this->tenant->id())->value('type') === 'PLATFORM';
    }
}
