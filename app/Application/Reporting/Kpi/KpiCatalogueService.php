<?php

declare(strict_types=1);

namespace App\Application\Reporting\Kpi;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Agent B2 — REQ-RPT-003 KPI governance (MPS §88; FRP IX). Each KPI carries name, definition, formula, sources,
 * date basis, filters, currency, owner and version. Tenant KPIs are versioned and maker-checker approved
 * (DRAFT → PENDING_APPROVAL → ACTIVE | REJECTED; ACTIVE → RETIRED; approving a new version retires the previous one).
 *
 * Until a tenant approves its own version, every registered query is exposed as a SYSTEM_BASELINE KPI (version 0)
 * whose code equals the query key — so dashboards always have a governed definition to show, never an ad-hoc number.
 */
final class KpiCatalogueService
{
    public const DRAFT = 'DRAFT';

    public const PENDING = 'PENDING_APPROVAL';

    public const ACTIVE = 'ACTIVE';

    public const REJECTED = 'REJECTED';

    public const RETIRED = 'RETIRED';

    public const BASELINE = 'SYSTEM_BASELINE';

    private const OWNERS = ['policies' => 'UNDERWRITING', 'premium' => 'FINANCE', 'claims' => 'CLAIMS', 'quotes' => 'SALES', 'proposals' => 'SALES',
        'underwriting' => 'UNDERWRITING', 'payments' => 'FINANCE', 'receivables' => 'FINANCE', 'commissions' => 'FINANCE', 'renewals' => 'SALES',
        'kyc' => 'COMPLIANCE', 'refunds' => 'FINANCE', 'regulatory' => 'COMPLIANCE'];

    public function __construct(private OutboxWriter $outbox, private AuditWriter $audit) {}

    /** @return array<string, mixed> the baseline (version 0) definition of a registered query */
    public static function baseline(string $queryKey): array
    {
        $q = KpiQueryRegistry::get($queryKey);

        return [
            'id' => null, 'code' => $queryKey, 'version' => 0, 'status' => self::BASELINE, 'name' => $q['label'],
            'definition' => $q['label'].' — system baseline definition over the registered query '.$queryKey.'.',
            'formula' => self::formulaOf($q), 'query_key' => $queryKey, 'sources' => $q['sources'],
            'date_basis' => $q['date_basis'] ?? 'SNAPSHOT', 'filters' => [], 'currency' => null,
            'owner' => self::OWNERS[Str::before($queryKey, '.')] ?? 'PLATFORM', 'unit' => $q['unit'],
            'created_by' => null, 'approved_by' => null, 'approved_at' => null,
        ];
    }

    /** @return list<array<string, mixed>> the effective catalogue: tenant ACTIVE versions override baselines; tenant-only codes included. */
    public function catalogue(string $tenantId): array
    {
        $out = [];
        foreach (array_keys(KpiQueryRegistry::definitions()) as $key) {
            $out[$key] = self::baseline($key);
        }
        foreach (DB::table('kpi_definitions')->where('tenant_id', $tenantId)->where('status', self::ACTIVE)->orderBy('code')->get() as $row) {
            $out[$row->code] = $this->present($row);
        }
        ksort($out);

        return array_values($out);
    }

    /** @return array<string, mixed> the effective (ACTIVE or baseline) definition of a KPI code */
    public function resolve(string $tenantId, string $code): array
    {
        $row = DB::table('kpi_definitions')->where('tenant_id', $tenantId)->where('code', $code)->where('status', self::ACTIVE)->first();
        if ($row) {
            return $this->present($row);
        }
        if (KpiQueryRegistry::has($code)) {
            return self::baseline($code);
        }

        throw new NotFoundHttpException("Unknown KPI {$code}.");
    }

    /** @return list<array<string, mixed>> */
    public function versions(string $tenantId, string $code): array
    {
        return DB::table('kpi_definitions')->where('tenant_id', $tenantId)->where('code', $code)->orderByDesc('version')->get()->map(fn ($r) => $this->present($r))->all();
    }

