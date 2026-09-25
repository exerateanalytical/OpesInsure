<?php

declare(strict_types=1);

namespace App\Application\Commissions\Statements\Http;

use App\Application\Commissions\Statements\CommissionStatementService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Partner;
use App\Models\PartnerStatement;
use App\Models\PartnerStatementItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** REQ-COM-003 — commission statement generation, adjustments (maker-checker) and disputes. */
final class CommissionStatementController extends Controller
{
    public function __construct(private readonly CommissionStatementService $service) {}

    public function generate(Request $r): JsonResponse
    {
        $d = $r->validate(['partner_id' => 'nullable|uuid', 'period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start',
            'currency' => 'required|string|size:3', 'opening_balance_minor' => 'integer']);
        $tenant = $this->tenant();
        if (! empty($d['partner_id'])) {
            abort_unless(Partner::where('id', $d['partner_id'])->where('tenant_id', $tenant)->exists(), 404);

            return response()->json($this->service->generate($tenant, $d['partner_id'], $d['period_start'], $d['period_end'], $d['currency'], $r->user(), (int) ($d['opening_balance_minor'] ?? 0)), 201);
        }

        return response()->json(['data' => $this->service->generatePeriod($tenant, $d['period_start'], $d['period_end'], $d['currency'], $r->user())], 201);
    }

    public function propose(Request $r, PartnerStatement $statement): JsonResponse
    {
        $this->owns($statement);
        $d = $r->validate(['amount_minor' => 'required|integer|not_in:0', 'reason' => 'required|string|max:1000']);

        return response()->json($this->service->proposeAdjustment($statement, (int) $d['amount_minor'], $d['reason'], $r->user()), 201);
    }

    public function approve(Request $r, PartnerStatementItem $item): JsonResponse
    {
        $this->ownsItem($item);

        return response()->json($this->service->approveAdjustment($item, $r->user()));
    }

    public function reject(Request $r, PartnerStatementItem $item): JsonResponse
    {
        $this->ownsItem($item);

        return response()->json($this->service->rejectAdjustment($item, $r->user(), $r->validate(['reason' => 'required|string|max:1000'])['reason']));
    }

    public function dispute(Request $r, PartnerStatement $statement): JsonResponse
    {
        $this->owns($statement);

        return response()->json($this->service->dispute($statement, $r->validate(['reason' => 'required|string|max:2000'])['reason'], $r->user()));
    }

    public function resolve(Request $r, PartnerStatement $statement): JsonResponse
    {
        $this->owns($statement);

        return response()->json($this->service->resolveDispute($statement, $r->validate(['resolution' => 'required|string|max:2000'])['resolution'], $r->user()));
    }

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function owns(PartnerStatement $s): void
    {
        abort_unless($s->tenant_id === $this->tenant(), 404);
    }

    private function ownsItem(PartnerStatementItem $i): void
    {
        abort_unless($i->entry_type === 'ADJUSTMENT' && PartnerStatement::where('id', $i->partner_statement_id)->where('tenant_id', $this->tenant())->exists(), 404);
    }
}
