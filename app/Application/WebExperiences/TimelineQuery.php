<?php

declare(strict_types=1);

namespace App\Application\WebExperiences;

use Illuminate\Support\Facades\DB;

/**
 * REQ-UI-002 / SSR §26 timeline: reads the single hash-chained audit_log
 * (REQ-DUP-016 — no second event store) for one subject, tenant-scoped.
 * Each entry: timestamp, actor, role, event, result, reason, channel,
 * related document.
 */
final class TimelineQuery
{
    /** @return list<array{at:string, actor:?string, role:?string, event:string, result:?string, reason:?string, channel:?string, document_id:?string}> */
    public function for(string $subjectType, string $subjectId, ?string $tenantId, int $limit = 50): array
    {
        $rows = DB::table('audit_log as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.subject_type', $subjectType)
            ->where('a.subject_id', $subjectId)
            ->when($tenantId !== null, fn ($q) => $q->where(fn ($w) => $w->where('a.tenant_id', $tenantId)->orWhereNull('a.tenant_id')))
            ->orderByDesc('a.sequence')
            ->limit($limit)
            ->get(['a.created_at', 'a.actor_id', 'u.full_name', 'a.action', 'a.reason_code', 'a.source', 'a.metadata']);

        $roles = $this->roles($rows->pluck('actor_id')->filter()->unique()->values()->all(), $tenantId);

        return $rows->map(function ($r) use ($roles): array {
            $meta = is_string($r->metadata) ? (json_decode($r->metadata, true) ?: []) : (array) $r->metadata;
            $result = null;
            foreach (['status', 'decision', 'to'] as $k) {
                if (isset($meta[$k]) && is_scalar($meta[$k])) {
                    $result = (string) $meta[$k];
                    break;
                }
            }

            return [
                'at' => (string) $r->created_at,
                'actor' => $r->full_name ?? ($r->actor_id ? null : __('web_experience.timeline.system')),
                'role' => $r->actor_id ? ($roles[$r->actor_id] ?? null) : null,
                'event' => (string) $r->action,
                'result' => $result,
                'reason' => $r->reason_code,
                'channel' => $r->source,
                'document_id' => isset($meta['document_id']) && is_scalar($meta['document_id']) ? (string) $meta['document_id'] : null,
            ];
        })->all();
    }

    /** @return array<string, string> actor_id => role codes in this tenant */
    private function roles(array $actorIds, ?string $tenantId): array
    {
        if ($actorIds === []) {
            return [];
        }

        return DB::table('tenant_memberships')->whereIn('user_id', $actorIds)->where('status', 'ACTIVE')
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get(['user_id', 'role_code'])->groupBy('user_id')
            ->map(fn ($g) => $g->pluck('role_code')->unique()->implode(', '))->all();
    }
}
