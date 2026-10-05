<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Claims\Adjusters\ExpertAssignmentService;
use App\Application\Claims\ClaimLifecycleService;
use App\Application\Mobile\ListCursor;
use App\Application\Mobile\Workspace\WorkspaceDataScope;
use App\Application\Notifications\NotificationCatalog;
use App\Domain\Claims\ClaimMachine;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimDecision;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Phase-1 fix S (owner-approved 2026-09-30): the claims module of the mobile staff workspace, for claims officers,
 * claims managers, adjusters and every other role holding claims.view (customer service, branch managers,
 * reinsurance). Replaces the read-only 50-row table:
 *
 *  - GET  mobile/workspace/claims                 paged list (?status=, ?q=, ?cursor=, meta.next_cursor)
 *  - GET  mobile/workspace/claims/{id}            detail + the actions the caller may take now (`actions`)
 *  - POST mobile/workspace/claims/{id}/assign-to-me              claims.assign
 *  - POST mobile/workspace/claims/{id}/transitions               claims.transition (+ claims.close to close)
 *  - POST mobile/workspace/claims/{id}/decisions                 claims.decision.propose (maker)
 *  - POST mobile/workspace/claims/{id}/decisions/{d}/approve     claims.decision.approve (checker, not the maker)
 *
 * Every read and action is narrowed to the caller's data scope (WorkspaceDataScope — carrier / branch / assigned ...):
 * a claim outside it is a 404. Writes go through the existing ClaimLifecycleService (state machine, guards, audit,
 * outbox) — no parallel workflow. Adjusters act on their expert assignments through the existing
 * claims/adjuster/assignments/* endpoints; the detail lists them with their available events.
 */
final class MobileWorkspaceClaimsController
{
    /** Status moves a claims handler makes by hand; decisions, payments and withdrawal have their own flows. */
    private const MANUAL_TARGETS = ['ACKNOWLEDGED', 'EVIDENCE_PENDING', 'ASSESSMENT', 'INVESTIGATING', 'CARRIER_REVIEW', 'CLOSED'];

    private const FINAL = ['PAID', 'CLOSED', 'DECLINED', 'WITHDRAWN'];

