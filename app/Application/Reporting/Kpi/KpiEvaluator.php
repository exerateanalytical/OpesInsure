<?php

declare(strict_types=1);

namespace App\Application\Reporting\Kpi;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Agent B2 — REQ-RPT-003/004 evaluates a governed KPI definition over its registered query and returns the
 * record set behind it (drill-down). The definition's own filters are always applied; callers may narrow further
 * only with the query's whitelisted filters, and with a period on the query's date basis.
 */
final class KpiEvaluator
{
    public const MAX_PAGE = 200;

    /**
     * @param  array<string, mixed>  $kpi  a resolved definition (KpiCatalogueService::resolve)
     * @param  array{from?:?string, to?:?string, last_days?:?int}  $period
     * @param  array<string, mixed>  $filters
     * @return array{value:int, by_currency:array<string,int>|null}
     */
    public function value(string $tenantId, array $kpi, array $period = [], array $filters = []): array
    {
        $q = KpiQueryRegistry::get($kpi['query_key']);
        $b = $this->recordSet($tenantId, $kpi, $period, $filters);
        if ($q['unit'] !== KpiQueryRegistry::UNIT_MONEY) {
            return ['value' => (int) $b->count(), 'by_currency' => null];
        }
        $by = [];
        foreach ($b->selectRaw($q['currency_column'].' as ccy, COALESCE(SUM('.$q['sum_column'].'), 0) as total')->groupBy($q['currency_column'])->orderBy($q['currency_column'])->get() as $row) {
            $by[(string) $row->ccy] = (int) $row->total;
        }

        return ['value' => array_sum($by), 'by_currency' => $by];
    }

    /**
     * @param  array<string, mixed>  $kpi
     * @return array{resource:string, columns:list<string>, rows:list<array<string,mixed>>, total:int, page:int, per_page:int}
     */
    public function drill(string $tenantId, array $kpi, array $period = [], array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $q = KpiQueryRegistry::get($kpi['query_key']);
        $perPage = max(1, min(self::MAX_PAGE, $perPage));
        $page = max(1, $page);
        $b = $this->recordSet($tenantId, $kpi, $period, $filters);
        $total = (int) (clone $b)->count();
        $rows = $b->select($q['record']['columns'])->orderBy($q['record']['id'])->forPage($page, $perPage)->get()->map(fn ($r) => (array) $r)->all();

        return ['resource' => $q['record']['resource'], 'columns' => array_map(fn ($c) => substr($c, strrpos($c, '.') + 1), $q['record']['columns']),
            'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @param array<string, mixed> $kpi */
    private function recordSet(string $tenantId, array $kpi, array $period, array $filters): Builder
    {
        $q = KpiQueryRegistry::get($kpi['query_key']);
        $b = KpiQueryRegistry::base($kpi['query_key'], $tenantId);
        foreach ([(array) ($kpi['filters'] ?? []), $filters] as $set) {
            foreach ($set as $name => $value) {
                $column = $q['filters'][$name] ?? throw ValidationException::withMessages(["filters.{$name}" => "Filter {$name} is not permitted on {$kpi['query_key']}."]);
                is_array($value) ? $b->whereIn($column, array_map('strval', $value)) : $b->where($column, (string) $value);
            }
        }
        if (! empty($kpi['currency']) && isset($q['currency_column'])) {
            $b->where($q['currency_column'], $kpi['currency']);
        }
        $basis = $q['date_basis'] ?? null;
        if ($basis !== null) {
            if (! empty($period['last_days'])) {
                $b->where($basis, '>=', CarbonImmutable::now()->subDays((int) $period['last_days']));
            }
            if (! empty($period['from'])) {
                $b->where($basis, '>=', CarbonImmutable::parse($period['from'])->startOfDay());
            }
            if (! empty($period['to'])) {
                $b->where($basis, '<=', CarbonImmutable::parse($period['to'])->endOfDay());
            }
        }

        return $b;
    }
}
