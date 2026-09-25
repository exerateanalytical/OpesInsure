<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Finance\ExceptionCentre\FinanceExceptionCentre;
use App\Application\Integrations\IntegrationHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent B6 — REQ-OPS-005 unified operational exception queue (MPS §86 failed-transaction recovery).
 * Finance exceptions (issuance, reconciliation, refunds, clearing variances, overdue obligations, cashier sessions)
 * come verbatim from the Batch 10 FinanceExceptionCentre — not re-queried here. This class only adds the technical
 * sources the finance centre does not own: failed inbound webhooks, dead-lettered outbound deliveries, failed queue jobs,
 * stuck outbox messages and failed/retrying carrier exchange messages. Each section has the same shape as the
 * finance centre's (available, count, breakdown, items) so a client renders them uniformly.
 */
final class OperationalExceptionQueue
{
    public const OPERATIONAL_SOURCES = ['failed_webhooks', 'dead_lettered_deliveries', 'failed_jobs', 'stuck_outbox', 'carrier_exchange_failures'];

    /** Outbox messages still unpublished after this many dispatch attempts are "stuck". Mirrors the dispatcher's retry window. */
    public const STUCK_OUTBOX_ATTEMPTS = 3;

    private const ITEM_LIMIT = 50;

    public function __construct(private FinanceExceptionCentre $finance, private IntegrationMonitorService $integrations, private IntegrationHealthService $health) {}

    public static function sources(): array
    {
        return [...FinanceExceptionCentre::SOURCES, ...self::OPERATIONAL_SOURCES];
    }

    /** @param list<string>|null $only */
    public function summary(string $tenantId, bool $platformSources, ?CarbonImmutable $asOf = null, ?array $only = null): array
    {
        $financeOnly = $only === null ? null : array_values(array_intersect($only, FinanceExceptionCentre::SOURCES));
        $finance = $only !== null && $financeOnly === [] ? ['sources' => []] : $this->finance->summary($tenantId, $asOf, $financeOnly);
        $sources = array_map(fn ($s) => ['domain' => 'finance'] + $s, $finance['sources']);

        foreach (self::OPERATIONAL_SOURCES as $source) {
            if ($only !== null && ! in_array($source, $only, true)) {
                continue;
            }
            $sources[$source] = ['domain' => 'operations'] + match (true) {
                $source === 'carrier_exchange_failures' => $this->carrierExchangeFailures($tenantId),
                ! $platformSources => ['available' => false, 'reason' => 'Platform-level source: requires operations.platform.view.', 'count' => 0, 'breakdown' => [], 'items' => []],
                default => $this->{lcfirst(str_replace('_', '', ucwords($source, '_')))}(),
            };
        }

        return [
            'as_of' => ($asOf ?? CarbonImmutable::now())->toIso8601String(),
            'total_open' => array_sum(array_column($sources, 'count')),
            'by_domain' => ['finance' => array_sum(array_column(array_filter($sources, fn ($s) => $s['domain'] === 'finance'), 'count')), 'operations' => array_sum(array_column(array_filter($sources, fn ($s) => $s['domain'] === 'operations'), 'count'))],
            'sources' => $sources,
        ];
    }

    private function failedWebhooks(): array
    {
        $base = DB::table('webhook_inbox')->whereIn('status', IntegrationMonitorService::WEBHOOK_FAILED_STATUSES);

        return ['available' => true, 'count' => (clone $base)->count(),
            'breakdown' => (clone $base)->selectRaw('provider as k, count(*) as n')->groupBy('provider')->pluck('n', 'k')->map(fn ($n) => (int) $n)->all(),
            'items' => $this->integrations->failedWebhooks()];
    }

    private function deadLetteredDeliveries(): array
    {
        $items = $this->integrations->deadLetteredDeliveries();

        return ['available' => true, 'count' => (int) $this->health->summary()['dead_letter_queue_count'], 'breakdown' => [], 'items' => $items];
    }

    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['available' => false, 'reason' => 'Table failed_jobs is not installed.', 'count' => 0, 'breakdown' => [], 'items' => []];
        }
        $base = DB::table('failed_jobs');

        return ['available' => true, 'count' => (clone $base)->count(),
            'breakdown' => (clone $base)->selectRaw('queue as k, count(*) as n')->groupBy('queue')->pluck('n', 'k')->map(fn ($n) => (int) $n)->all(),
            'items' => (clone $base)->orderByDesc('failed_at')->limit(self::ITEM_LIMIT)->get(['uuid', 'connection', 'queue', 'failed_at'])->all()];
    }

    private function stuckOutbox(): array
    {
        $base = DB::table('outbox_messages')->whereNull('published_at')->where('attempts', '>=', self::STUCK_OUTBOX_ATTEMPTS);

        return ['available' => true, 'count' => (clone $base)->count(),
            'breakdown' => (clone $base)->selectRaw('event_name as k, count(*) as n')->groupBy('event_name')->pluck('n', 'k')->map(fn ($n) => (int) $n)->all(),
            'items' => (clone $base)->orderBy('occurred_at')->limit(self::ITEM_LIMIT)->get(['id', 'event_name', 'aggregate_type', 'aggregate_id', 'attempts', 'occurred_at'])->all()];
    }

    /** Tenant-scoped through the claim the message belongs to. */
    private function carrierExchangeFailures(string $tenantId): array
    {
        if (! Schema::hasTable('carrier_exchange_messages') || ! Schema::hasColumn('carrier_exchange_messages', 'claim_id')) {
            return ['available' => false, 'reason' => 'Table carrier_exchange_messages is not installed.', 'count' => 0, 'breakdown' => [], 'items' => []];
        }
        $base = DB::table('carrier_exchange_messages as m')->join('claims as c', 'c.id', '=', 'm.claim_id')->where('c.tenant_id', $tenantId)
            ->whereIn('m.status', ['FAILED', 'RETRY_PENDING']);

        return ['available' => true, 'count' => (clone $base)->count(),
            'breakdown' => (clone $base)->selectRaw('m.status as k, count(*) as n')->groupBy('m.status')->pluck('n', 'k')->map(fn ($n) => (int) $n)->all(),
            'items' => (clone $base)->orderBy('m.updated_at')->limit(self::ITEM_LIMIT)->get(['m.id', 'm.claim_id', 'm.message_type', 'm.direction', 'm.status', 'm.failure_reason', 'm.updated_at'])->all()];
    }
}
