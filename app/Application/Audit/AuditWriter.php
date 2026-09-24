<?php

declare(strict_types=1);

namespace App\Application\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single writer for the hash-chained audit_log (exposed as the audit_events view, REQ-DUP-016).
 *
 * REQ-AUD-001: append-only (DB trigger), serialised chain (advisory lock), verifiable by AuditChainVerifier.
 * REQ-AUD-002: actor, tenant, branch, entity, action, old/new, reason, source, IP/device, approval.
 */
final class AuditWriter
{
    /** Chain lock key; constant so every writer serialises on the same head. */
    public const CHAIN_LOCK = 7_302_114_001;

    public const HASH_VERSION = 2;

    /**
     * @param  array{old?: array|null, new?: array|null, branch_id?: string|null, approval_id?: string|null, source?: string|null, device_id?: string|null}  $context
     */
    public function record(string $action, string $subjectType, ?string $subjectId, array $metadata = [], ?string $reason = null, array $context = []): string
    {
        $id = (string) Str::uuid();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : rescue(fn () => request(), null, false);

        $row = [
            'id' => $id,
            'tenant_id' => $this->tenantId(),
            'branch_id' => $context['branch_id'] ?? null,
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'reason_code' => $reason,
            'old_values' => $context['old'] ?? null,
            'new_values' => $context['new'] ?? null,
            'metadata' => $metadata,
            'source' => $context['source'] ?? (app()->runningInConsole() && ! app()->runningUnitTests() ? 'console' : ($request?->is('api/*') ? 'api' : 'web')),
            'ip_address' => $request?->ip(),
            'device_id' => $context['device_id'] ?? ($request ? substr((string) $request->header('X-Device-Id', ''), 0, 128) ?: null : null),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) ?: null : null,
            'approval_id' => $context['approval_id'] ?? null,
            'correlation_id' => substr((string) ($request?->header('X-Request-Id') ?: Str::uuid()), 0, 64),
        ];

        DB::transaction(function () use ($row) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [self::CHAIN_LOCK]);
            }
            $previous = DB::table('audit_log')->orderByDesc('sequence')->value('entry_hash');
            $row['previous_hash'] = $previous;
            $row['entry_hash'] = self::hashV2($row);
            $row['hash_version'] = self::HASH_VERSION;
            $row['created_at'] = now();
            foreach (['old_values', 'new_values'] as $k) {
                $row[$k] = $row[$k] === null ? null : json_encode($row[$k], JSON_THROW_ON_ERROR);
            }
            $row['metadata'] = json_encode($row['metadata'] ?: new \stdClass, JSON_THROW_ON_ERROR);
            DB::table('audit_log')->insert($row);
        });

        return $id;
    }

    /** Convenience for field-level changes (old -> new) with a mandatory reason. */
    public function recordChange(string $action, string $subjectType, ?string $subjectId, array $old, array $new, string $reason, array $metadata = [], array $context = []): string
    {
        return $this->record($action, $subjectType, $subjectId, $metadata, $reason, ['old' => $old, 'new' => $new] + $context);
    }

    /** Canonical hash over the immutable content of a row (keys sorted recursively so jsonb round-trips verify). */
    public static function hashV2(array $row): string
    {
        $fields = [];
        foreach (['id', 'tenant_id', 'branch_id', 'actor_id', 'action', 'subject_type', 'subject_id', 'reason_code', 'old_values', 'new_values', 'metadata', 'source', 'approval_id', 'previous_hash'] as $k) {
            $fields[$k] = $row[$k] ?? null;
        }

        return hash('sha256', self::canonicalJson($fields));
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonicalise($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function canonicalise(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalise(...), $value);
        }
        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = self::canonicalise($v);
        }

        return $out === [] ? new \stdClass : $out;
    }

    private function tenantId(): ?string
    {
        return app()->bound(\App\Domain\Tenancy\TenantContext::class)
            ? rescue(fn () => app(\App\Domain\Tenancy\TenantContext::class)->id(), null, false)
            : null;
    }
}
