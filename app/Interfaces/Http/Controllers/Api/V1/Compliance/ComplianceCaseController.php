<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Compliance;

use App\Application\Compliance\Cases\ComplianceCaseService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Controllers\Concerns\AuthorizesSensitiveActions;
use App\Models\ComplianceCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-CMP-001 canonical compliance/cases endpoints. trust/compliance-cases* are deprecated aliases of
 * open() / transition() (REQ-DUP-009) and keep their bare-model response shape.
 */
final class ComplianceCaseController
{
    use AuthorizesSensitiveActions;

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function case(string $id): ComplianceCase
    {
        return ComplianceCase::where('id', $id)->where('tenant_id', $this->tenant())->first() ?? abort(404);
    }

    public function open(Request $r, ComplianceCaseService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'compliance_case');
        $d = $r->validate(['type' => 'required|string|max:48', 'subject_type' => 'required|string|max:64', 'subject_id' => 'required|uuid',
            'severity' => 'required|in:LOW,MEDIUM,HIGH,CRITICAL', 'owner_id' => 'nullable|uuid', 'review_due_on' => 'nullable|date', 'findings' => 'array',
            'idempotency_key' => 'required|string|max:100']);
        $x = $this->auditedCall(fn () => $s->open($this->tenant(), $d, $r->user()), $perm, 'compliance_case', null);

        return $this->viaTrustAlias($r) ? response()->json($x, 201) : response()->json(['data' => $s->show($x)], 201);
    }

    public function show(Request $r, string $case, ComplianceCaseService $s): JsonResponse
    {
        $x = $this->case($case);
        $this->authorizeRoutePermission($r, 'compliance_case', $x->id);

        return response()->json(['data' => $s->show($x)]);
    }

    public function transition(Request $r, string $case, ComplianceCaseService $s): JsonResponse
    {
        $x = $this->case($case);
        $perm = $this->authorizeRoutePermission($r, 'compliance_case', $x->id);
        $d = $r->validate(['to_status' => 'required|in:UNDER_REVIEW,REMEDIATION,CLOSED,REOPENED', 'reason_code' => 'required|string|max:64', 'findings' => 'array']);
        $x = $this->auditedCall(fn () => $s->transition($x, $d['to_status'], $d['reason_code'], $d['findings'] ?? [], $r->user()), $perm, 'compliance_case', $x->id);

        return $this->viaTrustAlias($r) ? response()->json($x) : response()->json(['data' => $s->show($x)]);
    }

    public function addFinding(Request $r, string $case, ComplianceCaseService $s): JsonResponse
    {
        $x = $this->case($case);
        $perm = $this->authorizeRoutePermission($r, 'compliance_case', $x->id);
        $d = $r->validate(['title' => 'required|string|max:255', 'description' => 'nullable|string|max:10000', 'severity' => 'required|in:LOW,MEDIUM,HIGH,CRITICAL', 'category' => 'nullable|string|max:64']);

        return response()->json(['data' => $this->auditedCall(fn () => $s->addFinding($x, $d, $r->user()), $perm, 'compliance_case', $x->id)], 201);
    }

    public function withdrawFinding(Request $r, string $finding, ComplianceCaseService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'compliance_finding', $finding);
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['data' => $this->auditedCall(fn () => $s->withdrawFinding($finding, $this->tenant(), $d['reason'], $r->user()), $perm, 'compliance_finding', $finding)]);
    }

    public function planAction(Request $r, string $finding, ComplianceCaseService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'compliance_finding', $finding);
        $d = $r->validate(['description' => 'required|string|max:4000', 'owner_user_id' => 'required|uuid', 'due_on' => 'required|date|after_or_equal:today']);

        return response()->json(['data' => $this->auditedCall(fn () => $s->planAction($finding, $this->tenant(), $d, $r->user()), $perm, 'compliance_finding', $finding)], 201);
    }

    public function actOnAction(Request $r, string $action, ComplianceCaseService $s): JsonResponse
    {
        $d = $r->validate(['event' => 'required|in:start,complete,cancel', 'notes' => 'nullable|string|max:4000']);
        $perm = $this->authorizeRoutePermission($r, 'compliance_corrective_action', $action);

        return response()->json(['data' => $this->auditedCall(fn () => $s->actOnAction($action, $this->tenant(), $d['event'], $d, $r->user()), $perm, 'compliance_corrective_action', $action)]);
    }

    public function verifyAction(Request $r, string $action, ComplianceCaseService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'compliance_corrective_action', $action);
        $d = $r->validate(['accepted' => 'required|boolean', 'notes' => 'required|string|max:4000']);

        return response()->json(['data' => $this->auditedCall(fn () => $s->actOnAction($action, $this->tenant(), 'verify', $d, $r->user()), $perm, 'compliance_corrective_action', $action)]);
    }

    public function linkEvidence(Request $r, string $case, ComplianceCaseService $s): JsonResponse
    {
        $x = $this->case($case);
        $perm = $this->authorizeRoutePermission($r, 'compliance_case', $x->id);
        $d = $r->validate(['description' => 'required|string|max:1000', 'document_id' => 'nullable|uuid', 'external_reference' => 'nullable|string|max:255',
            'finding_id' => 'nullable|uuid', 'corrective_action_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->auditedCall(fn () => $s->linkEvidence($x, $d, $r->user()), $perm, 'compliance_case', $x->id)], 201);
    }
}