    /** @param array<string, mixed> $data */
    public function draft(string $tenantId, User $maker, array $data): array
    {
        $queryKey = (string) ($data['query_key'] ?? '');
        if (! KpiQueryRegistry::has($queryKey)) {
            throw ValidationException::withMessages(['query_key' => 'KPIs may only use a registered query; free SQL is not accepted.']);
        }
        $q = KpiQueryRegistry::get($queryKey);
        $filters = $this->validFilters($q, (array) ($data['filters'] ?? []));
        $currency = isset($data['currency']) ? strtoupper((string) $data['currency']) : null;
        if ($currency !== null && ! isset($q['filters']['currency']) && $q['unit'] !== KpiQueryRegistry::UNIT_MONEY) {
            throw ValidationException::withMessages(['currency' => "Query {$queryKey} has no currency dimension."]);
        }
        $dateBasis = (string) ($data['date_basis'] ?? ($q['date_basis'] ?? 'SNAPSHOT'));
        if ($dateBasis !== ($q['date_basis'] ?? 'SNAPSHOT')) {
            throw ValidationException::withMessages(['date_basis' => 'The date basis must be the registered query\'s: '.($q['date_basis'] ?? 'SNAPSHOT').'.']);
        }

        return DB::transaction(function () use ($tenantId, $maker, $data, $queryKey, $q, $filters, $currency, $dateBasis): array {
            $code = (string) $data['code'];
            // Lock existing versions (aggregates cannot be FOR UPDATE); a concurrent first version is caught by unique(tenant_id, code, version).
            $version = (int) DB::table('kpi_definitions')->where('tenant_id', $tenantId)->where('code', $code)->lockForUpdate()->pluck('version')->max() + 1;
            $id = (string) Str::uuid();
            DB::table('kpi_definitions')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'code' => $code, 'version' => $version, 'name' => $data['name'], 'definition' => $data['definition'],
                'formula' => $data['formula'] ?? self::formulaOf($q), 'query_key' => $queryKey, 'sources' => json_encode($q['sources']),
                'date_basis' => $dateBasis, 'filters' => json_encode((object) $filters), 'currency' => $currency, 'owner' => $data['owner'], 'unit' => $q['unit'],
                'status' => self::DRAFT, 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('reporting.kpi_definition.drafted', 'kpi_definition', $id, ['code' => $code, 'version' => $version, 'query_key' => $queryKey]);

            return $this->present(DB::table('kpi_definitions')->find($id));
        });
    }

    public function submit(string $tenantId, string $id, User $maker): array
    {
        return $this->transition($tenantId, $id, self::DRAFT, self::PENDING, ['submitted_at' => now()], 'reporting.kpi_definition.submitted', $maker);
    }

    public function approve(string $tenantId, string $id, User $checker): array
    {
        return DB::transaction(function () use ($tenantId, $id, $checker): array {
            $row = $this->locked($tenantId, $id);
            if ($row->created_by === $checker->id) {
                abort(403, 'Maker-checker: the author of a KPI version cannot approve it.');
            }
            if ($row->status !== self::PENDING) {
                abort(409, "KPI version is {$row->status}; expected ".self::PENDING.'.');
            }
            $previous = DB::table('kpi_definitions')->where('tenant_id', $tenantId)->where('code', $row->code)->where('status', self::ACTIVE)->lockForUpdate()->first();
            if ($previous) {
                DB::table('kpi_definitions')->where('id', $previous->id)->update(['status' => self::RETIRED, 'retired_at' => now(), 'updated_at' => now()]);
            }

            return $this->transition($tenantId, $id, self::PENDING, self::ACTIVE, ['approved_by' => $checker->id, 'approved_at' => now()], 'reporting.kpi_definition.approved', $checker,
                ['superseded_version' => $previous?->version]);
        });
    }

