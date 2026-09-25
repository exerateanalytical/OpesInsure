<?php

declare(strict_types=1);

namespace App\Application\Claims\Adjusters\Http;

use App\Application\Claims\Adjusters\ExpertAssignmentLifecycle;
use App\Application\Claims\Adjusters\ExpertAssignmentService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** REQ-CLM-009 — adjuster-facing API (/v1/adjuster/assignments): the caller's own assignments only. */
final class AdjusterAssignmentController
{
    public function __construct(private readonly ExpertAssignmentService $svc, private readonly TenantContext $tenant) {}

    public function index(Request $r): JsonResponse
    {
        $r->validate(['status' => ['nullable', Rule::in(ExpertAssignmentLifecycle::STATES)]]);

        return response()->json(['data' => $this->svc->forAdjuster($this->tenant->id(), $r->user(), $r->query('status'))]);
    }

    public function show(Request $r, string $assignment): JsonResponse
    {
        return response()->json(['data' => $this->svc->forAdjusterOne($this->tenant->id(), $r->user(), $assignment)]);
    }

    public function accept(Request $r, string $assignment): JsonResponse
    {
        $this->svc->assertAdjusterOwns($this->tenant->id(), $r->user(), $assignment);
        $this->svc->accept($this->tenant->id(), $assignment, $r->user());

        return $this->show($r, $assignment);
    }

    public function decline(Request $r, string $assignment): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:5|max:5000']);
        $this->svc->assertAdjusterOwns($this->tenant->id(), $r->user(), $assignment);
        $this->svc->decline($this->tenant->id(), $assignment, $d['reason'], $r->user());

        return $this->show($r, $assignment);
    }

    public function scheduleInspection(Request $r, string $assignment): JsonResponse
    {
        $d = $r->validate(['scheduled_for' => 'required|date|after:now', 'location' => 'nullable|string|max:255']);
        $this->svc->assertAdjusterOwns($this->tenant->id(), $r->user(), $assignment);
        $this->svc->scheduleInspection($this->tenant->id(), $assignment, $d['scheduled_for'], $d['location'] ?? null, $r->user());

        return $this->show($r, $assignment);
    }

    public function recordInspection(Request $r, string $assignment): JsonResponse
    {
        $d = $r->validate(['inspected_at' => 'nullable|date', 'notes' => 'nullable|string|max:10000']);
        $this->svc->assertAdjusterOwns($this->tenant->id(), $r->user(), $assignment);
        $this->svc->recordInspection($this->tenant->id(), $assignment, $d['notes'] ?? null, $d['inspected_at'] ?? null, $r->user());

        return $this->show($r, $assignment);
    }

    public function submitReport(Request $r, string $assignment): JsonResponse
    {
        $d = $r->validate(['summary' => 'required|string|min:20|max:20000', 'assessed_loss_minor' => 'required|integer|min:0', 'document_id' => 'nullable|uuid']);
        $this->svc->assertAdjusterOwns($this->tenant->id(), $r->user(), $assignment);
        $this->svc->submitReport($this->tenant->id(), $assignment, $d, $r->user());

        return $this->show($r, $assignment);
    }
}
