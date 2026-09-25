<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\ApiFamilies;

use App\Domain\Tenancy\TenantContext;
use App\Models\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-API-004 — notifications family read side (list + detail) for the operations screens. Mutations stay on
 * POST notifications, notifications/{d}/retry|cancel (NotificationDeliveryService). Tenant-scoped; destination_hash
 * is never returned.
 */
final class NotificationDeliveryQueryController
{
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => 'sometimes|string|max:24', 'channel' => 'sometimes|in:SMS,EMAIL,PUSH,WHATSAPP', 'party_id' => 'sometimes|uuid', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $rows = $this->scoped()
            ->when(isset($d['status']), fn ($q) => $q->where('status', strtoupper($d['status'])))
            ->when(isset($d['channel']), fn ($q) => $q->where('channel', $d['channel']))
            ->when(isset($d['party_id']), fn ($q) => $q->where('party_id', $d['party_id']))
            ->latest()->paginate((int) ($d['per_page'] ?? 50));

        return response()->json(['data' => collect($rows->items())->map(fn (NotificationDelivery $n) => $this->row($n))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]]);
    }

    public function show(string $d): JsonResponse
    {
        return response()->json(['data' => $this->row($this->scoped()->findOrFail($d))]);
    }

    private function row(NotificationDelivery $n): array
    {
        return $n->only(['id', 'party_id', 'template_id', 'channel', 'status', 'provider', 'provider_reference', 'attempts', 'max_attempts', 'failure_reason']) + [
            'next_attempt_at' => $n->next_attempt_at?->toIso8601String(), 'sent_at' => $n->sent_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String(),
        ];
    }

    private function scoped()
    {
        return NotificationDelivery::query()->where('tenant_id', app(TenantContext::class)->id());
    }
}