    public function __construct(private readonly WorkspaceDataScope $scope) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => 'nullable|string|max:400', 'q' => 'nullable|string|max:100']);
        $t = $this->tenant();
        /** @var User $user */
        $user = $request->user();
        $page = ListCursor::from($request, 25, 100);
        $statuses = array_values(array_filter(array_map('trim', explode(',', strtoupper((string) $request->query('status', ''))))));
        $search = trim((string) $request->query('q', ''));

        $q = $this->scope->claims(Claim::query()->where('claims.tenant_id', $t), $user)
            ->with(['claimant:id,display_name', 'policy:id,policy_number,carrier_id', 'assignee:id,full_name'])
            ->when($statuses !== [], fn ($w) => $w->whereIn('claims.status', $statuses))
            ->when($search !== '', fn ($w) => $w->where(fn ($x) => $x->where('claims.claim_number', 'ilike', '%'.$search.'%')
                ->orWhereIn('claims.claimant_party_id', DB::table('parties')->where('display_name', 'ilike', '%'.$search.'%')->select('id'))))
            ->orderByRaw("CASE WHEN claims.status IN ('PAID','CLOSED','DECLINED','WITHDRAWN') THEN 1 ELSE 0 END")
            ->orderByDesc('claims.submitted_at')->orderBy('claims.id');
        $locale = NotificationCatalog::requestLocale($request);
        $rows = $page->slice($page->apply($q)->get());

        return response()->json(['data' => $rows->map(fn (Claim $c) => $this->summary($c, $user, $locale))->values(), 'meta' => $page->meta()]);
    }

    public function show(string $claim, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->scoped($claim, $request), $request)]);
    }

    public function assignToMe(string $claim, Request $request, ClaimLifecycleService $claims): JsonResponse
    {
        $c = $this->scoped($claim, $request);
        if (in_array($c->status, self::FINAL, true) || $c->assigned_to === $request->user()->id) {
            throw ValidationException::withMessages(['claim' => __('mobile_workspace.claims.cannot_assign')]);
        }
        $claims->assign($c, $request->user(), 'MOBILE_SELF_ASSIGN', $request->user());

        return response()->json(['data' => $this->detail($c->refresh(), $request)]);
    }

    public function transition(string $claim, Request $request, ClaimLifecycleService $claims): JsonResponse
    {
        $c = $this->scoped($claim, $request);
        $data = $request->validate(['to_status' => ['required', Rule::in(self::MANUAL_TARGETS)], 'note' => 'nullable|string|max:2000', 'closure_summary' => 'nullable|string|max:2000']);
        abort_if($data['to_status'] === 'CLOSED' && ! $request->user()->hasPermission('claims.close'), 403, 'Permission denied.');
        if (! in_array($data['to_status'], $this->targets($c, $request->user()), true)) {
            throw ValidationException::withMessages(['to_status' => __('mobile_workspace.claims.transition_blocked', ['reason' => $data['to_status']])]);
        }
        $details = array_filter(['note' => $data['note'] ?? null, 'closure_summary' => $data['closure_summary'] ?? null, 'channel' => 'MOBILE_WORKSPACE']);
        try {
            $claims->transition($c, $data['to_status'], 'MOBILE_WORKSPACE', $details, $request->user());
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['to_status' => __('mobile_workspace.claims.transition_blocked', ['reason' => $e->getMessage()])]);
        }

        return response()->json(['data' => $this->detail($c->refresh(), $request)]);
    }

    public function proposeDecision(string $claim, Request $request, ClaimLifecycleService $claims): JsonResponse
    {
        $c = $this->scoped($claim, $request);
        $data = $request->validate([
            'decision' => 'required|in:APPROVE,PARTIAL,DECLINE', 'approved_amount_minor' => 'required_unless:decision,DECLINE|nullable|integer|min:0',
            'reason_code' => 'required|string|max:64', 'rationale' => 'required|string|min:20|max:5000',
        ]);
        $data['approved_amount_minor'] = $data['decision'] === 'DECLINE' ? 0 : (int) $data['approved_amount_minor'];
        $claims->proposeDecision($c, $data, $request->user());

        return response()->json(['data' => $this->detail($c->refresh(), $request)], 201);
    }

    public function approveDecision(string $claim, string $decision, Request $request, ClaimLifecycleService $claims): JsonResponse
    {
        $c = $this->scoped($claim, $request);
        abort_unless(Str::isUuid($decision), 404);
        $d = ClaimDecision::where('claim_id', $c->id)->whereKey($decision)->firstOrFail();
        $claims->approveDecision($d, $request->user());

        return response()->json(['data' => $this->detail($c->refresh(), $request)]);
    }

    // ---------------------------------------------------------------- shapes

    private function scoped(string $id, Request $request): Claim
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->scope->claims(Claim::query()->where('claims.tenant_id', $this->tenant()), $request->user())
            ->with(['claimant:id,display_name', 'policy', 'assignee:id,full_name'])->whereKey($id)->firstOrFail();
    }

    private function statusLabel(?string $status, string $locale): string
    {
        $label = __('mobile_workspace.status.'.$status, [], $locale);

        return $label === 'mobile_workspace.status.'.$status ? (string) Str::of((string) $status)->replace('_', ' ')->lower()->ucfirst() : $label;
    }

    private function summary(Claim $c, User $me, string $locale): array
    {
        return [
            'id' => $c->id, 'claim_number' => $c->claim_number, 'status' => $c->status, 'status_label' => $this->statusLabel($c->status, $locale),
            'priority' => $c->priority, 'claimant_name' => $c->claimant?->display_name, 'policy_number' => $c->policy?->policy_number,
            'estimated_loss_minor' => $c->estimated_loss_minor !== null ? (int) $c->estimated_loss_minor : null, 'currency' => $c->currency ?? 'XAF',
            'submitted_at' => $c->submitted_at?->toIso8601String(), 'loss_occurred_at' => $c->loss_occurred_at?->toIso8601String(),
            'assigned_to_me' => $c->assigned_to !== null && $c->assigned_to === $me->id, 'assignee_name' => $c->assignee?->full_name,
        ];
    }

    /** @return list<string> manual status targets the caller may move $c to now */
    private function targets(Claim $c, User $me): array
    {
        if (! $me->hasPermission('claims.transition')) {
            return [];
        }

        return array_values(array_filter(self::MANUAL_TARGETS, function (string $to) use ($c, $me) {
            $t = ClaimMachine::transitionTo((string) $c->status, $to);

            return $t !== null && $t->event !== 'withdraw' && ($to !== 'CLOSED' || $me->hasPermission('claims.close'));
        }));
    }

    private function detail(Claim $c, Request $request): array
    {
        /** @var User $me */
        $me = $request->user();
        $locale = NotificationCatalog::requestLocale($request);
        $c->loadMissing(['claimant:id,display_name', 'policy', 'assignee:id,full_name']);
        $pending = ClaimDecision::where(['claim_id' => $c->id, 'status' => 'PENDING_APPROVAL'])->latest()->first();
        $targets = $this->targets($c, $me);

        $actions = [];
        if ($me->hasPermission('claims.assign') && ! in_array($c->status, self::FINAL, true) && $c->assigned_to !== $me->id) {
            $actions[] = 'assign_to_me';
        }
        if ($targets !== []) {
            $actions[] = 'transition';
        }
        if ($me->hasPermission('claims.decision.propose') && $c->status === 'CARRIER_REVIEW' && ! $pending) {
            $actions[] = 'propose_decision';
        }
        if ($me->hasPermission('claims.decision.approve') && $pending && $pending->proposed_by !== $me->id) {
            $actions[] = 'approve_decision';
        }

        $experts = app(ExpertAssignmentService::class);
        $mine = $me->hasPermission('claims.experts.work') ? $experts->providerIdsFor($me) : [];
        $assignments = $mine === [] ? [] : DB::table('claim_assignments')->where('claim_id', $c->id)->where('assignment_type', 'EXPERT')
            ->whereIn('provider_profile_id', $mine)->orderByDesc('assigned_at')->pluck('id')
            ->map(fn ($id) => $experts->find($this->tenant(), (string) $id))
            ->map(fn ($a) => [
                'id' => $a->id, 'status' => $a->status, 'status_label' => $this->statusLabel($a->status, $locale), // The adjuster's own moves only (accept_report / return_report / cancel belong to the insurer).
                'available_events' => array_values(array_filter((array) $a->available_events, fn ($e) => (\App\Application\Claims\Adjusters\ExpertAssignmentLifecycle::TRANSITIONS[$e][2] ?? null) === 'ADJUSTER')),
                'instructions' => $a->instructions, 'inspection_scheduled_for' => $a->inspection_scheduled_for, 'inspection_location' => $a->inspection_location,
                'report_submitted_at' => $a->report_submitted_at, 'assigned_at' => $a->assigned_at,
            ])->values()->all();

        return $this->summary($c, $me, $locale) + [
            'loss_location' => $c->loss_location,
            'description' => $c->loss_details['description'] ?? ($c->loss_details['incident']['description'] ?? null),
            'current_reserve_minor' => $c->current_reserve_minor !== null ? (int) $c->current_reserve_minor : null,
            'approved_amount_minor' => $c->approved_amount_minor !== null ? (int) $c->approved_amount_minor : null,
            'actions' => $actions,
            'transitions' => array_map(fn ($to) => ['to_status' => $to, 'label' => $this->statusLabel($to, $locale)], $targets),
            'pending_decision' => $pending ? [
                'id' => $pending->id, 'decision' => $pending->decision, 'approved_amount_minor' => (int) $pending->approved_amount_minor, 'reason_code' => $pending->reason_code,
                'rationale' => $pending->rationale, 'proposed_by_me' => $pending->proposed_by === $me->id, 'proposed_at' => $pending->created_at?->toIso8601String(),
            ] : null,
            'expert_assignments' => $assignments,
            'timeline' => DB::table('claim_events')->where('claim_id', $c->id)->orderBy('occurred_at')->get(['to_status', 'reason_code', 'occurred_at'])->map(fn ($e) => [
                'to_status' => $e->to_status, 'label' => $this->statusLabel($e->to_status, $locale), 'reason_code' => $e->reason_code,
                'occurred_at' => \Carbon\Carbon::parse($e->occurred_at)->toIso8601String(),
            ])->values(),
        ];
    }
}
