<?php

declare(strict_types=1);

namespace App\Application\Cases;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\Models\WorkCase;
use App\Application\Events\OutboxWriter;
use App\Domain\Shared\Clock\Clock;
use App\Interfaces\Http\Middleware\AssignCorrelationId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Append-only case_events (INV-6.4) + outbox (ICE §6.8 events) + audit_log.
 * Callers hold the case row lock, so seq is gap-free per case.
 */
final class CaseJournal
{
    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox, private readonly Clock $clock) {}

    /** @param array<string, mixed> $payload */
    public function event(WorkCase $case, string $type, array $payload = [], ?string $from = null, ?string $to = null, ?string $actorId = null): void
    {
        $seq = (int) DB::table('case_events')->where('case_id', $case->id)->max('seq') + 1;
        DB::table('case_events')->insert([
            'id' => (string) Str::uuid(), 'case_id' => $case->id, 'seq' => $seq, 'type' => $type,
            'from_status' => $from, 'to_status' => $to, 'actor_id' => $actorId,
            'payload' => json_encode($payload ?: new \stdClass, JSON_THROW_ON_ERROR),
            'correlation_id' => self::correlationId(),
            'occurred_at' => $this->clock->now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function publish(string $event, string $aggregateType, string $aggregateId, array $payload): void
    {
        $this->outbox->record($event, $aggregateType, $aggregateId, $payload, ['correlation_id' => self::correlationId()]);
    }

    /** @param array<string, mixed> $metadata */
    public function audit(string $action, WorkCase $case, array $metadata = [], ?string $reason = null, array $context = []): void
    {
        $this->audit->record($action, 'case', $case->id, $metadata + ['case_number' => $case->case_number, 'case_type' => $case->case_type_code], $reason, $context + ['branch_id' => $case->branch_id]);
    }

    public static function correlationId(): ?string
    {
        return rescue(fn () => AssignCorrelationId::current(), null, false);
    }
}
