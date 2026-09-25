<?php

declare(strict_types=1);

namespace App\Application\Ledger\Technical;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Batch 10-9 — REQ-ACC-004: IBNR / life actuarial values are imported from carrier or actuarial engines, never
 * computed by the platform. Each import is a new version for (tenant, kind, period_end); a different user must
 * approve it (maker-checker). Approval supersedes the previously approved version of the same scope.
 */
final class ActuarialImportService
{
    public const KINDS = ['IBNR', 'LIFE_MATH_RESERVE', 'LIFE_OTHER'];

    public function __construct(private AuditWriter $audit, private OutboxWriter $outbox) {}

    /** @param list<array{carrier_id?:?string,line_code?:?string,metric:string,amount_minor:int,currency:string}> $values */
    public function import(string $tenantId, string $kind, string $periodEnd, string $source, array $values, string $actorId, ?string $notes = null): object
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Unknown actuarial import kind.']);
        }
        if ($values === []) {
            throw ValidationException::withMessages(['values' => 'An import needs at least one value.']);
        }

        return DB::transaction(function () use ($tenantId, $kind, $periodEnd, $source, $values, $actorId, $notes) {
            DB::table('technical_actuarial_imports')->where(['tenant_id' => $tenantId, 'kind' => $kind, 'period_end' => $periodEnd])->lockForUpdate()->get(['id']);
            $version = (int) DB::table('technical_actuarial_imports')->where(['tenant_id' => $tenantId, 'kind' => $kind, 'period_end' => $periodEnd])->max('version') + 1;
            $id = (string) Str::uuid();
            $now = now();
            DB::table('technical_actuarial_imports')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'kind' => $kind, 'period_end' => $periodEnd, 'version' => $version,
                'status' => 'PENDING_APPROVAL', 'source' => $source, 'checksum' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)),
                'row_count' => count($values), 'notes' => $notes, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('technical_actuarial_values')->insert(array_map(fn ($v) => [
                'id' => (string) Str::uuid(), 'import_id' => $id, 'carrier_id' => $v['carrier_id'] ?? null,
                'line_code' => ($v['line_code'] ?? null) ?: null, 'metric' => strtoupper($v['metric']),
                'amount_minor' => (int) $v['amount_minor'], 'currency' => strtoupper($v['currency']), 'created_at' => $now,
            ], $values));
            $this->audit->record('technical.actuarial_import.created', 'technical_actuarial_import', $id, ['kind' => $kind, 'period_end' => $periodEnd, 'version' => $version]);
            $this->outbox->record('technical.actuarial_import.created', 'technical_actuarial_import', $id, ['kind' => $kind, 'period_end' => $periodEnd, 'version' => $version]);

            return $this->find($tenantId, $id);
        });
    }

    public function approve(string $tenantId, string $id, string $actorId): object
    {
        return $this->decide($tenantId, $id, $actorId, 'APPROVED', null);
    }

    public function reject(string $tenantId, string $id, string $actorId, string $reason): object
    {
        return $this->decide($tenantId, $id, $actorId, 'REJECTED', $reason);
    }

    public function find(string $tenantId, string $id): object
    {
        return DB::table('technical_actuarial_imports')->where(['tenant_id' => $tenantId, 'id' => $id])->first() ?? abort(404);
    }

    private function decide(string $tenantId, string $id, string $actorId, string $to, ?string $reason): object
    {
        return DB::transaction(function () use ($tenantId, $id, $actorId, $to, $reason) {
            $i = DB::table('technical_actuarial_imports')->where(['tenant_id' => $tenantId, 'id' => $id])->lockForUpdate()->first() ?? abort(404);
            if ($i->status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages(['status' => 'Only a pending import can be decided.']);
            }
            if ($i->created_by === $actorId) {
                throw ValidationException::withMessages(['approver' => 'The importer cannot decide their own import (maker-checker).']);
            }
            $now = now();
            if ($to === 'APPROVED') {
                DB::table('technical_actuarial_imports')->where(['tenant_id' => $tenantId, 'kind' => $i->kind, 'period_end' => $i->period_end, 'status' => 'APPROVED'])
                    ->update(['status' => 'SUPERSEDED', 'updated_at' => $now]);
            }
            DB::table('technical_actuarial_imports')->where('id', $id)->update(['status' => $to, 'decided_by' => $actorId, 'decided_at' => $now, 'decision_reason' => $reason, 'updated_at' => $now]);
            $event = $to === 'APPROVED' ? 'technical.actuarial_import.approved' : 'technical.actuarial_import.rejected';
            $this->audit->record($event, 'technical_actuarial_import', $id, ['kind' => $i->kind, 'version' => $i->version], $reason);
            $this->outbox->record($event, 'technical_actuarial_import', $id, ['kind' => $i->kind, 'period_end' => $i->period_end, 'version' => $i->version]);

            return $this->find($tenantId, $id);
        });
    }
}
