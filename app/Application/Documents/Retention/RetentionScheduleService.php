<?php

declare(strict_types=1);

namespace App\Application\Documents\Retention;

use App\Application\Audit\AuditWriter;
use App\Application\Documents\DocumentGovernanceProblem;
use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Events\OutboxWriter;
use App\Models\Document;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-DOC-009 retention schedules. Periods per record class are an owner decision (ICE gap 20), so none
 * are seeded: a schedule is drafted with its legal basis and becomes ACTIVE only after a second user approves.
 * Most specific ACTIVE schedule wins: document type > document group > security level > default (all null);
 * tenant-specific beats platform-wide.
 */
final class RetentionScheduleService
{
    public function __construct(private AuditWriter $audit, private OutboxWriter $outbox, private DocumentRegister $register) {}

    /** @param array<string, mixed> $d */
    public function draft(?string $tenantId, array $d, User $actor): object
    {
        if (! empty($d['document_type_code']) && $this->register->type((string) $d['document_type_code']) === null) {
            throw DocumentGovernanceProblem::make('UNKNOWN_DOCUMENT_TYPE', 422, 'Document type is not in the document register.');
        }
        if (! empty($d['security_level']) && ! in_array($d['security_level'], DocumentRegister::SECURITY_LEVELS, true)) {
            throw DocumentGovernanceProblem::make('UNKNOWN_SECURITY_LEVEL', 422, 'Unknown security level.');
        }
        // Gap Closure Pack file 10: retention classes are PLATFORM_NORMALIZED; durations are PENDING_LEGAL_VALIDATION (never seeded).
        \App\Application\OperationsTaxonomy\OperationsCatalogue::assert('retention_classes', $d['retention_class'] ?? null, 'retention_class');
        $id = (string) Str::uuid();
        DB::table('retention_schedules')->insert([
            'retention_class' => $d['retention_class'] ?? null, 'legal_hold_override' => (bool) ($d['legal_hold_override'] ?? true),
            'destruction_method' => $d['destruction_method'] ?? null, 'effective_from' => $d['effective_from'] ?? null, 'effective_until' => $d['effective_until'] ?? null,
            'source' => $d['source'] ?? null,
            'id' => $id, 'tenant_id' => $tenantId, 'code' => $d['code'], 'document_type_code' => $d['document_type_code'] ?? null,
            'document_group' => $d['document_group'] ?? null, 'security_level' => $d['security_level'] ?? null,
            'retention_years' => (int) $d['retention_years'], 'trigger_event' => $d['trigger_event'] ?? 'ISSUED_AT', 'disposition' => $d['disposition'] ?? 'DESTROY',
            'legal_basis' => $d['legal_basis'] ?? null, 'status' => 'DRAFT', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('document.retention_schedule.drafted', 'retention_schedule', $id, ['code' => $d['code']]);

        return DB::table('retention_schedules')->find($id);
    }

    public function approve(?string $tenantId, string $id, User $actor): object
    {
        $s = DB::table('retention_schedules')->where('id', $id)->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->first();
        if (! $s || $s->status !== 'DRAFT') {
            throw DocumentGovernanceProblem::make('NOT_DRAFT', 409, 'Only a DRAFT retention schedule can be approved.');
        }
        if ($s->created_by === $actor->id) {
            throw DocumentGovernanceProblem::make('MAKER_CHECKER', 403, 'The author of a retention schedule cannot approve it.');
        }
        // Pack gate (retention.status PENDING_LEGAL_VALIDATION): a class schedule only activates with its legal basis.
        if (($s->retention_class ?? null) !== null && trim((string) $s->legal_basis) === '') {
            throw DocumentGovernanceProblem::make('LEGAL_BASIS_REQUIRED', 422, 'A retention class schedule needs a validated legal basis before approval.');
        }
        DB::transaction(function () use ($s, $actor) {
            // one ACTIVE schedule per scope: the previous one is retired, never deleted
            DB::table('retention_schedules')->where('status', 'ACTIVE')->where('id', '<>', $s->id)
                ->where(fn ($q) => $s->tenant_id ? $q->where('tenant_id', $s->tenant_id) : $q->whereNull('tenant_id'))
                ->where(fn ($q) => $s->document_type_code ? $q->where('document_type_code', $s->document_type_code) : $q->whereNull('document_type_code'))
                ->where(fn ($q) => $s->document_group ? $q->where('document_group', $s->document_group) : $q->whereNull('document_group'))
                ->where(fn ($q) => $s->security_level ? $q->where('security_level', $s->security_level) : $q->whereNull('security_level'))
                ->update(['status' => 'RETIRED', 'updated_at' => now()]);
            DB::table('retention_schedules')->where('id', $s->id)->update(['status' => 'ACTIVE', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record('document.retention_schedule.approved', 'retention_schedule', $s->id, ['code' => $s->code]);
            $this->outbox->record('document.retention_schedule.approved', 'retention_schedule', $s->id, ['code' => $s->code, 'retention_years' => $s->retention_years, 'document_type_code' => $s->document_type_code]);
        });

        return DB::table('retention_schedules')->find($s->id);
    }

    public function scheduleFor(Document $d): ?object
    {
        return DB::table('retention_schedules')->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('tenant_id', $d->tenant_id)->orWhereNull('tenant_id'))
            ->where(fn ($q) => $q->whereNull('document_type_code')->orWhere('document_type_code', $d->document_type_code))
            ->where(fn ($q) => $q->whereNull('document_group')->orWhere('document_group', $d->document_group))
            ->where(fn ($q) => $q->whereNull('security_level')->orWhere('security_level', $d->security_level))
            ->orderByRaw('(document_type_code IS NOT NULL) DESC, (document_group IS NOT NULL) DESC, (security_level IS NOT NULL) DESC, (tenant_id IS NOT NULL) DESC')
            ->first();
    }

    /** Date from which the document may be disposed of; null = no schedule applies (retain indefinitely). */
    public function disposableFrom(Document $d): ?CarbonImmutable
    {
        $s = $this->scheduleFor($d);
        if (! $s) {
            return null;
        }
        $anchor = match ($s->trigger_event) {
            'VALID_UNTIL' => $d->valid_until,
            'ISSUED_AT' => $d->issued_at ?? $d->created_at,
            default => $d->created_at,
        };

        return $anchor ? CarbonImmutable::parse($anchor)->addYears((int) $s->retention_years) : null;
    }
}
