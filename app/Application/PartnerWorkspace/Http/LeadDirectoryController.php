<?php

declare(strict_types=1);

namespace App\Application\PartnerWorkspace\Http;

use App\Application\Cases\Models\DiaryEntry;
use App\Application\PartnerWorkspace\AgentLeadService;
use App\Application\PartnerWorkspace\LeadDirectoryService;
use App\Application\PartnerWorkspace\LeadPipeline;
use App\Domain\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-CRM-001 — lead directory, assignment, pipeline and activities (tenant/broker + agent activities). */
final class LeadDirectoryController
{
    public function __construct(private readonly LeadDirectoryService $leads, private readonly AgentLeadService $agentLeads) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    public function index(Request $r): JsonResponse
    {
        $f = $r->validate(['status' => ['nullable', Rule::in(LeadPipeline::STATUSES)], 'partner_id' => 'nullable|uuid', 'assigned_user_id' => 'nullable|uuid', 'q' => 'nullable|string|max:80']);

        return response()->json([
            'data' => $this->leads->list($r->user(), $this->tenant(), $f)->map(fn ($l) => self::lead($l))->values(),
            'meta' => ['pipeline' => $this->leads->pipeline($r->user(), $this->tenant())],
        ]);
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'full_name' => 'required|string|max:160', 'phone_e164' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'city' => 'nullable|string|max:80', 'product_interest' => 'nullable|string|max:32', 'notes' => 'nullable|string|max:2000',
            'source' => 'nullable|string|max:32', 'partner_id' => 'nullable|uuid', 'assigned_user_id' => 'nullable|uuid', 'auto_assign' => 'sometimes|boolean',
        ]);

        return response()->json(['data' => self::lead($this->leads->create($d, $r->user(), $this->tenant()))], 201);
    }

    public function show(Request $r, string $lead): JsonResponse
    {
        $l = $this->leads->find($lead, $r->user(), $this->tenant());

        return response()->json(['data' => self::lead($l) + [
            'assignments' => $this->leads->assignments($l),
            'activities' => $this->leads->activities($l)->map(fn ($e) => self::activity($e))->values(),
        ]]);
    }

    public function assign(Request $r, string $lead): JsonResponse
    {
        $d = $r->validate(['partner_id' => 'present|nullable|uuid', 'assigned_user_id' => 'sometimes|nullable|uuid', 'reason' => 'required|string|max:255']);

        return response()->json(['data' => self::lead($this->leads->assign($lead, $d, $r->user(), $this->tenant()))]);
    }

    public function transition(Request $r, string $lead): JsonResponse
    {
        $d = $r->validate(['status' => ['required', Rule::in(LeadPipeline::MANUAL_STATUSES)], 'lost_reason' => 'nullable|string|max:255']);

        return response()->json(['data' => self::lead($this->leads->transition($lead, $d['status'], $d['lost_reason'] ?? null, $r->user(), $this->tenant()))]);
    }

    public function activities(Request $r, string $lead): JsonResponse
    {
        return response()->json(['data' => $this->leads->activities($this->leads->find($lead, $r->user(), $this->tenant()))->map(fn ($e) => self::activity($e))->values()]);
    }

    public function addActivity(Request $r, string $lead): JsonResponse
    {
        $l = $this->leads->find($lead, $r->user(), $this->tenant());

        return response()->json(['data' => self::activity($this->leads->addActivity($l, $this->activityInput($r), $r->user()))], 201);
    }

    // ---- agent (mobile) activities: same diary, agent-owned lead lookup

    public function agentActivities(Request $r, string $lead): JsonResponse
    {
        return response()->json(['data' => $this->leads->activities($this->agentLeads->show($lead, $r->user(), $this->tenant()))->map(fn ($e) => self::activity($e))->values()]);
    }

    public function agentAddActivity(Request $r, string $lead): JsonResponse
    {
        $l = $this->agentLeads->show($lead, $r->user(), $this->tenant());

        return response()->json(['data' => self::activity($this->leads->addActivity($l, $this->activityInput($r), $r->user()))], 201);
    }

    private function activityInput(Request $r): array
    {
        return $r->validate(['entry_type' => ['required', Rule::in(LeadDirectoryService::ACTIVITY_TYPES)], 'body' => 'required|string|max:4000', 'follow_up_at' => 'nullable|date|after:now']);
    }

    public static function lead(object $l): array
    {
        return [
            'id' => $l->id, 'full_name' => $l->full_name, 'phone_e164' => $l->phone_e164, 'city' => $l->city, 'product_interest' => $l->product_interest,
            'notes' => $l->notes, 'source' => $l->source, 'status' => $l->status, 'next_statuses' => LeadPipeline::next($l->status), 'lost_reason' => $l->lost_reason,
            'partner_id' => $l->partner_id, 'assigned_user_id' => $l->assigned_user_id, 'converted_customer_id' => $l->converted_customer_id,
            'status_changed_at' => $l->status_changed_at ? Carbon::parse($l->status_changed_at)->toIso8601String() : null,
            'created_at' => Carbon::parse($l->created_at)->toIso8601String(), 'updated_at' => Carbon::parse($l->updated_at)->toIso8601String(),
        ];
    }

    public static function activity(DiaryEntry $e): array
    {
        return ['id' => $e->id, 'entry_type' => $e->entry_type, 'body' => $e->body, 'author_id' => $e->author_id,
            'follow_up_at' => $e->follow_up_at?->toIso8601String(), 'follow_up_notified_at' => $e->follow_up_notified_at?->toIso8601String(), 'created_at' => $e->created_at?->toIso8601String()];
    }
}
