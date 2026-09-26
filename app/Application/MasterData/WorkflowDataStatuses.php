<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Owner Workflow Data Master v1 — catalogue statuses for reference lists (table master_data_workflow_statuses).
 *
 * Each owner catalogue item (geography.public_holidays, vehicles.usage_classes, aviation.airport_master, …) records
 * its status, where it lives in the platform (a master-data list, the vehicle master, the party-role catalogue) and
 * the status of values already there. Status codes are the dataset's 7 codes (dataset.status_codes); the owner's
 * raw wording (PARTIALLY_COMPLETE, PENDING_MASTER_REVIEW) is kept in owner_status.
 *
 * Seeding is idempotent and never overwrites a row an admin edited or a VERIFIED row.
 */
final class WorkflowDataStatuses
{
    public const SOURCE = 'OWNER_WORKFLOW_DATA_MASTER_V1';

    /** Same 7 codes as the dataset (database/data/workflow_institutional_data_master_2026.json → dataset.status_codes). */
    public const CODES = ['VERIFIED', 'PLATFORM_NORMALIZED', 'UNVERIFIED', 'PENDING_SOURCE', 'CONFIG_REQUIRED', 'DEMO_ONLY', 'RETIRED'];

    /** Statuses that are not production values: the catalogue exists but must not be relied on as authoritative. */
    public const NON_PRODUCTION = ['PENDING_SOURCE', 'CONFIG_REQUIRED', 'UNVERIFIED', 'DEMO_ONLY'];

    public const TARGETS = ['MASTER_DATA', 'VEHICLE_MASTER', 'PARTY_ROLES', 'EXTERNAL'];

    private const TABLE = 'master_data_workflow_statuses';

    /**
     * @param  array<string, mixed>  $doc  a master-data file carrying workflow_statuses[]
     * @return list<string> warnings
     */
    public function seed(array $doc): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return ['master_data_workflow_statuses table missing: statuses not seeded.'];
        }
        $warnings = [];
        $now = now();
        foreach ($doc['workflow_statuses'] as $e) {
            $key = $e['owner_domain'].'.'.$e['owner_item'];
            if (! in_array($e['status'], self::CODES, true) || (isset($e['values_status']) && ! in_array($e['values_status'], self::CODES, true))) {
                $warnings[] = "Workflow status $key skipped: status not one of the 7 dataset codes.";
                continue;
            }
            if (! in_array($e['target'], self::TARGETS, true)) {
                $warnings[] = "Workflow status $key skipped: unknown target {$e['target']}.";
                continue;
            }
            if ($e['target'] === 'MASTER_DATA' && ! DB::table('master_data_lists')->where(['domain_code' => $e['domain'] ?? null, 'code' => $e['list'] ?? null])->exists()) {
                $warnings[] = "Workflow status $key: target list {$e['domain']}.{$e['list']} not found.";
            }
            $fields = [
                'owner_domain' => $e['owner_domain'], 'owner_item' => $e['owner_item'], 'status' => $e['status'], 'owner_status' => $e['owner_status'],
                'target' => $e['target'], 'domain_code' => $e['domain'] ?? null, 'list_code' => $e['list'] ?? null, 'values_status' => $e['values_status'] ?? null,
                'source' => (string) ($doc['workflow_status_source'] ?? self::SOURCE), 'source_version' => (string) ($doc['version'] ?? ''), 'effective_from' => $doc['effective_from'] ?? null,
                'note' => $e['note'] ?? null, 'mapping' => isset($e['mapping']) ? json_encode($e['mapping'], JSON_UNESCAPED_UNICODE) : null,
            ];
            $row = DB::table(self::TABLE)->where('item_key', $key)->first();
            if (! $row) {
                DB::table(self::TABLE)->insert($fields + ['id' => (string) Str::uuid(), 'item_key' => $key, 'is_seeded' => true, 'created_at' => $now, 'updated_at' => $now]);
            } elseif ($row->admin_modified_at === null && $row->status !== 'VERIFIED') {
                DB::table(self::TABLE)->where('id', $row->id)->update($fields + ['updated_at' => $now]);
            }
        }

        return $warnings;
    }

    /** Picker meta for one owner item (e.g. vehicles.detailed_generation_variant): status + "Other / Not listed" stays available. */
    public function pickerMeta(string $itemKey): array
    {
        $row = Schema::hasTable(self::TABLE) ? DB::table(self::TABLE)->where('item_key', $itemKey)->first() : null;

        return ['data_status' => $row?->status, 'owner_status' => $row?->owner_status, 'source' => $row?->source, 'allow_other' => true];
    }

    /** Status attached to one master-data list (null when the owner file says nothing about it). */
    public function forList(string $domain, string $list): ?array
    {
        return $this->byList()[$domain.'.'.$list] ?? null;
    }

    /** @return array<string, array{status: string, values_status: ?string, owner_item: string, source: string}> */
    public function byList(): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        return DB::table(self::TABLE)->where('target', 'MASTER_DATA')->whereNotNull('list_code')->orderBy('item_key')->get()
            ->mapWithKeys(fn ($r) => [$r->domain_code.'.'.$r->list_code => [
                'status' => $r->status, 'values_status' => $r->values_status, 'owner_item' => $r->item_key, 'source' => $r->source,
            ]])->all();
    }

    /**
     * Admin view: every owner catalogue item with its status, target and live value count (PENDING_SOURCE items show 0).
     *
     * @return list<array<string, mixed>>
     */
    public function all(?string $status = null): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }
        $counts = DB::table('master_data_values')->where('status', 'ACTIVE')->whereNull('tenant_id')->whereNull('merged_into_id')
            ->selectRaw("domain_code || '.' || list_code as k, count(*) as n")->groupBy('domain_code', 'list_code')->pluck('n', 'k');

        return DB::table(self::TABLE)->when($status, fn ($q) => $q->where('status', $status))->orderBy('owner_domain')->orderBy('owner_item')->get()
            ->map(fn ($r) => [
                'key' => $r->item_key, 'owner_domain' => $r->owner_domain, 'owner_item' => $r->owner_item,
                'status' => $r->status, 'owner_status' => $r->owner_status, 'production_ready' => ! in_array($r->status, self::NON_PRODUCTION, true),
                'target' => $r->target, 'domain' => $r->domain_code, 'list' => $r->list_code, 'values_status' => $r->values_status,
                'value_count' => $r->target === 'MASTER_DATA' && $r->list_code ? (int) ($counts[$r->domain_code.'.'.$r->list_code] ?? 0) : null,
                'source' => $r->source, 'source_version' => $r->source_version, 'effective_from' => $r->effective_from,
                'note' => $r->note, 'mapping' => $r->mapping ? json_decode($r->mapping, true) : null, 'admin_modified' => $r->admin_modified_at !== null,
            ])->all();
    }
}
