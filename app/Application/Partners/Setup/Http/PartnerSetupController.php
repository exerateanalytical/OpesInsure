<?php

declare(strict_types=1);

namespace App\Application\Partners\Setup\Http;

use App\Application\Partners\Setup\Models\PartnerSetup;
use App\Application\Partners\Setup\PartnerSetupService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-SET-003 API: broker setup lifecycle, 19-item checklist, attestations, transitions (tenant-scoped). */
final class PartnerSetupController
{
    public function __construct(private readonly PartnerSetupService $setups) {}

    public function show(Request $r, string $partner): JsonResponse
    {
        return response()->json($this->payload($this->setupFor($r, $partner), $r));
    }

    public function store(Request $r, string $partner): JsonResponse
    {
        $d = $r->validate(['notes' => 'nullable|string|max:2000']);
        $setup = $this->setups->open($this->partner($r, $partner), $r->user(), $d['notes'] ?? null);

        return response()->json($this->payload($setup, $r), 201);
    }

    public function attest(Request $r, string $partner, string $item): JsonResponse
    {
        $d = $r->validate(['status' => 'required|in:COMPLETE,NOT_APPLICABLE,PENDING', 'evidence_reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000']);

        return response()->json(['data' => $this->setups->attest($this->setupFor($r, $partner), strtoupper($item), $d['status'], $d['evidence_reference'] ?? null, $d['notes'] ?? null, $r->user())]);
    }

    public function transition(Request $r, string $partner): JsonResponse
    {
        $d = $r->validate(['event' => 'required|string|max:48', 'reason' => 'required|string|min:5|max:2000']);

        return response()->json($this->payload($this->setups->transition($this->setupFor($r, $partner), $d['event'], $r->user(), $d['reason']), $r));
    }

    private function setupFor(Request $r, string $partner): PartnerSetup
    {
        return PartnerSetup::where('partner_id', $this->partner($r, $partner)->id)->firstOrFail();
    }

    /** Same visibility rule as PartnerController: own tenant, or a platform/compliance administrator. */
    private function partner(Request $r, string $id): Partner
    {
        $p = Partner::findOrFail($id);
        $global = $r->user()->memberships()->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'])->exists();
        abort_unless($global || $p->tenant_id === app(TenantContext::class)->id(), 404);

        return $p;
    }

    private function payload(PartnerSetup $setup, Request $r): array
    {
        return ['data' => $setup, 'meta' => ['checklist' => $this->setups->evaluate($setup), 'available_events' => $this->setups->available($setup, $r->user()),
            // Gap Closure Pack file 11: private datasets this organization must supply (staged via the private_onboarding import).
            'private_onboarding' => app(\App\Application\PrivateOnboarding\PrivateOnboardingService::class)->readiness('BROKER', $setup->partner_id)]];
    }
}
