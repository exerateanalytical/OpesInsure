<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Compliance;

use App\Application\Compliance\Governance\GovernanceRegisterService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Controllers\Concerns\AuthorizesSensitiveActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** REQ-CMP-003 ICT + vendor/outsourcing governance registers (structure only, OQ-8.1). */
final class GovernanceRegisterController
{
    use AuthorizesSensitiveActions;

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    public function index(Request $r, string $register, GovernanceRegisterService $s): JsonResponse
    {
        $this->authorizeRoutePermission($r, 'governance_register');

        return response()->json(['data' => $s->list($register, $this->tenant(), $r->integer('per_page', 50))]);
    }

    public function show(Request $r, string $register, string $id, GovernanceRegisterService $s): JsonResponse
    {
        $this->authorizeRoutePermission($r, 'governance_register', $id);

        return response()->json(['data' => $s->find($register, $this->tenant(), $id)]);
    }

    public function store(Request $r, string $register, GovernanceRegisterService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'governance_register');

        return response()->json(['data' => $this->auditedCall(fn () => $s->create($register, $this->tenant(), $r->all(), $r->user()), $perm, $s->table($register), null)], 201);
    }

    public function update(Request $r, string $register, string $id, GovernanceRegisterService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'governance_register', $id);

        return response()->json(['data' => $this->auditedCall(fn () => $s->update($register, $this->tenant(), $id, $r->all(), $r->user()), $perm, $s->table($register), $id)]);
    }

    public function approveExitPlan(Request $r, string $id, GovernanceRegisterService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'governance_exit_plans', $id);

        return response()->json(['data' => $this->auditedCall(fn () => $s->approveExitPlan($this->tenant(), $id, $r->user()), $perm, 'governance_exit_plans', $id)]);
    }
}
