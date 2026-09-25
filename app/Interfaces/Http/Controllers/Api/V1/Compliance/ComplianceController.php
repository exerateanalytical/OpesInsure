<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Compliance;

use App\Application\Privacy\DataSubjectRequestService;
use App\Application\Security\PrivilegedAccessService;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Controllers\Concerns\AuthorizesSensitiveActions;
use App\Models\DataSubjectRequest;
use App\Models\PrivilegedAccessGrant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * REQ-DUP-009 canonical compliance/* endpoints: one service each (PrivilegedAccessService,
 * DataSubjectRequestService). trust/privileged-access* and trust/data-subject-requests* are deprecated
 * aliases of these actions; the permission enforced is the one the matched route declares, and an alias
 * keeps its historical response shape (bare model) while compliance/* answers with a `data` envelope.
 * Both request contracts are accepted on both paths (legacy single-step grant / trust request→approve).
 */
final class ComplianceController
{
    use AuthorizesSensitiveActions;

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function owns(object $x): void
    {
        if (($x->tenant_id ?? null) !== $this->tenant()) {
            abort(404);
        }
    }

    public function grantAccess(Request $r, PrivilegedAccessService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'privileged_access_grant');
        if ($r->has('scope')) { // two-step request (maker); approval is a separate call (checker)
            $d = $r->validate(['user_id' => 'required|uuid', 'purpose' => 'required|string|max:64', 'justification' => 'required|string|min:20|max:2000',
                'starts_at' => 'required|date', 'expires_at' => 'required|date', 'scope' => 'required|array|min:1']);
            $g = $this->auditedCall(fn () => $s->request($this->tenant(), User::findOrFail($d['user_id']), $d, $r->user()), $perm, 'privileged_access_grant', null);
        } else { // legacy single-step grant: caller is the approver
            $d = $r->validate(['user_id' => 'required|uuid|exists:users,id', 'tenant_id' => 'required|uuid|exists:tenants,id', 'purpose' => 'required|string|max:64',
                'justification' => 'required|string|min:40|max:4000', 'starts_at' => 'required|date', 'expires_at' => 'required|date|after:starts_at']);
            abort_if($d['tenant_id'] !== $this->tenant(), 404);
            $g = $this->auditedCall(fn () => $s->grant($this->tenant(), User::findOrFail($d['user_id']), $d, $r->user()), $perm, 'privileged_access_grant', null);
        }

        return $this->out($r, $g, 201, ['id' => $g->id, 'status' => $g->status, 'expires_at' => $g->expires_at]);
    }

    public function approveAccess(Request $r, PrivilegedAccessGrant $x, PrivilegedAccessService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'privileged_access_grant', $x->id);
        $this->owns($x);

        return $this->out($r, $this->auditedCall(fn () => $s->approve($x, $r->user()), $perm, 'privileged_access_grant', $x->id));
    }

    public function revokeAccess(Request $r, PrivilegedAccessGrant $x, PrivilegedAccessService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'privileged_access_grant', $x->id);
        $this->owns($x);
        $d = $r->validate(['reason' => 'required|string|max:2000']);

        return $this->out($r, $this->auditedCall(fn () => $s->revoke($x, $d['reason'], $r->user()), $perm, 'privileged_access_grant', $x->id));
    }

    public function dataRequest(Request $r, DataSubjectRequestService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'data_subject_request');
        $d = $r->validate(['party_id' => 'required|uuid|exists:parties,id', 'type' => 'required|in:ACCESS,CORRECTION,DELETION,ERASURE,RESTRICTION,PORTABILITY,OBJECTION',
            'due_on' => 'nullable|date', 'assigned_to' => 'nullable|uuid', 'idempotency_key' => 'nullable|string|max:100']);
        $x = $this->auditedCall(fn () => $s->receive($this->tenant(), array_filter($d, fn ($v) => $v !== null), $r->user()), $perm, 'data_subject_request', null);

        return $this->out($r, $x, 201, ['id' => $x->id, 'request_number' => $x->request_number, 'status' => $x->status, 'due_on' => $x->due_on]);
    }

    public function verifyDataRequest(Request $r, DataSubjectRequest $x, DataSubjectRequestService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'data_subject_request', $x->id);
        $this->owns($x);
        $d = $r->validate(['identity_evidence' => 'required|string|min:20']);

        return $this->out($r, $this->auditedCall(fn () => $s->verify($x, $d['identity_evidence'], $r->user()), $perm, 'data_subject_request', $x->id));
    }

    public function resolveDataRequest(Request $r, DataSubjectRequest $x, DataSubjectRequestService $s): JsonResponse
    {
        $perm = $this->authorizeRoutePermission($r, 'data_subject_request', $x->id);
        $this->owns($x);
        $d = $r->validate(['decision' => 'required|string|max:64', 'notes' => 'required|string|max:4000']);

        return $this->out($r, $this->auditedCall(fn () => $s->resolve($x, $d['decision'], $d['notes'], $r->user()), $perm, 'data_subject_request', $x->id));
    }

    public function audit(Request $r)
    {
        $q = DB::table('audit_log')->orderByDesc('sequence');
        if ($r->filled('subject_type')) {
            $q->where('subject_type', $r->string('subject_type'));
        }
        if ($r->filled('actor_id')) {
            $q->where('actor_id', $r->string('actor_id'));
        }
        // REQ-AML-003 (Batch 15 E9): tipping-off control — STR traces are invisible without cases.str.view.
        if (! $r->user()?->hasPermission('cases.str.view')) {
            \App\Application\Compliance\Aml\Str\TippingOffGuard::hideFromAudit($q);
        }

        return response()->json(['data' => $q->paginate(min($r->integer('per_page', 50), 100))]);
    }

    private function out(Request $r, object $model, int $status = 200, ?array $summary = null): JsonResponse
    {
        if ($this->viaTrustAlias($r)) {
            return response()->json($model, $status);
        }

        return response()->json(['data' => $summary ?? $model], $status);
    }
}
