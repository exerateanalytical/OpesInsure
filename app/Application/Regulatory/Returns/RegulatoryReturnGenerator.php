<?php

declare(strict_types=1);

namespace App\Application\Regulatory\Returns;

use App\Application\Compliance\RegulatoryReportingService;
use App\Models\RegulatoryReportDefinition;
use App\Models\RegulatoryReportRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agent B1 — REQ-RPT-001 regulatory returns with data lineage.
 *
 * A return is a configured regulatory_report_definition (maker-checker approved by RegulatoryReportingService) whose
 * `schema` declares a whitelisted dataset, filters, optional group_by and columns. Art. 411 / Art. 557 mappings are
 * expressed by pointing the definition at a regulatory_reporting_categories code — no CIMA return layout is built in.
 *
 * Schema: {dataset, columns:[{key, field, aggregate?: sum|count}], group_by?:[field], filters?:{field:[values]}}
 * Each generated run stores the exact SQL it executed (source_query), the definition version it used (source_version)
 * and one lineage row per (output row, source record) with a hash of the source record as read.
 */
final class RegulatoryReturnGenerator
{
    public const ENGINE_VERSION = 'rpt-gen-1';

    /** dataset => [table, tenant column, period column, field => SQL expression] */
    private const DATASETS = [
        'policies' => ['policies', 'tenant_id', 'issued_at', [
            'id' => 'id', 'policy_number' => 'policy_number', 'status' => 'status', 'carrier_id' => 'carrier_id', 'currency' => 'currency',
            'premium_minor' => 'premium_minor', 'issued_at' => 'issued_at', 'coverage_starts_at' => 'coverage_starts_at', 'coverage_ends_at' => 'coverage_ends_at',
            'line_code' => "COALESCE(NULLIF(terms_snapshot->>'line_code', ''), 'UNSPECIFIED')",
        ]],
        'claims' => ['claims', 'tenant_id', 'submitted_at', [
            'id' => 'id', 'claim_number' => 'claim_number', 'policy_id' => 'policy_id', 'status' => 'status', 'currency' => 'currency',
            'estimated_loss_minor' => 'estimated_loss_minor', 'current_reserve_minor' => 'current_reserve_minor', 'approved_amount_minor' => 'approved_amount_minor',
            'loss_occurred_at' => 'loss_occurred_at', 'submitted_at' => 'submitted_at', 'closed_at' => 'closed_at',
        ]],
        'commission_accruals' => ['commission_accruals', 'tenant_id', 'created_at', [
            'id' => 'id', 'policy_id' => 'policy_id', 'partner_id' => 'partner_id', 'status' => 'status', 'currency' => 'currency',
            'amount_minor' => 'amount_minor', 'clawed_back_minor' => 'clawed_back_minor', 'paid_minor' => 'paid_minor', 'created_at' => 'created_at',
        ]],
    ];

    public function __construct(private readonly RegulatoryReportingService $reports) {}

    /** Validates a definition schema before it is stored (called from the definition endpoint). */
    public function validateSchema(array $schema): void
    {
        $ds = self::DATASETS[$schema['dataset'] ?? ''] ?? null;
        if ($ds === null) {
            throw ValidationException::withMessages(['schema.dataset' => 'Unknown dataset; allowed: '.implode(', ', array_keys(self::DATASETS)).'.']);
        }
        $fields = $ds[3];
        $group = $schema['group_by'] ?? [];
        foreach ([...$group, ...array_keys($schema['filters'] ?? [])] as $f) {
            if (! isset($fields[$f])) {
                throw ValidationException::withMessages(['schema' => "Unknown field [{$f}] for dataset {$schema['dataset']}."]);
            }
        }
        if (empty($schema['columns']) || ! is_array($schema['columns'])) {
            throw ValidationException::withMessages(['schema.columns' => 'At least one column is required.']);
        }
        foreach ($schema['columns'] as $c) {
            $agg = $c['aggregate'] ?? null;
            if (! is_string($c['key'] ?? null) || ($agg !== 'count' && ! isset($fields[$c['field'] ?? '']))) {
                throw ValidationException::withMessages(['schema.columns' => 'Each column needs a key and a known field.']);
            }
            if ($agg !== null && ! in_array($agg, ['sum', 'count'], true)) {
                throw ValidationException::withMessages(['schema.columns' => 'aggregate must be sum or count.']);
            }
            if ($group !== [] && $agg === null && ! in_array($c['field'], $group, true)) {
                throw ValidationException::withMessages(['schema.columns' => "Column [{$c['key']}] must be aggregated or grouped."]);
            }
            if ($group === [] && $agg !== null) {
                throw ValidationException::withMessages(['schema.columns' => 'Aggregates need group_by.']);
            }
        }
    }

