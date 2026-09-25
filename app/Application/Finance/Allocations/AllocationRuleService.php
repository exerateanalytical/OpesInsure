<?php

declare(strict_types=1);

namespace App\Application\Finance\Allocations;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PAY-004 — versioned, per-tenant allocation-order rule.
 *
 * strategy OLDEST_DUE_FIRST: earliest due date first, category priority breaks ties.
 * strategy PRIORITY_FIRST:   category priority first, earliest due date breaks ties.
 * A new version supersedes the previous one; versions are never edited. With no tenant
 * rule the platform default (version 0) applies: oldest-due-first, taxes/levies/fees before premium.
 */
final class AllocationRuleService
{
    public const STRATEGIES = ['OLDEST_DUE_FIRST', 'PRIORITY_FIRST'];

    public const CATEGORIES = ['TAX', 'LEVY', 'STAMP_DUTY', 'FEE', 'OTHER', 'PREMIUM'];

    public const DEFAULT = ['id' => null, 'version' => 0, 'strategy' => 'OLDEST_DUE_FIRST', 'priority' => self::CATEGORIES];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /** @return array{id: ?string, version: int, strategy: string, priority: list<string>} */
    public function active(string $tenantId): array
    {
        $r = DB::table('allocation_rule_versions')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderByDesc('version')->first();

        return $r ? ['id' => $r->id, 'version' => (int) $r->version, 'strategy' => $r->strategy, 'priority' => json_decode($r->priority, true)] : self::DEFAULT;
    }

    public function history(string $tenantId)
    {
        return DB::table('allocation_rule_versions')->where('tenant_id', $tenantId)->orderByDesc('version')->get()
            ->map(fn ($r) => ['id' => $r->id, 'version' => (int) $r->version, 'strategy' => $r->strategy, 'priority' => json_decode($r->priority, true),
                'status' => $r->status, 'effective_from' => $r->effective_from, 'reason' => $r->reason]);
    }

    /** @param list<string> $priority */
    public function publish(string $tenantId, string $strategy, array $priority, string $reason, ?User $actor): array
    {
        if (! in_array($strategy, self::STRATEGIES, true)) {
            throw ValidationException::withMessages(['strategy' => ['Unknown allocation strategy.']]);
        }
        $priority = array_values(array_map('strtoupper', $priority));
        if (array_diff($priority, self::CATEGORIES) !== [] || count(array_unique($priority)) !== count($priority)) {
            throw ValidationException::withMessages(['priority' => ['Priority must list distinct categories from: '.implode(', ', self::CATEGORIES).'.']]);
        }
        // Categories left out keep their default relative order after the listed ones.
        $priority = array_values(array_merge($priority, array_diff(self::CATEGORIES, $priority)));

        return DB::transaction(function () use ($tenantId, $strategy, $priority, $reason, $actor) {
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();
            $version = (int) DB::table('allocation_rule_versions')->where('tenant_id', $tenantId)->max('version') + 1;
            $now = now();
            DB::table('allocation_rule_versions')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->update(['status' => 'SUPERSEDED', 'updated_at' => $now]);
            $id = (string) Str::uuid();
            DB::table('allocation_rule_versions')->insert(['id' => $id, 'tenant_id' => $tenantId, 'version' => $version, 'strategy' => $strategy,
                'priority' => json_encode($priority), 'status' => 'ACTIVE', 'effective_from' => $now, 'created_by' => $actor?->id, 'reason' => $reason,
                'created_at' => $now, 'updated_at' => $now]);
            $this->audit->record('finance.allocation_rule.published', 'allocation_rule_version', $id, ['version' => $version, 'strategy' => $strategy, 'priority' => $priority], $reason);
            $this->outbox->record('finance.allocation_rule.published', 'allocation_rule_version', $id, ['tenant_id' => $tenantId, 'version' => $version]);

            return $this->active($tenantId);
        });
    }

    public static function componentCategory(string $component): string
    {
        return match ($component) {
            'TAX' => 'TAX', 'LEVY' => 'LEVY', 'STAMP_DUTY' => 'STAMP_DUTY', 'SERVICE_FEE' => 'FEE', 'NET_PREMIUM' => 'PREMIUM', default => 'OTHER',
        };
    }

    public static function obligationCategory(string $type): string
    {
        return match (strtoupper($type)) {
            'TAX' => 'TAX', 'LEVY' => 'LEVY', 'STAMP_DUTY' => 'STAMP_DUTY', 'FEE' => 'FEE', 'PREMIUM', 'INSTALMENT' => 'PREMIUM', default => 'OTHER',
        };
    }

    /**
     * Order allocation targets by the rule. Each target: category, due_at (nullable = due now), key (stable tie-break).
     *
     * @param  list<array<string, mixed>>  $targets
     * @return list<array<string, mixed>>
     */
    public static function order(array $targets, array $rule): array
    {
        $rank = array_flip($rule['priority']);
        $due = fn ($t) => $t['due_at'] ? strtotime((string) $t['due_at']) : 0;
        usort($targets, function ($a, $b) use ($rank, $due, $rule) {
            $p = ($rank[$a['category']] ?? 99) <=> ($rank[$b['category']] ?? 99);
            $d = $due($a) <=> $due($b);
            $primary = $rule['strategy'] === 'PRIORITY_FIRST' ? [$p, $d] : [$d, $p];

            return $primary[0] ?: ($primary[1] ?: strcmp($a['key'], $b['key']));
        });

        return $targets;
    }
}
