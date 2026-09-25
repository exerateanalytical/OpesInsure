<?php

declare(strict_types=1);

namespace App\Application\Policies\IssuanceQueue\Http;

use App\Application\Policies\IssuanceQueue\IssuanceException;
use App\Application\Policies\IssuanceQueue\IssuanceQueueService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** REQ-POL-004 — failed / paid-not-issued issuance queue API (tenant-scoped). */
final class IssuanceQueueController
{
    public function __construct(private readonly IssuanceQueueService $queue) {}

    private function tenantId(): string
    {
        return app(TenantContext::class)->id();
    }

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => ['nullable', Rule::in(['OPEN', 'ESCALATED', 'RESOLVED', 'UNRESOLVED'])], 'kind' => ['nullable', Rule::in(IssuanceQueueService::KINDS)],
            'per_page' => 'nullable|integer|min:1|max:100']);
        $status = $d['status'] ?? 'UNRESOLVED';
        $page = IssuanceException::with('proposal:id,status,party_id', 'payment:id,amount_minor,currency,provider_reference,reconciled_at')
            ->where('tenant_id', $this->tenantId())
            ->when($status === 'UNRESOLVED', fn ($q) => $q->where('status', '<>', 'RESOLVED'), fn ($q) => $q->where('status', $status))
            ->when($d['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->orderBy('created_at')->paginate((int) ($d['per_page'] ?? 25));

        return response()->json(['data' => $page->items(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(string $exception): JsonResponse
    {
        $ex = $this->find($exception);

        return response()->json(['data' => $ex->toArray() + ['events' => DB::table('issuance_exception_events')->where('issuance_exception_id', $ex->id)->orderBy('occurred_at')->get()]]);
    }

    public function scan(Request $r): JsonResponse
    {
        $d = $r->validate(['grace_minutes' => 'nullable|integer|min:0|max:10080', 'review_hours' => 'nullable|integer|min:1|max:720']);
        $found = $this->queue->scan($this->tenantId(), (int) ($d['grace_minutes'] ?? 30), (int) ($d['review_hours'] ?? 48));

        return response()->json(['data' => $found, 'meta' => ['recorded' => count($found)]]);
    }

    public function retry(Request $r, string $exception): JsonResponse
    {
        return response()->json(['data' => $this->queue->retry($this->find($exception), $r->user())]);
    }

    public function escalate(Request $r, string $exception): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|max:1000', 'escalated_to' => 'nullable|uuid|exists:users,id']);
        if (! empty($d['escalated_to']) && ! DB::table('tenant_memberships')->where(['tenant_id' => $this->tenantId(), 'user_id' => $d['escalated_to'], 'status' => 'ACTIVE'])->exists()) {
            return response()->json(['message' => 'The escalation target must be an active member of this tenant.', 'errors' => ['escalated_to' => ['Not a member of this tenant.']]], 422);
        }

        return response()->json(['data' => $this->queue->escalate($this->find($exception), $r->user(), $d['reason'], $d['escalated_to'] ?? null)]);
    }

    public function resolve(Request $r, string $exception): JsonResponse
    {
        $d = $r->validate(['resolution' => ['required', Rule::in(IssuanceQueueService::RESOLUTIONS)], 'notes' => 'required|string|max:2000']);

        return response()->json(['data' => $this->queue->resolve($this->find($exception), $r->user(), $d['resolution'], $d['notes'])]);
    }

    private function find(string $id): IssuanceException
    {
        return IssuanceException::where('tenant_id', $this->tenantId())->whereKey($id)->firstOrFail();
    }
}
