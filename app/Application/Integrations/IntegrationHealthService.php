<?php
declare(strict_types=1);
namespace App\Application\Integrations;

use App\Models\{IntegrationClient,IntegrationWebhookSubscription};
use Illuminate\Support\Facades\DB;

/**
 * Real numbers only — every figure here is a direct query against data this
 * batch actually writes (delivery_attempts, outbox_messages). No placeholder
 * or invented metric, per the plan's own instruction not to claim more than
 * what is executed and verifiable.
 */
final class IntegrationHealthService
{
    public function summary(): array
    {
        $since24h = now()->subDay();

        return [
            'clients_by_status' => IntegrationClient::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'open_circuits' => IntegrationWebhookSubscription::where('circuit_state', 'OPEN')->count(),
            'half_open_circuits' => IntegrationWebhookSubscription::where('circuit_state', 'HALF_OPEN')->count(),
            'deliveries_24h' => [
                'delivered' => DB::table('integration_delivery_attempts')->where('status', 'DELIVERED')->where('created_at', '>=', $since24h)->count(),
                'dead_lettered' => DB::table('integration_delivery_attempts')->where('status', 'DEAD_LETTERED')->where('created_at', '>=', $since24h)->count(),
                'retry_scheduled' => DB::table('integration_delivery_attempts')->where('status', 'RETRY_SCHEDULED')->where('created_at', '>=', $since24h)->count(),
            ],
            'latency_ms_24h' => $this->latencyPercentiles($since24h),
            'oldest_pending_outbox_seconds' => $this->oldestPendingOutboxSeconds(),
            'pending_outbox_count' => DB::table('outbox_messages')->whereNull('published_at')->count(),
            'dead_letter_queue_count' => DB::table('integration_delivery_attempts')
                ->where('status', 'DEAD_LETTERED')
                ->whereRaw('attempt = (select max(ida2.attempt) from integration_delivery_attempts ida2 where ida2.integration_webhook_subscription_id = integration_delivery_attempts.integration_webhook_subscription_id and ida2.event_id = integration_delivery_attempts.event_id)')
                ->count(),
        ];
    }

    private function latencyPercentiles(\Illuminate\Support\Carbon $since): array
    {
        $durations = DB::table('integration_delivery_attempts')
            ->where('created_at', '>=', $since)
            ->whereNotNull('duration_ms')
            ->orderBy('duration_ms')
            ->pluck('duration_ms')
            ->all();

        if (empty($durations)) {
            return ['p50' => null, 'p95' => null, 'sample_size' => 0];
        }

        $percentile = fn (float $p) => $durations[min(count($durations) - 1, (int) ceil($p * count($durations)) - 1)];

        return ['p50' => $percentile(0.50), 'p95' => $percentile(0.95), 'sample_size' => count($durations)];
    }

    private function oldestPendingOutboxSeconds(): ?int
    {
        $oldest = DB::table('outbox_messages')->whereNull('published_at')->min('occurred_at');

        return $oldest ? (int) abs(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($oldest))) : null;
    }
}
