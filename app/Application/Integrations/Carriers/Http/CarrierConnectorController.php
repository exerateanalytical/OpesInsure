<?php

declare(strict_types=1);

namespace App\Application\Integrations\Carriers\Http;

use App\Application\Integrations\Carriers\CarrierConnectorService;
use Illuminate\Http\{JsonResponse,Request};

/** REQ-API-007 — carrier connector admin API (agent B3). */
final class CarrierConnectorController
{
    public function __construct(private readonly CarrierConnectorService $connectors) {}

    public function show(string $carrier): JsonResponse
    {
        $config = $this->connectors->config($carrier);
        abort_if($config === null, 404);

        return response()->json(['data' => $this->connectors->present($config)]);
    }

    public function configure(Request $r, string $carrier): JsonResponse
    {
        abort_unless(\Illuminate\Support\Facades\DB::table('carriers')->where('id', $carrier)->exists(), 404);
        $d = $r->validate([
            'transport' => 'required|in:API,MANUAL',
            'base_url' => 'nullable|url:https|max:500',
            'endpoints' => 'nullable|array',
            'endpoints.*' => 'string|max:200',
            'signing_key_id' => 'nullable|string|max:64',
            'integration_client_id' => 'nullable|uuid|exists:integration_clients,id',
            'timeout_seconds' => 'nullable|integer|min:1|max:120',
            'max_attempts' => 'nullable|integer|min:1|max:20',
            'base_backoff_seconds' => 'nullable|integer|min:1|max:86400',
            'status' => 'nullable|in:ACTIVE,DISABLED',
        ]);

        return response()->json(['data' => $this->connectors->configure($carrier, array_filter($d, fn ($v) => $v !== null), $r->user())]);
    }

    public function dispatch(string $message): JsonResponse
    {
        $m = $this->connectors->dispatch($message);

        return response()->json(['data' => ['id' => $m->id, 'status' => $m->status, 'attempt_count' => $m->attempt_count, 'next_attempt_at' => $m->next_attempt_at, 'fallback_reason' => $m->fallback_reason ?? null]]);
    }

    public function fallbackQueue(Request $r): JsonResponse
    {
        $d = $r->validate(['carrier_id' => 'nullable|uuid']);

        return response()->json(['data' => $this->connectors->fallbackQueue($d['carrier_id'] ?? null)]);
    }

    public function resolveFallback(Request $r, string $message): JsonResponse
    {
        $d = $r->validate([
            'resolution' => 'required|in:SENT_MANUALLY,REQUEUED,CANCELLED',
            'note' => 'required|string|max:1000',
            'external_reference' => 'nullable|string|max:190',
        ]);
        $m = $this->connectors->resolveFallback($message, $d['resolution'], $d['note'], $d['external_reference'] ?? null, $r->user());

        return response()->json(['data' => ['id' => $m->id, 'status' => $m->status, 'fallback_resolution' => $m->fallback_resolution]]);
    }

    public function sync(Request $r, string $carrier): JsonResponse
    {
        $d = $r->validate([
            'record_type' => 'required|string|max:64',
            'external_record_id' => 'required|string|max:190',
            'opesinsure_record_id' => 'required|uuid',
            'external_version' => 'nullable|string|max:64',
            'last_external_modified_at' => 'nullable|date',
            'fields' => 'nullable|array',
        ]);

        return response()->json(['data' => $this->connectors->syncRecord($carrier, $d)]);
    }

    public function resolveConflict(Request $r, string $mapping): JsonResponse
    {
        $d = $r->validate(['resolution' => 'required|in:KEEP_OPESINSURE,ACCEPT_EXTERNAL', 'note' => 'required|string|max:1000']);

        return response()->json(['data' => $this->connectors->resolveConflict($mapping, $d['resolution'], $d['note'], $r->user())]);
    }
}
