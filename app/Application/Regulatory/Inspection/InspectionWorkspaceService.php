<?php

declare(strict_types=1);

namespace App\Application\Regulatory\Inspection;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Security\PrivilegedAccessService;
use App\Models\PrivilegedAccessGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agent B1 — REQ-RPT-006 regulatory inspection workspace.
 *
 * An inspection wraps a time-boxed privileged_access_grant (purpose REGULATORY_INSPECTION, scope = resources) requested
 * by compliance and approved by a second person (PrivilegedAccessService maker-checker). While the grant is live the
 * inspector can only READ the scoped resources of the tenant; every read is logged as a privileged-access USED event
 * and every export is written to regulatory_inspection_exports (with a content hash).
 */
final class InspectionWorkspaceService
{
    public const PURPOSE = 'REGULATORY_INSPECTION';

    /** resource => [table, columns, date column] (explicit columns: no free-text PII blobs). */
    public const RESOURCES = [
        'evaluations' => ['engine_evaluations', ['id', 'engine', 'operation', 'subject_type', 'subject_id', 'outcome', 'blocking', 'reference_at', 'inputs_hash', 'reasons', 'warnings', 'created_at'], 'created_at'],
        'audit' => ['audit_log', ['id', 'sequence', 'actor_id', 'action', 'subject_type', 'subject_id', 'reason_code', 'entry_hash', 'previous_hash', 'created_at'], 'created_at'],
        'engine-runs' => ['rating_runs', ['id', 'quote_id', 'tariff_version_id', 'status', 'engine_version', 'input_hash', 'output_hash', 'reference_at', 'completed_at', 'created_at'], 'created_at'],
        'policies' => ['policies', ['id', 'policy_number', 'status', 'carrier_id', 'currency', 'premium_minor', 'issued_at', 'coverage_starts_at', 'coverage_ends_at', 'terms_hash', 'created_at'], 'created_at'],
        'claims' => ['claims', ['id', 'claim_number', 'policy_id', 'status', 'currency', 'estimated_loss_minor', 'current_reserve_minor', 'approved_amount_minor', 'loss_occurred_at', 'submitted_at', 'closed_at', 'created_at'], 'created_at'],
    ];