    public function reject(string $tenantId, string $id, User $checker, string $reason): array
    {
        return DB::transaction(function () use ($tenantId, $id, $checker, $reason): array {
            if ($this->locked($tenantId, $id)->created_by === $checker->id) {
                abort(403, 'Maker-checker: the author of a KPI version cannot reject it.');
            }

            return $this->transition($tenantId, $id, self::PENDING, self::REJECTED, ['rejected_by' => $checker->id, 'rejection_reason' => $reason], 'reporting.kpi_definition.rejected', $checker, ['reason' => $reason]);
        });
    }

    public function retire(string $tenantId, string $id, User $actor): array
    {
        return $this->transition($tenantId, $id, self::ACTIVE, self::RETIRED, ['retired_at' => now()], 'reporting.kpi_definition.retired', $actor);
    }

    private function transition(string $tenantId, string $id, string $from, string $to, array $set, string $event, User $actor, array $extra = []): array
    {
        return DB::transaction(function () use ($tenantId, $id, $from, $to, $set, $event, $actor, $extra): array {
            $row = $this->locked($tenantId, $id);
            if ($row->status !== $from) {
                abort(409, "KPI version is {$row->status}; expected {$from}.");
            }
            DB::table('kpi_definitions')->where('id', $id)->update($set + ['status' => $to, 'updated_at' => now()]);
            $payload = ['kpi_definition_id' => $id, 'tenant_id' => $tenantId, 'code' => $row->code, 'version' => $row->version, 'from' => $from, 'to' => $to, 'actor_id' => $actor->id] + $extra;
            $this->outbox->record($event, 'kpi_definition', $id, $payload);
            $this->audit->record($event, 'kpi_definition', $id, $payload);

            return $this->present(DB::table('kpi_definitions')->find($id));
        });
    }

    private function locked(string $tenantId, string $id): object
    {
        return DB::table('kpi_definitions')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first()
            ?? throw new NotFoundHttpException('KPI version not found.');
    }

    /** @param array<string, mixed> $q */
    private function validFilters(array $q, array $filters): array
    {
        $out = [];
        foreach ($filters as $name => $value) {
            if (! isset($q['filters'][$name])) {
                throw ValidationException::withMessages(["filters.{$name}" => "Filter {$name} is not permitted on this query (allowed: ".implode(', ', array_keys($q['filters'])).').']);
            }
            $values = is_array($value) ? array_values($value) : [$value];
            foreach ($values as $v) {
                if (! is_scalar($v) || strlen((string) $v) > 120) {
                    throw ValidationException::withMessages(["filters.{$name}" => 'Filter values must be short scalar values.']);
                }
            }
            $out[$name] = is_array($value) ? array_map('strval', $values) : (string) $value;
        }

        return $out;
    }

    /** @param array<string, mixed> $q */
    private static function formulaOf(array $q): string
    {
        return $q['unit'] === KpiQueryRegistry::UNIT_MONEY
            ? 'SUM('.$q['sum_column'].') per '.$q['currency_column'].' over the registered record set'
            : 'COUNT(records) over the registered record set';
    }

    private function present(object $r): array
    {
        return [
            'id' => $r->id, 'code' => $r->code, 'version' => (int) $r->version, 'status' => $r->status, 'name' => $r->name, 'definition' => $r->definition,
            'formula' => $r->formula, 'query_key' => $r->query_key, 'sources' => json_decode($r->sources, true), 'date_basis' => $r->date_basis,
            'filters' => json_decode($r->filters, true) ?: [], 'currency' => $r->currency, 'owner' => $r->owner, 'unit' => $r->unit,
            'created_by' => $r->created_by, 'submitted_at' => $r->submitted_at, 'approved_by' => $r->approved_by, 'approved_at' => $r->approved_at,
            'rejection_reason' => $r->rejection_reason, 'retired_at' => $r->retired_at,
        ];
    }
}
