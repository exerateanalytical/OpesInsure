<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SHR-008 Audit Timeline, customer side: GET /mobile/account/activity — the caller's OWN actions in the current
 * tenant, read from the same audit_log the staff trail (compliance/audit-log, AuditTrail page) reads. Scoped to
 * actor_id = caller and tenant_id = current tenant, so nobody sees anyone else's trail; no IP / device / hash-chain
 * columns are returned. Sign-ins stay on GET /me/security/login-activity.
 */
final class MobileActivityController
{
    public function __invoke(Request $request): JsonResponse
    {
        $d = $request->validate(['limit' => 'nullable|integer|min:1|max:100', 'before' => 'nullable|integer|min:1']);
        $rows = DB::table('audit_log')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('actor_id', $request->user()->id)
            ->when($d['before'] ?? null, fn ($q, $v) => $q->where('sequence', '<', $v))
            ->orderByDesc('sequence')->limit($d['limit'] ?? 50)
            ->get(['sequence', 'action', 'subject_type', 'subject_id', 'source', 'created_at']);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'sequence' => (int) $r->sequence, 'action' => $r->action, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id,
            'source' => $r->source, 'occurred_at' => $r->created_at,
        ])->values()]);
    }
}
