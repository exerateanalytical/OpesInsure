<?php

declare(strict_types=1);

namespace App\Application\Operations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent B6 — REQ-OPS-001 correlation view (ESR OPS-012). Everything the platform recorded under one correlation id:
 * every table that carries a `correlation_id` column (discovered from the schema, so new tables join automatically —
 * audit_log, journals, case_events, approval_requests, engine_evaluations, …), outbox messages whose metadata carries it,
 * and the workflow transitions of the subjects those audit rows touched (workflow_transition_history has no correlation
 * column, so those rows are linked via the audited subject and marked linked_via=audit_subject).
 * Tenant-safe: only tables with a tenant_id column are read, always filtered to the caller's tenant.
 */
final class CorrelationViewService
{
    private const LIMIT_PER_SOURCE = 200;

    /** @return array{correlation_id:string, total:int, sources:array<string, list<object>>, timeline:list<array>, skipped:list<string>} */
    public function show(string $tenantId, string $correlationId): array
    {
        $sources = [];
        $skipped = [];
        foreach ($this->correlatedTables() as $table) {
            if (! Schema::hasColumn($table, 'tenant_id')) {
                $skipped[] = $table;

                continue;
            }
            $rows = DB::table($table)->where('tenant_id', $tenantId)->where('correlation_id', $correlationId)->limit(self::LIMIT_PER_SOURCE)->get();
            if ($rows->isNotEmpty()) {
                $sources[$table] = $rows->all();
            }
        }

        $outbox = DB::table('outbox_messages')->whereRaw("metadata->>'correlation_id' = ?", [$correlationId])->limit(self::LIMIT_PER_SOURCE)->get()
            ->filter(fn ($m) => $this->outboxInTenant($m, $tenantId, $sources));
        if ($outbox->isNotEmpty()) {
            $sources['outbox_messages'] = $outbox->values()->all();
        }

        $workflow = $this->workflowFor(collect($sources['audit_log'] ?? []));
        if ($workflow->isNotEmpty()) {
            $sources['workflow_transition_history'] = $workflow->all();
        }

        return [
            'correlation_id' => $correlationId,
            'total' => array_sum(array_map('count', $sources)),
            'sources' => $sources,
            'timeline' => $this->timeline($sources),
            'skipped' => $skipped,
        ];
    }

    /** @return list<string> */
    private function correlatedTables(): array
    {
        return DB::table('information_schema.columns')->where('table_schema', DB::raw('current_schema()'))->where('column_name', 'correlation_id')
            ->orderBy('table_name')->pluck('table_name')->reject(fn ($t) => $t === 'outbox_messages')->values()->all();
    }

    /** outbox_messages has no tenant column: keep a message only when it names the tenant or an aggregate already seen in this tenant's rows. */
    private function outboxInTenant(object $m, string $tenantId, array $sources): bool
    {
        $payload = json_decode((string) $m->payload, true) ?: [];
        $meta = json_decode((string) $m->metadata, true) ?: [];
        if (($payload['tenant_id'] ?? $meta['tenant_id'] ?? null) === $tenantId) {
            return true;
        }
        foreach ($sources as $rows) {
            foreach ($rows as $r) {
                if (($r->id ?? null) === $m->aggregate_id || ($r->subject_id ?? null) === $m->aggregate_id) {
                    return true;
                }
            }
        }

        return false;
    }

    private function workflowFor(Collection $audit): Collection
    {
        $subjects = $audit->pluck('subject_id')->filter()->unique()->values();
        if ($subjects->isEmpty() || ! Schema::hasTable('workflow_transition_history')) {
            return collect();
        }
        $from = $audit->min('created_at');
        $to = $audit->max('created_at');

        return DB::table('workflow_transition_history')->whereIn('subject_id', $subjects->all())
            ->whereBetween('occurred_at', [now()->parse($from)->subMinute(), now()->parse($to)->addMinute()])
            ->orderBy('occurred_at')->limit(self::LIMIT_PER_SOURCE)->get()->map(function ($r) {
                $r->linked_via = 'audit_subject';

                return $r;
            });
    }

    private function timeline(array $sources): array
    {
        $out = [];
        foreach ($sources as $source => $rows) {
            foreach ($rows as $r) {
                $out[] = [
                    'at' => $r->occurred_at ?? $r->created_at ?? $r->posted_at ?? null,
                    'source' => $source,
                    'id' => $r->id ?? null,
                    'what' => $r->action ?? $r->event_name ?? $r->event ?? $r->status ?? null,
                    'subject' => isset($r->subject_type) ? $r->subject_type.':'.($r->subject_id ?? '') : (isset($r->aggregate_type) ? $r->aggregate_type.':'.$r->aggregate_id : null),
                ];
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $out;
    }
}
