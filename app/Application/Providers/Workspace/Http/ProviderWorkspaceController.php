<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Http;

use App\Application\Health\Preauth\Http\PreauthorizationController;
use App\Application\Health\ProviderClaims\Http\ProviderClaimController;
use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Providers\Portal\ProviderScope;
use App\Application\Providers\Workspace\ProviderAccess;
use App\Application\Providers\Workspace\ProviderOperationsService;
use App\Application\Providers\Workspace\ProviderWorkspaceRegister;
use App\Application\Providers\Workspace\ProviderWorkspaceService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provider Portal Gap-Free spec v1 — provider-side API under /api/v1/provider-portal (the existing provider-portal
 * prefix; the spec's /api/provider/* contracts map 1:1, see ProviderWorkspaceRegister::SCREENS). Mutations go through
 * the canonical engines (preauth, provider claims) after the provider + facility scope is asserted, so no validation or
 * business rule is duplicated. Mutating routes require an Idempotency-Key.
 */
final class ProviderWorkspaceController
{
    public function __construct(
        private readonly ProviderWorkspaceService $ws,
        private readonly ProviderOperationsService $ops,
        private readonly ProviderAccess $access,
        private readonly TenantContext $tenant,
    ) {}

    private function t(): string
    {
        return $this->tenant->id();
    }

    private function filters(Request $r): array
    {
        return $r->validate(array_fill_keys(ProviderWorkspaceRegister::FILTERS, 'nullable|string|max:120') + ['status' => 'nullable|string|max:40', 'request_type' => 'nullable|string|max:16',
            'per_page' => 'nullable|integer|min:1|max:200', 'page' => 'nullable|integer|min:1']);
    }

    // ----------------------------------------------------------------- register, dashboard

    public function screens(): JsonResponse
    {
        return response()->json(['data' => ['portal' => ProviderWorkspaceRegister::PORTAL_CODE, 'screens' => ProviderWorkspaceRegister::screens(),
            'filters' => ProviderWorkspaceRegister::FILTERS, 'reports' => ProviderWorkspaceRegister::REPORTS, 'ui_states' => ProviderWorkspaceRegister::UI_STATES,
            'offline' => ['draft_allowed' => ProviderWorkspaceRegister::OFFLINE_DRAFT_ALLOWED, 'finalization_forbidden' => ProviderWorkspaceRegister::OFFLINE_FINALIZATION_FORBIDDEN]]]);
    }

    public function dashboard(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->ws->dashboard($this->t(), $r->user(), ProviderScope::of($r))]);
    }

    // ----------------------------------------------------------------- eligibility

    public function eligibilityCheck(Request $r): JsonResponse
    {
        $d = $r->validate(['member_ref' => 'required|string|max:120', 'search_method' => ['nullable', Rule::in(ProviderWorkspaceRegister::SEARCH_METHODS)],
            'policy_id' => 'nullable|uuid', 'service_code' => 'required|string|max:64', 'service_date' => 'nullable|date', 'facility_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->ws->checkEligibility($this->t(), $r->user(), ProviderScope::of($r), $d)]);
    }

    public function eligibilityShow(Request $r, string $check): JsonResponse
    {
        return response()->json(['data' => $this->ws->eligibilityResult($this->t(), ProviderScope::of($r), $check)]);
    }

    // ----------------------------------------------------------------- preauthorizations & admissions (canonical engine)

    public function preauthIndex(Request $r): JsonResponse
    {
        return response()->json($this->ws->preauthorizations($this->t(), $r->user(), ProviderScope::of($r), $this->filters($r)));
    }

    public function preauthShow(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->ws->preauthorization($this->t(), $r->user(), ProviderScope::of($r), $id)]);
    }

    public function preauthStore(Request $r): Response
    {
        $s = ProviderScope::of($r);
        $this->access->assertFacility($r->user(), $s, $r->input('facility_id'));
        $r->merge(['provider_id' => $s->providerId]);

        return app(PreauthorizationController::class)->store($r);
    }

    public function preauthRespond(Request $r, string $id): Response
    {
        $this->ws->assertPreauth($this->t(), $r->user(), ProviderScope::of($r), $id);

        return app(PreauthorizationController::class)->provideInfo($r, $id);
    }

    public function preauthCancel(Request $r, string $id): Response
    {
        $this->ws->assertPreauth($this->t(), $r->user(), ProviderScope::of($r), $id);

        return app(PreauthorizationController::class)->cancel($r, $id);
    }

    public function admissionIndex(Request $r): JsonResponse
    {
        return response()->json($this->ws->preauthorizations($this->t(), $r->user(), ProviderScope::of($r), ['request_type' => 'ADMISSION'] + $this->filters($r)));
    }

    /** New admission = admit the patient on an APPROVED / PARTIALLY_APPROVED admission preauthorization. */
    public function admissionStore(Request $r): Response
    {
        $d = $r->validate(['preauthorization_id' => 'required|uuid']);
        $this->ws->assertPreauth($this->t(), $r->user(), ProviderScope::of($r), $d['preauthorization_id'], 'ADMISSION');

        return app(PreauthorizationController::class)->admit($r, $d['preauthorization_id']);
    }

    public function admissionShow(Request $r, string $id): JsonResponse
    {
        $this->ws->assertPreauth($this->t(), $r->user(), ProviderScope::of($r), $id, 'ADMISSION');

        return $this->preauthShow($r, $id);
    }

    public function admissionExtend(Request $r, string $id): Response
    {
        $this->ws->assertPreauth($this->t(), $r->user(), ProviderScope::of($r), $id, 'ADMISSION');

        return app(PreauthorizationController::class)->requestExtension($r, $id);
    }

    public function admissionDischarge(Request $r, string $id): Response
    {
        $this->ws->assertPreauth($this->t(), $r->user(), ProviderScope::of($r), $id, 'ADMISSION');

        return app(PreauthorizationController::class)->discharge($r, $id);
    }

    // ----------------------------------------------------------------- treatment episodes

    public function episodeIndex(Request $r): JsonResponse
    {
        return response()->json($this->ops->episodes($this->t(), $r->user(), ProviderScope::of($r), $this->filters($r)));
    }

    public function episodeStore(Request $r): JsonResponse
    {
        $d = $r->validate(['facility_id' => 'nullable|uuid', 'department_id' => 'nullable|uuid', 'policy_id' => 'nullable|uuid', 'member_ref' => 'required_without:preauthorization_id|nullable|string|max:120',
            'preauthorization_id' => 'nullable|uuid', 'eligibility_check_id' => 'nullable|uuid', 'episode_type' => ['required', Rule::in(ProviderOperationsService::EPISODE_TYPES)],
            'started_on' => 'nullable|date', 'diagnosis_summary' => 'nullable|string|max:5000', 'attending_practitioner' => 'nullable|string|max:200']);

        return response()->json(['data' => $this->ops->openEpisode($this->t(), $r->user(), ProviderScope::of($r), $d)], 201);
    }

    public function episodeShow(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->ops->episode($this->t(), $r->user(), ProviderScope::of($r), $id)]);
    }

    public function episodeLine(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['service_code' => 'required_without:provider_code|nullable|string|max:64', 'provider_code' => 'nullable|string|max:64', 'quantity' => 'nullable|integer|min:1|max:99999',
            'unit_price_minor' => 'required|integer|min:0', 'service_date' => 'nullable|date', 'performed_by' => 'nullable|string|max:200']);

        return response()->json(['data' => $this->ops->addEpisodeLine($this->t(), $r->user(), ProviderScope::of($r), $id, $d)], 201);
    }

    public function episodeClose(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['ended_on' => 'nullable|date']);

        return response()->json(['data' => $this->ops->closeEpisode($this->t(), $r->user(), ProviderScope::of($r), $id, $d['ended_on'] ?? null)]);
    }

    public function episodeBill(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['invoice_reference' => 'required|string|max:120', 'contract_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->ops->billEpisode($this->t(), $r->user(), ProviderScope::of($r), $id, $d['invoice_reference'], $d['contract_id'] ?? null)], 201);
    }

    // ----------------------------------------------------------------- claims / invoices

    public function claimIndex(Request $r): JsonResponse
    {
        return response()->json($this->ws->claims($r->user(), ProviderScope::of($r), $this->filters($r)));
    }

    public function claimShow(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->ws->claim($r->user(), ProviderScope::of($r), $id)]);
    }

    public function claimStore(Request $r): JsonResponse
    {
        $s = ProviderScope::of($r);
        $facility = $r->validate(['facility_id' => 'nullable|uuid', 'source_system' => 'nullable|string|max:64']);
        $this->access->assertFacility($r->user(), $s, $facility['facility_id'] ?? null);
        $r->merge(['provider_id' => $s->providerId]);
        $res = app(ProviderClaimController::class)->store($r);
        $id = $res->getData(true)['data']['id'] ?? null;
        if ($id) {
            DB::table('health_provider_claims')->where('id', $id)->update(['provider_facility_id' => $facility['facility_id'] ?? null, 'source_system' => $facility['source_system'] ?? 'PROVIDER_PORTAL']);
            app(\App\Application\Events\OutboxWriter::class)->record('provider_portal.claim.created', 'health_provider_claim', $id, ['provider_claim_id' => $id, 'provider_id' => $s->providerId]);

            return response()->json(['data' => $this->ws->claim($r->user(), $s, $id)], 201);
        }

        return $res;
    }

    public function claimSubmit(Request $r, string $id): JsonResponse
    {
        $s = ProviderScope::of($r);
        $this->ws->claim($r->user(), $s, $id);
        app(ProviderClaimService::class)->submit($this->t(), $id, $r->user()->id);

        return response()->json(['data' => $this->ws->claim($r->user(), $s, $id)]);
    }

    public function claimRespond(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['response' => 'required|string|min:2|max:5000']);

        return response()->json(['data' => $this->ops->respondToClaimQuery($this->t(), $r->user(), ProviderScope::of($r), $id, $d['response'])]);
    }

    // ----------------------------------------------------------------- finance

    public function accounts(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->ws->accounts($r->user(), ProviderScope::of($r), $this->filters($r))]);
    }

    public function account(Request $r, string $insurer): JsonResponse
    {
        return response()->json(['data' => $this->ws->account($r->user(), ProviderScope::of($r), $insurer, $this->filters($r))]);
    }

    public function settlements(Request $r): JsonResponse
    {
        return response()->json($this->ws->settlements($r->user(), ProviderScope::of($r), $this->filters($r)));
    }

    public function settlement(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->ws->settlement($r->user(), ProviderScope::of($r), $id)]);
    }

    public function reconciliations(Request $r): JsonResponse
    {
        return response()->json($this->ops->reconciliations($this->t(), $r->user(), ProviderScope::of($r), $this->filters($r)));
    }

    public function reconciliationStore(Request $r): JsonResponse
    {
        $d = $r->validate(['payment_reference' => 'required|string|max:120', 'received_on' => 'required|date', 'currency' => 'required|string|size:3',
            'amount_minor' => 'required|integer|min:1', 'settlement_batch_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->ops->recordPayment($this->t(), $r->user(), ProviderScope::of($r), $d)], 201);
    }

    public function reconciliationShow(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->ops->reconciliation($this->t(), $r->user(), ProviderScope::of($r), $id)]);
    }

    public function reconciliationMatch(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['allocations' => 'required|array|min:1|max:500', 'allocations.*.claim_id' => 'required|uuid', 'allocations.*.amount_minor' => 'required|integer|min:1',
            'reason' => 'required|string|min:3|max:2000']);

        return response()->json(['data' => $this->ops->match($this->t(), $r->user(), ProviderScope::of($r), $id, $d['allocations'], $d['reason'])]);
    }

    public function disputes(Request $r): JsonResponse
    {
        return response()->json($this->ops->disputes($this->t(), $r->user(), ProviderScope::of($r), $this->filters($r)));
    }

    public function disputeStore(Request $r): JsonResponse
    {
        $d = $r->validate(['subject_type' => 'required|in:CLAIM,CLAIM_LINE,SETTLEMENT,RECONCILIATION', 'claim_id' => 'nullable|uuid', 'claim_line_no' => 'nullable|integer|min:1',
            'settlement_batch_id' => 'nullable|uuid', 'reconciliation_id' => 'nullable|uuid', 'reason_code' => ['required', Rule::in(ProviderWorkspaceRegister::DISPUTE_REASONS)],
            'disputed_amount_minor' => 'nullable|integer|min:0', 'description' => 'required|string|min:5|max:5000']);

        return response()->json(['data' => $this->ops->openDispute($this->t(), $r->user(), ProviderScope::of($r), $d)], 201);
    }

    public function disputeShow(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->ops->dispute($this->t(), $r->user(), ProviderScope::of($r), $id)]);
    }

    // ----------------------------------------------------------------- contracts / tariffs detail

    public function contractShow(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->ws->contract($this->t(), ProviderScope::of($r), $id)]);
    }

    public function tariffShow(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->ws->tariff($this->t(), ProviderScope::of($r), $id)]);
    }

    // ----------------------------------------------------------------- reports, audit, documents, notifications

    public function report(Request $r, string $report): Response
    {
        $f = $this->filters($r);
        $rows = $this->ws->report($r->user(), ProviderScope::of($r), strtoupper($report), $f);
        if ($r->query('format') === 'csv') {
            abort_unless($r->user()->hasPermission('provider.reports.export'), 403);
            app(\App\Application\Audit\AuditWriter::class)->record('provider_portal.report.exported', 'provider', ProviderScope::of($r)->providerId,
                ['provider_id' => ProviderScope::of($r)->providerId, 'report' => strtoupper($report), 'filters' => array_filter($f), 'rows' => count($rows)]);

            return response($this->ws->csv($rows), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="'.strtolower($report).'.csv"']);
        }

        return response()->json(['data' => $rows, 'meta' => ['report' => strtoupper($report), 'filters' => array_filter($f), 'count' => count($rows)]]);
    }

    public function audit(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->ws->auditLog($r->user(), ProviderScope::of($r), $this->filters($r))]);
    }

    /** Documents issued on the provider's preauthorizations (GOP pack): medical documents only for clinical roles. */
    public function documents(Request $r): JsonResponse
    {
        $s = ProviderScope::of($r);
        if (! $this->access->mayReadClinical($r->user(), $s)) {
            return response()->json(['data' => [], 'meta' => ['ui_state' => 'PERMISSION_DENIED', 'reason' => 'Medical documents are restricted to clinical roles.']]);
        }
        $manifests = $this->ws->preauthQuery($this->t(), $r->user(), $s)->whereNotNull('gop_manifest_id')->pluck('gop_manifest_id');

        return response()->json(['data' => DB::table('documents')->whereIn('pack_manifest_id', $manifests)
            ->orderByDesc('created_at')->limit(500)->get(['id', 'document_type_code', 'document_number', 'status', 'valid_from', 'valid_until'])->all()]);
    }

    public function notifications(Request $r): JsonResponse
    {
        return response()->json(['data' => DB::table('outbox_messages')->whereRaw("payload->>'provider_id' = ?", [ProviderScope::of($r)->providerId])
            ->orderByDesc('occurred_at')->limit(200)->get(['id', 'event_name', 'aggregate_type', 'aggregate_id', 'occurred_at'])->all()]);
    }

    // ----------------------------------------------------------------- organisation & users

    public function roles(): JsonResponse
    {
        return response()->json(['data' => collect(ProviderAccess::ROLES)->map(fn ($clinical, $code) => ['code' => $code, 'clinical_access' => $clinical,
            'spec_aliases' => array_keys(ProviderAccess::ALIASES, $code, true)])->values()]);
    }

    public function users(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->access->users(ProviderScope::of($r))]);
    }

    public function userAssign(Request $r): JsonResponse
    {
        $d = $r->validate(['user_id' => 'required|uuid', 'provider_role' => 'required|string|max:40', 'facility_scope' => 'required|in:ALL,ASSIGNED',
            'facility_ids' => 'nullable|array|max:500', 'facility_ids.*' => 'uuid']);

        return response()->json(['data' => $this->access->assign(ProviderScope::of($r), $d['user_id'], $d['provider_role'], $d['facility_scope'], $d['facility_ids'] ?? [], $r->user())], 201);
    }

    public function userRevoke(Request $r, string $id): JsonResponse
    {
        return response()->json(['data' => $this->access->revoke(ProviderScope::of($r), $id, $r->user())]);
    }

    public function departments(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->access->departments($r->user(), ProviderScope::of($r))]);
    }

    public function departmentStore(Request $r, string $facility): JsonResponse
    {
        $d = $r->validate(['code' => 'required|string|max:64', 'name' => 'required|string|max:200', 'level' => 'nullable|in:DEPARTMENT,SERVICE_UNIT',
            'parent_department_id' => 'nullable|uuid', 'specialty_code' => 'nullable|string|max:64']);

        return response()->json(['data' => $this->access->addDepartment($r->user(), ProviderScope::of($r), $facility, $d)], 201);
    }

    public function integration(Request $r): JsonResponse
    {
        return response()->json(['data' => ['provider_api_base' => url('/api/v1/provider-portal'), 'idempotency' => 'Idempotency-Key header required on every POST',
            'source_system_tracking' => 'source_system on provider claims', 'capabilities' => ['ELIGIBILITY_CHECK', 'BENEFIT_INQUIRY', 'PREAUTHORIZATION_REQUEST', 'PREAUTHORIZATION_STATUS',
                'ADMISSION_NOTIFICATION', 'EXTENSION_REQUEST', 'CLAIM_SUBMISSION', 'CLAIM_STATUS', 'INVOICE_SUBMISSION', 'PAYMENT_ADVICE', 'SETTLEMENT_STATEMENT', 'RECONCILIATION'],
            'opes_emr_flow' => ['PATIENT_ENCOUNTER' => 'treatment-episodes', 'INSURANCE_ELIGIBILITY' => 'eligibility/check', 'PREAUTH_IF_REQUIRED' => 'preauthorizations',
                'TREATMENT' => 'treatment-episodes/{id}/lines', 'DISCHARGE' => 'treatment-episodes/{id}/close', 'CLAIM_GENERATION' => 'treatment-episodes/{id}/bill',
                'SUBMISSION' => 'claims/{id}/submit', 'SETTLEMENT' => 'settlements']]]);
    }

    // ----------------------------------------------------------------- back office (insurer side)

    public function resolveDispute(Request $r, string $id): JsonResponse
    {
        $d = $r->validate(['status' => 'required|string|max:32', 'response' => 'required|string|min:3|max:5000', 'resolution_amount_minor' => 'nullable|integer|min:0']);

        return response()->json(['data' => $this->ops->resolveDispute($this->t(), $id, $d['status'], $d['resolution_amount_minor'] ?? null, $d['response'], $r->user())]);
    }
}