    public function generate(string $tenant, RegulatoryReportDefinition $def, array $in, User $actor): RegulatoryReportRun
    {
        if ($existing = RegulatoryReportRun::where(['tenant_id' => $tenant, 'idempotency_key' => $in['idempotency_key']])->first()) {
            return $existing;
        }
        if (RegulatoryReportRun::where(['tenant_id' => $tenant, 'definition_id' => $def->id, 'period_key' => $in['period_key']])->exists()) {
            throw ValidationException::withMessages(['period_key' => 'A run already exists for this definition and period.']);
        }
        $schema = $def->schema;
        $this->validateSchema($schema);
        [$table, $tenantCol, $periodCol, $fields] = self::DATASETS[$schema['dataset']];
        $from = CarbonImmutable::parse($in['period_from'])->startOfDay();
        $to = CarbonImmutable::parse($in['period_to'])->startOfDay()->addDay();

        $select = collect($fields)->map(fn ($sql, $alias) => DB::raw("{$sql} as \"{$alias}\""))->values()->all();
        $q = DB::table($table)->select($select)->where($tenantCol, $tenant)->where($periodCol, '>=', $from)->where($periodCol, '<', $to);
        foreach ($schema['filters'] ?? [] as $f => $values) {
            $q->whereIn(DB::raw($fields[$f]), (array) $values);
        }
        $q->orderBy('id');
        $sql = $q->toRawSql();
        $records = $q->get();

        [$rows, $sources] = $this->shape($schema, $records);
        $payload = ['definition' => ['code' => $def->code, 'version' => $def->version, 'regulatory_category_code' => $def->regulatory_category_code],
            'period' => ['from' => $in['period_from'], 'to' => $in['period_to']], 'columns' => array_column($schema['columns'], 'key'), 'rows' => $rows];

        return DB::transaction(function () use ($tenant, $def, $in, $actor, $payload, $sql, $rows, $sources, $table) {
            $run = $this->reports->prepare($tenant, $def, [
                'period_key' => $in['period_key'], 'idempotency_key' => $in['idempotency_key'], 'payload' => $payload,
                'period_from' => $in['period_from'], 'period_to' => $in['period_to'], 'source_query' => $sql,
                'source_version' => $def->code.'@v'.$def->version.'#'.substr($def->schema_hash, 0, 16).'/'.self::ENGINE_VERSION,
                'row_count' => count($rows), 'generated_at' => now(),
            ], $actor);
            $lineage = [];
            foreach ($sources as $i => $records) {
                $rowHash = hash('sha256', json_encode($rows[$i], JSON_THROW_ON_ERROR));
                foreach ($records as $r) {
                    $lineage[] = ['id' => (string) Str::uuid(), 'run_id' => $run->id, 'row_index' => $i, 'row_hash' => $rowHash, 'source_table' => $table,
                        'source_id' => $r->id, 'source_hash' => hash('sha256', json_encode((array) $r, JSON_THROW_ON_ERROR)), 'created_at' => now()];
                }
            }
            foreach (array_chunk($lineage, 500) as $chunk) {
                DB::table('regulatory_report_run_lineage')->insert($chunk);
            }

            return $run;
        });
    }

    /** @return array{0:list<array<string,mixed>>,1:list<list<object>>} */
    private function shape(array $schema, \Illuminate\Support\Collection $records): array
    {
        $group = $schema['group_by'] ?? [];
        if ($group === []) {
            $rows = $records->map(fn ($r) => collect($schema['columns'])->mapWithKeys(fn ($c) => [$c['key'] => $r->{$c['field']}])->all())->values()->all();

            return [$rows, $records->map(fn ($r) => [$r])->values()->all()];
        }
        $rows = [];
        $sources = [];
        foreach ($records->groupBy(fn ($r) => implode('|', array_map(fn ($f) => (string) $r->{$f}, $group)))->sortKeys() as $items) {
            $first = $items->first();
            $rows[] = collect($schema['columns'])->mapWithKeys(fn ($c) => [$c['key'] => match ($c['aggregate'] ?? null) {
                'sum' => (int) $items->sum(fn ($x) => (int) $x->{$c['field']}),
                'count' => $items->count(),
                default => $first->{$c['field']},
            }])->all();
            $sources[] = $items->values()->all();
        }

        return [$rows, $sources];
    }

    /** Lineage rows of a run, optionally for one output row. */
    public function lineage(RegulatoryReportRun $run, ?int $row = null): \Illuminate\Support\Collection
    {
        return DB::table('regulatory_report_run_lineage')->where('run_id', $run->id)->when($row !== null, fn ($q) => $q->where('row_index', $row))
            ->orderBy('row_index')->orderBy('source_id')->get(['row_index', 'row_hash', 'source_table', 'source_id', 'source_hash']);
    }
}
