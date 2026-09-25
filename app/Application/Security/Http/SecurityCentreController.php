<?php

declare(strict_types=1);

namespace App\Application\Security\Http;

use App\Application\Audit\AuditWriter;
use App\Application\Security\Crash\CrashReportIngestor;
use App\Application\Security\Findings\SecurityFindingService;
use App\Domain\Tenancy\TenantContext;
use App\Models\SecurityFinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Agent B7 — security centre API (REQ-SEC-001 / REQ-SEC-003 / REQ-MOB-007). */
final class SecurityCentreController
{
    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private static function activity(object $a): array
    {
        return ['id' => $a->id, 'user_id' => $a->user_id, 'method' => $a->method, 'device_id' => $a->device_id, 'device_name' => $a->device_name, 'platform' => $a->platform,
            'country_code' => $a->country_code, 'new_device' => (bool) $a->new_device, 'anomaly_flags' => json_decode($a->anomaly_flags, true), 'occurred_at' => $a->occurred_at];
    }

    /** The signed-in user's own sign-in history. */
    public function myLoginActivity(Request $r): JsonResponse
    {
        $rows = DB::table('login_activities')->where('user_id', $r->user()->id)->orderByDesc('occurred_at')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($a) => self::activity($a))->values()]);
    }

    /** Sign-ins of the current tenant's members; ?flagged=1 → anomalies only. */
    public function loginActivity(Request $r): JsonResponse
    {
        $members = DB::table('tenant_memberships')->where('tenant_id', $this->tenant())->pluck('user_id');
        $rows = DB::table('login_activities')->whereIn('user_id', $members)
            ->when($r->boolean('flagged'), fn ($q) => $q->whereRaw('jsonb_array_length(anomaly_flags) > 0'))
            ->orderByDesc('occurred_at')->limit(min(200, max(1, (int) $r->query('limit', 100))))->get();

        return response()->json(['data' => $rows->map(fn ($a) => self::activity($a))->values()]);
    }

    public function privilegedAccess(Request $r): JsonResponse
    {
        $rows = DB::table('privileged_access_grants')->where('tenant_id', $this->tenant())
            ->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->orderByDesc('created_at')->limit(200)
            ->get(['id', 'user_id', 'purpose', 'status', 'requested_by', 'approved_by', 'starts_at', 'expires_at', 'revoked_at', 'scope']);

        return response()->json(['data' => $rows->map(fn ($g) => [...(array) $g, 'approved_by' => $g->status === 'REQUESTED' ? null : $g->approved_by, 'scope' => json_decode($g->scope, true)])->values()]);
    }

    public function findings(Request $r): JsonResponse
    {
        $rows = DB::table('security_findings')->where('tenant_id', $this->tenant())
            ->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->when($r->query('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $rows]);
    }

    public function finding(string $finding): JsonResponse
    {
        $row = DB::table('security_findings')->where('tenant_id', $this->tenant())->where('id', $finding)->first() ?? abort(404);

        return response()->json(['data' => [...(array) $row, 'events' => DB::table('security_finding_events')->where('security_finding_id', $row->id)->orderBy('occurred_at')->get()]]);
    }

    public function reportFinding(Request $r, SecurityFindingService $s): JsonResponse
    {
        $d = $r->validate([
            'source' => ['required', Rule::in(SecurityFindingService::SOURCES)], 'severity' => ['required', Rule::in(SecurityFindingService::SEVERITIES)],
            'title' => 'required|string|max:255', 'description' => 'required|string|max:10000', 'category' => 'nullable|string|max:48', 'affected_asset' => 'nullable|string|max:191',
            'cve' => 'nullable|string|max:32', 'owner_id' => 'nullable|uuid|exists:users,id', 'due_at' => 'nullable|date', 'notes' => 'nullable|string|max:2000',
        ]);

        return response()->json(['data' => $s->report($this->tenant(), $d, $r->user())], 201);
    }

    public function transitionFinding(Request $r, string $finding, SecurityFindingService $s): JsonResponse
    {
        abort_unless(DB::table('security_findings')->where('tenant_id', $this->tenant())->where('id', $finding)->exists(), 404);
        $d = $r->validate([
            'to' => 'required|in:TRIAGED,IN_REMEDIATION,RESOLVED,RISK_ACCEPTED,FALSE_POSITIVE,OPEN', 'notes' => 'nullable|string|max:4000',
            'remediation_plan' => 'nullable|string|max:4000', 'risk_acceptance_expires_at' => 'nullable|date',
        ]);
        if ($d['to'] === 'RISK_ACCEPTED' && ! $r->user()->hasPermission('security.findings.accept_risk')) {
            abort(403, 'Accepting a security risk needs security.findings.accept_risk.');
        }

        return response()->json(['data' => $s->transition(SecurityFinding::query()->findOrFail($finding), $d['to'], $r->user(), $d)]);
    }

    public function purposes(): JsonResponse
    {
        return response()->json(['data' => DB::table('processing_purposes')->orderBy('code')->get()]);
    }

    public function updatePurpose(Request $r, string $code, AuditWriter $audit): JsonResponse
    {
        $row = DB::table('processing_purposes')->where('code', $code)->first() ?? abort(404);
        $d = $r->validate([
            'name' => 'sometimes|string|max:160', 'description' => 'sometimes|nullable|string|max:2000',
            'lawful_basis' => 'sometimes|in:CONSENT,CONTRACT,LEGAL_OBLIGATION,LEGITIMATE_INTEREST,VITAL_INTEREST,PUBLIC_TASK',
            'consent_purpose' => 'sometimes|nullable|string|max:64', 'basis_status' => 'sometimes|in:PLATFORM_PROVISIONAL,OWNER_CONFIRMED', 'is_active' => 'sometimes|boolean',
        ]);
        if (($d['lawful_basis'] ?? $row->lawful_basis) === 'CONSENT' && ! ($d['consent_purpose'] ?? $row->consent_purpose ?? $code)) {
            abort(422);
        }
        DB::table('processing_purposes')->where('id', $row->id)->update([...$d, 'updated_at' => now()]);
        $audit->record('privacy.purpose.updated', 'processing_purpose', $row->id, ['code' => $code, 'changes' => $d]);

        return response()->json(['data' => DB::table('processing_purposes')->find($row->id)]);
    }

    public function purposeChecks(Request $r): JsonResponse
    {
        $rows = DB::table('purpose_of_use_checks')->where('tenant_id', $this->tenant())
            ->when($r->query('decision'), fn ($q, $s) => $q->where('decision', $s))->when($r->query('party_id'), fn ($q, $s) => $q->where('party_id', $s))
            ->orderByDesc('occurred_at')->limit(200)->get();

        return response()->json(['data' => $rows]);
    }

    /** Public (pre-auth) crash sink — see CrashReportIngestor for the no-PII contract. */
    public function crashReport(Request $r, CrashReportIngestor $ingestor): JsonResponse
    {
        $d = $r->validate([
            'platform' => 'required|in:ANDROID,IOS,WEB', 'app_version' => 'required|string|max:32', 'build' => 'nullable|string|max:32',
            'os_version' => 'nullable|string|max:32', 'error_type' => 'required|string|max:120', 'message' => 'nullable|string|max:4000', 'stack' => 'nullable|string|max:65000',
        ]);
        $unknown = array_diff(array_keys($r->all()), ['platform', 'app_version', 'build', 'os_version', 'error_type', 'message', 'stack']);
        if ($unknown !== []) {
            return response()->json(['message' => 'Crash reports accept only the documented fields.', 'errors' => ['payload' => array_values($unknown)]], 422);
        }

        return response()->json(['data' => ['accepted' => true] + $ingestor->ingest($d)], 201);
    }

    public function crashReports(Request $r): JsonResponse
    {
        $rows = DB::table('mobile_crash_reports')->when($r->query('app_version'), fn ($q, $v) => $q->where('app_version', $v))
            ->orderByDesc('last_seen_at')->limit(200)->get();

        return response()->json(['data' => $rows]);
    }
}
