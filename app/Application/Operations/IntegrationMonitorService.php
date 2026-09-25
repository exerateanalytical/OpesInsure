<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Integrations\IntegrationHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent B6 — REQ-OPS-001 integration / payment / webhook monitoring (ESR OPS-008..011). Extends the existing
 * IntegrationHealthService summary (reused, not re-computed) with failed inbound webhooks, dead-lettered outbound
 * deliveries and per-provider payment status. Payment figures are tenant-scoped; webhook_inbox and outbound
 * deliveries are platform-level tables (no tenant column) and are shown to platform operators only.
 */
final class IntegrationMonitorService
{
    /** webhook_inbox lifecycle: RECEIVED → PROCESSED | FAILED. */
    public const WEBHOOK_FAILED_STATUSES = ['FAILED'];

    /** App\Domain\Payments\PaymentStatus values. */
    public const PAYMENT_FAILED_STATUSES = ['FAILED', 'EXPIRED'];

    public const PAYMENT_SUCCESS_STATUSES = ['SUCCEEDED'];

    private const LIMIT = 50;

    public function __construct(private IntegrationHealthService $integrations) {}

    public function summary(string $tenantId): array
    {
        return [
            'integrations' => $this->integrations->summary(),
            'webhooks' => $this->webhooks(),
            'payment_providers' => $this->paymentProviders($tenantId),
        ];
    }

    public function failedWebhooks(?string $provider = null): array
    {
        return DB::table('webhook_inbox')->whereIn('status', self::WEBHOOK_FAILED_STATUSES)->when($provider, fn ($q) => $q->where('provider', $provider))
            ->orderByDesc('received_at')->limit(self::LIMIT)
            ->get(['id', 'provider', 'external_event_id', 'status', 'failure_reason', 'processing_attempts', 'next_attempt_at', 'received_at', 'processed_at'])->all();
    }

    /** Latest attempt per (subscription, event) that ended DEAD_LETTERED — the same definition IntegrationHealthService counts. */
    public function deadLetteredDeliveries(): array
    {
        return DB::table('integration_delivery_attempts as a')
            ->join('integration_webhook_subscriptions as s', 's.id', '=', 'a.integration_webhook_subscription_id')
            ->where('a.status', 'DEAD_LETTERED')
            ->whereRaw('a.attempt = (select max(b.attempt) from integration_delivery_attempts b where b.integration_webhook_subscription_id = a.integration_webhook_subscription_id and b.event_id = a.event_id)')
            ->orderByDesc('a.created_at')->limit(self::LIMIT)
            ->get(['a.id', 'a.integration_webhook_subscription_id', 'a.event_id', 'a.attempt', 'a.response_status', 'a.failure_reason', 'a.created_at'])->all();
    }

    private function webhooks(): array
    {
        $since = now()->subDay();

        return [
            'by_status' => DB::table('webhook_inbox')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'failed_by_provider' => DB::table('webhook_inbox')->whereIn('status', self::WEBHOOK_FAILED_STATUSES)->selectRaw('provider, count(*) as n')->groupBy('provider')->pluck('n', 'provider')->map(fn ($n) => (int) $n)->all(),
            'received_24h' => DB::table('webhook_inbox')->where('received_at', '>=', $since)->count(),
            'awaiting_retry' => DB::table('webhook_inbox')->whereNotNull('next_attempt_at')->whereNull('processed_at')->count(),
        ];
    }

    private function paymentProviders(string $tenantId): array
    {
        if (! Schema::hasTable('payment_intents')) {
            return [];
        }
        $rows = DB::table('payment_intents')->where('tenant_id', $tenantId)->where('updated_at', '>=', now()->subDay())
            ->selectRaw('provider, status, count(*) as n, max(updated_at) as last_at')->groupBy('provider', 'status')->get();
        $configured = array_keys((array) config('payments.providers', []));
        $out = [];
        foreach ($rows as $r) {
            $p = $out[$r->provider] ??= ['provider' => $r->provider, 'configured' => in_array($r->provider, $configured, true), 'by_status_24h' => [], 'succeeded_24h' => 0, 'failed_24h' => 0, 'last_success_at' => null];
            $p['by_status_24h'][$r->status] = (int) $r->n;
            if (in_array(strtoupper($r->status), self::PAYMENT_SUCCESS_STATUSES, true)) {
                $p['succeeded_24h'] += (int) $r->n;
                $p['last_success_at'] = max($p['last_success_at'] ?? '', (string) $r->last_at);
            } elseif (in_array(strtoupper($r->status), self::PAYMENT_FAILED_STATUSES, true)) {
                $p['failed_24h'] += (int) $r->n;
            }
            $out[$r->provider] = $p;
        }
        foreach ($out as &$p) {
            $p['status'] = $p['failed_24h'] > 0 && $p['succeeded_24h'] === 0 ? 'DOWN' : ($p['failed_24h'] > 0 ? 'DEGRADED' : 'OK');
        }

        return array_values($out);
    }
}