    public function __construct(private readonly PrivilegedAccessService $access, private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function open(string $tenant, User $inspector, array $d, User $requester): object
    {
        $resources = array_values(array_unique($d['resources']));
        sort($resources);
        return DB::transaction(function () use ($tenant, $inspector, $d, $requester, $resources) {
            $grant = $this->access->request($tenant, $inspector, [
                'purpose' => self::PURPOSE, 'justification' => $d['justification'], 'scope' => $resources,
                'starts_at' => CarbonImmutable::parse($d['starts_at']), 'expires_at' => CarbonImmutable::parse($d['expires_at']),
            ], $requester);
            $id = (string) Str::uuid();
            DB::table('regulatory_inspections')->insert(['id' => $id, 'tenant_id' => $tenant, 'privileged_access_grant_id' => $grant->id,
                'inspector_user_id' => $inspector->id, 'authority' => $d['authority'], 'reference' => $d['reference'] ?? null,
                'resources' => json_encode($resources, JSON_THROW_ON_ERROR), 'status' => 'REQUESTED', 'opened_by' => $requester->id,
                'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('regulatory.inspection.requested', 'regulatory_inspection', $id, ['grant_id' => $grant->id, 'authority' => $d['authority']]);

            return $this->find($tenant, $id);
        });
    }

    public function approve(string $tenant, string $id, User $approver): object
    {
        $i = $this->find($tenant, $id);
        $this->access->approve(PrivilegedAccessGrant::findOrFail($i->privileged_access_grant_id), $approver);
        DB::table('regulatory_inspections')->where('id', $id)->update(['status' => 'ACTIVE', 'updated_at' => now()]);
        $this->outbox->record('regulatory.inspection.opened', 'regulatory_inspection', $id, ['inspection_id' => $id, 'inspector_user_id' => $i->inspector_user_id, 'expires_at' => (string) $i->expires_at]);

        return $this->find($tenant, $id);
    }

    public function close(string $tenant, string $id, string $reason, User $actor): object
    {
        $i = $this->find($tenant, $id);
        if ($i->status === 'CLOSED') {
            return $i;
        }
        $this->access->revoke(PrivilegedAccessGrant::findOrFail($i->privileged_access_grant_id), $reason, $actor);
        DB::table('regulatory_inspections')->where('id', $id)->update(['status' => 'CLOSED', 'closed_by' => $actor->id, 'closed_at' => now(), 'updated_at' => now()]);
        $this->outbox->record('regulatory.inspection.closed', 'regulatory_inspection', $id, ['inspection_id' => $id, 'reason' => $reason]);

        return $this->find($tenant, $id);
    }

    public function find(string $tenant, string $id): object
    {
        $i = DB::table('regulatory_inspections as i')->join('privileged_access_grants as g', 'g.id', '=', 'i.privileged_access_grant_id')
            ->where('i.tenant_id', $tenant)->where('i.id', $id)
            ->first(['i.*', 'g.starts_at', 'g.expires_at', 'g.status as grant_status', 'g.revoked_at']);
        abort_unless($i !== null, 404);
        $i->resources = json_decode($i->resources, true);

        return $i;
    }

    /** Read-only page of a scoped resource for the inspector (logged as privileged-access USED). */
    public function read(string $tenant, string $id, string $resource, array $filters, User $inspector): array
    {
        [$i, $q] = $this->query($tenant, $id, $resource, $filters, $inspector);
        $rows = $q->limit(min((int) ($filters['limit'] ?? 200), 1000))->get();

        return ['inspection_id' => $i->id, 'resource' => $resource, 'read_only' => true, 'expires_at' => $i->expires_at, 'rows' => $rows];
    }

    /** CSV export of a scoped resource; every export is logged with its row count and content hash. */
    public function export(string $tenant, string $id, string $resource, array $filters, User $inspector): array
    {
        [$i, $q] = $this->query($tenant, $id, $resource, $filters, $inspector);
        $cols = self::RESOURCES[$resource][1];
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $cols);
        $count = 0;
        foreach ($q->limit(50000)->cursor() as $row) {
            fputcsv($fh, array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), array_values((array) $row)));
            $count++;
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        $hash = hash('sha256', $csv);
        $exportId = (string) Str::uuid();
        DB::table('regulatory_inspection_exports')->insert(['id' => $exportId, 'inspection_id' => $i->id, 'resource' => $resource, 'format' => 'csv',
            'filters' => json_encode(array_intersect_key($filters, array_flip(['from', 'to'])), JSON_THROW_ON_ERROR), 'row_count' => $count,
            'content_hash' => $hash, 'exported_by' => $inspector->id, 'exported_at' => now()]);
        $this->audit->record('regulatory.inspection.exported', 'regulatory_inspection', $i->id, ['export_id' => $exportId, 'resource' => $resource, 'rows' => $count, 'content_hash' => $hash]);

        return ['export_id' => $exportId, 'csv' => $csv, 'content_hash' => $hash, 'filename' => "inspection-{$resource}-".now()->format('Ymd-His').'.csv'];
    }

    public function exports(string $tenant, string $id): \Illuminate\Support\Collection
    {
        $this->find($tenant, $id);

        return DB::table('regulatory_inspection_exports')->where('inspection_id', $id)->orderBy('exported_at')->get();
    }

    private function query(string $tenant, string $id, string $resource, array $filters, User $inspector): array
    {
        $i = $this->find($tenant, $id);
        if (! isset(self::RESOURCES[$resource]) || ! in_array($resource, $i->resources, true)) {
            abort(403, 'Resource outside the inspection scope.');
        }
        if ($i->inspector_user_id !== $inspector->id || $i->status !== 'ACTIVE') {
            abort(403, 'Inspection is not active for this user.');
        }
        try {
            $this->access->use(PrivilegedAccessGrant::findOrFail($i->privileged_access_grant_id), 'regulatory_inspection', $i->id, self::PURPOSE, $inspector);
        } catch (ValidationException) {
            abort(403, 'Inspection access window is closed.');
        }
        [$table, $cols, $date] = self::RESOURCES[$resource];
        $q = DB::table($table)->where('tenant_id', $tenant)->select($cols)
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where($date, '>=', CarbonImmutable::parse($v)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where($date, '<', CarbonImmutable::parse($v)->startOfDay()->addDay()))
            ->orderBy($date)->orderBy('id');

        return [$i, $q];
    }
}
