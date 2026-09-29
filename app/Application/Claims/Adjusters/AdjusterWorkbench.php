<?php

declare(strict_types=1);

namespace App\Application\Claims\Adjusters;

use App\Application\Claims\Assessment\Models\ClaimAssessment;
use App\Application\Claims\Evidence\ClaimEvidenceChecklist;
use App\Application\WebExperiences\PortalScope;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Claim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Q9 claims professional / adjuster workbench (CLP-001, 004, 006-018) — read side. Every read starts from
 * ExpertAssignmentService (the caller's own expert assignments: provider link, tenant) and, inside a portal, is
 * narrowed to the claims of the caller's carrier (PortalScope::visibleOf). Writes stay in ExpertAssignmentService,
 * ClaimEvidenceService and ClaimAssessmentService (see ClaimsWorkbenchActions).
 */
final class AdjusterWorkbench
{
    /** Open (work in progress) stages, in lifecycle order. */
    public const OPEN = ['ASSIGNMENT_PENDING', 'ACCEPTED', 'INSPECTION_SCHEDULED', 'INSPECTED', 'REPORT_RETURNED', 'REPORT_SUBMITTED'];

    /** Completed assignments (CLP-017): the terminal lifecycle states. */
    public const COMPLETED = ExpertAssignmentLifecycle::TERMINAL;

    /** Claim statuses a handler no longer works. */
    private const CLAIM_DONE = ['CLOSED', 'SETTLED', 'REJECTED', 'WITHDRAWN', 'CANCELLED'];

    public function __construct(private readonly ExpertAssignmentService $assignments, private readonly ClaimEvidenceChecklist $checklist) {}

    /**
     * The caller's expert assignments (optionally only some statuses), narrowed to the portal carrier.
     *
     * @param  list<string>|null  $statuses
     * @return list<object>
     */
    public function assignments(string $tenantId, User $user, ?array $statuses = null): array
    {
        $rows = array_values(array_filter($this->assignments->forAdjuster($tenantId, $user, null),
            fn ($r) => $statuses === null || in_array($r->status, $statuses, true)));
        $visible = PortalScope::visibleOf('claims', array_values(array_unique(array_map(fn ($r) => (string) $r->claim_id, $rows))));

        return array_values(array_filter($rows, fn ($r) => in_array((string) $r->claim_id, $visible, true)));
    }

    /** CLP-001: counts per stage, upcoming inspections and the items waiting on the adjuster. */
    public function dashboard(string $tenantId, User $user): array
    {
        $open = $this->assignments($tenantId, $user, self::OPEN);
        $counts = array_fill_keys(self::OPEN, 0);
        foreach ($open as $r) {
            $counts[$r->status]++;
        }
        $horizon = CarbonImmutable::now()->addDays(7);
        $upcoming = array_values(array_filter($open, fn ($r) => $r->status === 'INSPECTION_SCHEDULED' && $r->inspection_scheduled_for !== null
            && CarbonImmutable::parse($r->inspection_scheduled_for)->lessThanOrEqualTo($horizon)));
        usort($upcoming, fn ($a, $b) => strcmp((string) $a->inspection_scheduled_for, (string) $b->inspection_scheduled_for));
        $attention = array_values(array_filter($open, fn ($r) => in_array($r->status, ['ASSIGNMENT_PENDING', 'REPORT_RETURNED', 'INSPECTED', 'ACCEPTED'], true)));

        return ['counts' => $counts, 'open' => count($open), 'upcoming' => $upcoming, 'attention' => array_slice($attention, 0, 20)];
    }

    /** Claims assigned to the caller as handler (claims.view), narrowed to the portal carrier. @return array{counts: array<string,int>, rows: list<object>} */
    public function handledClaims(string $tenantId, User $user): array
    {
        $q = PortalScope::narrowTable(Claim::query()->where('tenant_id', $tenantId)->where('assigned_to', $user->id)->whereNotIn('status', self::CLAIM_DONE), 'claims');
        $counts = (clone $q)->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $rows = $q->orderBy('loss_occurred_at')->limit(20)->get(['id', 'claim_number', 'status', 'priority', 'loss_occurred_at', 'current_reserve_minor', 'currency'])->all();

        return ['counts' => $counts, 'rows' => $rows];
    }

    /** CLP-018: the caller's own adjuster performance over all their assignments (no peer data). */
    public function performance(string $tenantId, User $user): array
    {
        $ids = array_map(fn ($r) => (string) $r->id, $this->assignments($tenantId, $user));
        $rows = $ids === [] ? collect() : DB::table('claim_assignments')->whereIn('id', $ids)
            ->get(['id', 'status', 'assigned_at', 'accepted_at', 'inspected_at', 'report_submitted_at', 'reviewed_at', 'return_count', 'case_id', 'assessed_loss_minor']);
        $days = function (string $from, string $to) use ($rows): ?float {
            $d = $rows->filter(fn ($r) => $r->{$from} && $r->{$to})->map(fn ($r) => CarbonImmutable::parse($r->{$from})->diffInMinutes(CarbonImmutable::parse($r->{$to})) / 1440);

            return $d->isEmpty() ? null : round((float) $d->avg(), 1);
        };
        $accepted = $rows->where('status', 'REPORT_ACCEPTED');
        $reports = $rows->filter(fn ($r) => $r->report_submitted_at !== null);
        $caseIds = $rows->pluck('case_id')->filter()->values()->all();

        return [
            'total' => $rows->count(),
            'open' => $rows->whereIn('status', self::OPEN)->count(),
            'completed' => $accepted->count(),
            'declined' => $rows->where('status', 'DECLINED')->count(),
            'cancelled' => $rows->where('status', 'CANCELLED')->count(),
            'reports_submitted' => $reports->count(),
            'reports_returned' => (int) $rows->sum('return_count'),
            'first_time_right_pct' => $accepted->count() === 0 ? null : (int) round(100 * $accepted->where('return_count', 0)->count() / $accepted->count()),
            'avg_days_to_accept' => $days('assigned_at', 'accepted_at'),
            'avg_days_to_inspect' => $days('assigned_at', 'inspected_at'),
            'avg_days_to_report' => $days('assigned_at', 'report_submitted_at'),
            'avg_days_to_review' => $days('report_submitted_at', 'reviewed_at'),
            'sla_breaches' => $caseIds === [] ? 0 : DB::table('sla_clocks')->whereIn('case_id', $caseIds)->whereNotNull('breached_at')->count(),
            'assessments_recorded' => ClaimAssessment::where('tenant_id', $tenantId)->where('assessor_user_id', $user->id)->count(),
            'assessments_accepted' => ClaimAssessment::where('tenant_id', $tenantId)->where('assessor_user_id', $user->id)->where('status', 'ACCEPTED')->count(),
        ];
    }

    /**
     * CLP-004: one of the caller's own assignments with its claim (404 otherwise — existence is not leaked; other
     * carriers' claims are refused the same way inside a portal).
     */
    public function assignment(string $tenantId, User $user, string $id): object
    {
        $this->assignments->assertAdjusterOwns($tenantId, $user, $id);
        $a = $this->assignments->find($tenantId, $id);
        $claim = Claim::where('tenant_id', $tenantId)->find($a->claim_id);
        if ($claim === null || PortalScope::visibleOf('claims', [(string) $claim->id]) === []) {
            throw new ApiProblemException('ASSIGNMENT_NOT_FOUND', 404, 'Expert assignment not found.');
        }
        $a->claim = $claim;
        $a->history = $this->assignments->history($id);

        return $a;
    }

    /** CLP-006 incident details (loss_details, as captured at FNOL / by the claimant). */
    public function incident(Claim $claim): array
    {
        $d = is_array($claim->loss_details) ? $claim->loss_details : [];
        $i = is_array($d['incident'] ?? null) ? $d['incident'] : [];

        return [
            'loss_occurred_at' => $claim->loss_occurred_at, 'loss_location' => $claim->loss_location, 'submitted_at' => $claim->submitted_at,
            'description' => $d['description'] ?? null, 'incident_type' => $i['incident_type'] ?? ($d['incident_type'] ?? null),
            'police_report_number' => $i['police_report_number'] ?? null,
            'injuries_reported' => $i['injuries_reported'] ?? ($d['injuries_reported'] ?? null),
            'vehicle_drivable' => $i['vehicle_drivable'] ?? null, 'towing_required' => $i['towing_required'] ?? null,
            'coordinates' => isset($i['latitude'], $i['longitude']) ? $i['latitude'].', '.$i['longitude'] : null,
            'parties' => DB::table('claim_involved_parties')->where('claim_id', $claim->id)->whereNull('removed_at')->orderBy('created_at')
                ->get(['role', 'display_name', 'contact_phone'])->map(fn ($p) => (array) $p)->all(),
        ];
    }

    /** CLP-007 policy and coverage view: the policy terms in force and the coverage checks run on the claim. */
    public function policyCoverage(Claim $claim): array
    {
        $p = DB::table('policies')->where('id', $claim->policy_id)->first(['policy_number', 'status', 'coverage_starts_at', 'coverage_ends_at', 'currency', 'terms_snapshot', 'carrier_id']);
        $terms = $p ? (json_decode((string) $p->terms_snapshot, true) ?: []) : [];
        $covers = [];
        foreach ((array) ($terms['coverages'] ?? $terms['covers'] ?? $terms['guarantees'] ?? []) as $k => $c) {
            $c = is_array($c) ? $c : ['code' => is_string($k) ? $k : (string) $c];
            $covers[] = ['code' => $c['code'] ?? (is_string($k) ? $k : null), 'name' => $c['name'] ?? $c['label'] ?? null,
                'limit_minor' => $c['limit_minor'] ?? $c['sum_insured_minor'] ?? null, 'deductible_minor' => $c['deductible_minor'] ?? null];
        }
        $inForce = $p && $claim->loss_occurred_at && $p->coverage_starts_at && $p->coverage_ends_at
            && $claim->loss_occurred_at->betweenIncluded(CarbonImmutable::parse($p->coverage_starts_at), CarbonImmutable::parse($p->coverage_ends_at));

        return [
            'policy_number' => $p?->policy_number, 'status' => $p?->status, 'coverage_starts_at' => $p?->coverage_starts_at, 'coverage_ends_at' => $p?->coverage_ends_at,
            'currency' => $p?->currency ?? 'XAF', 'in_force_at_loss' => $p ? $inForce : null, 'covers' => $covers,
            'checks' => DB::table('claim_coverage_checks')->where('claim_id', $claim->id)->orderByDesc('checked_at')
                ->get(['outcome', 'coverage_code', 'checked_at', 'resolution', 'resolution_note'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /** CLP-008 evidence repository: linked evidence and the evidence checklist of the claim. */
    public function evidence(Claim $claim): array
    {
        $links = DB::table('claim_documents as l')->join('documents as d', 'd.id', '=', 'l.document_id')->where('l.claim_id', $claim->id)
            ->orderByDesc('l.submitted_at')->get(['l.evidence_type', 'l.status', 'l.submitted_at', 'l.verified_at', 'l.rejection_reason', 'd.mime_type', 'd.size_bytes', 'd.scan_status'])
            ->map(fn ($r) => (array) $r)->all();
        $checklist = rescue(fn () => $this->checklist->build($claim), ['items' => [], 'complete' => null], false);

        return ['links' => $links, 'checklist' => array_map(fn ($i) => ['document_type_id' => $i['document_type_id'] ?? null, 'name' => $i['name'] ?? $i['label'] ?? null,
            'mandatory' => (bool) ($i['mandatory'] ?? false), 'status' => $i['status'] ?? null], $checklist['items'] ?? []), 'complete' => $checklist['complete'] ?? null,
            // S4: uploads still in the security check / quarantined (never downloadable).
            'pending' => app(\App\Application\Documents\Scanning\PendingDocuments::class)->forClaim($claim->id)];
    }

    /** CLP-012 / 013 / 016: the assessments (recommendations) recorded on the claim. @return list<array<string,mixed>> */
    public function assessments(Claim $claim): array
    {
        return ClaimAssessment::where('claim_id', $claim->id)->orderByDesc('created_at')->get()
            ->map(fn (ClaimAssessment $a) => ['assessment_number' => $a->assessment_number, 'status' => $a->status, 'recommended_total_minor' => $a->recommended_total_minor,
                'currency' => $a->currency, 'rationale' => $a->rationale, 'created_at' => $a->created_at,
                'heads' => collect($a->heads ?? [])->map(fn ($h) => $h['head_code'].': '.\App\Application\WebExperiences\Money::format((int) $h['recommended_minor'], $a->currency))->implode(' · ')])->all();
    }
}
