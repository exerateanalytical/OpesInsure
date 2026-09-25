<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Audit\AuditWriter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agent B6 — REQ-OPS-001 queues & failed jobs (ESR OPS-006/007). Retry and forget delegate to Laravel's own
 * queue:retry / queue:forget (no parallel failed-job store) and are audited. Payload bodies are never returned:
 * only the job's display name, queue and a truncated exception.
 */
final class QueueConsoleService
{
    public function __construct(private AuditWriter $audit) {}

    public function failed(?string $queue = null, int $perPage = 25): LengthAwarePaginator
    {
        return DB::table('failed_jobs')->when($queue, fn ($q) => $q->where('queue', $queue))->orderByDesc('failed_at')
            ->paginate(min(max($perPage, 1), 100), ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->through(fn ($r) => $this->present($r));
    }

    public function retry(string $uuid): array
    {
        $job = $this->find($uuid);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit->record('operations.failed_job.retried', 'failed_job', null, ['uuid' => $uuid, 'queue' => $job->queue, 'job' => $this->present($job)['job']]);

        return ['uuid' => $uuid, 'retried' => true, 'still_failed' => DB::table('failed_jobs')->where('uuid', $uuid)->exists()];
    }

    public function forget(string $uuid, string $reason): array
    {
        $job = $this->find($uuid);
        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->audit->record('operations.failed_job.forgotten', 'failed_job', null, ['uuid' => $uuid, 'queue' => $job->queue, 'job' => $this->present($job)['job'], 'reason' => $reason]);

        return ['uuid' => $uuid, 'forgotten' => ! DB::table('failed_jobs')->where('uuid', $uuid)->exists()];
    }

    private function find(string $uuid): object
    {
        return DB::table('failed_jobs')->where('uuid', $uuid)->first() ?? throw ValidationException::withMessages(['uuid' => 'Failed job not found.']);
    }

    private function present(object $r): array
    {
        $payload = json_decode((string) $r->payload, true) ?: [];

        return [
            'uuid' => $r->uuid, 'connection' => $r->connection, 'queue' => $r->queue,
            'job' => $payload['displayName'] ?? ($payload['job'] ?? null), 'attempts' => $payload['attempts'] ?? null,
            'exception' => Str::limit(strtok((string) $r->exception, "\n") ?: '', 300), 'failed_at' => $r->failed_at,
        ];
    }
}
