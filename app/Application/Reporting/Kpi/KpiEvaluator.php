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

    /** R4: seconds a monthly trend series (monthly()) is served from cache. */
    public const MONTHLY_TTL = 120;

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
            // R4: identical record sets counted twice on one page (tiles + operations) run once per request.
            return ['value' => \App\Application\Identity\Rbac\RequestMemo::count($b), 'by_currency' => null];
        }
        $by = [];
        foreach ($b->selectRaw($q['currency_column'].' as ccy, COALESCE(SUM('.$q['sum_column'].'), 0) as total')->groupBy($q['currency_column'])->orderBy($q['currency_column'])->get() as $row) {
            $by[(string) $row->ccy] = (int) $row->total;
        }

        return ['value' => array_sum($by), 'by_currency' => $by];
    }

    /**
     * R4: the KPI per calendar month over [from, to] in ONE grouped query (same record set, filters and date basis as
     * value(), so each month equals value() for that month). Months without records are absent.
     *
     * @param  array<string, mixed>  $kpi
     * @param  array{from:string, to:string}  $period
     * @return array<string, array{value:int, by_currency:array<string,int>|null}> keyed 'YYYY-MM'
     */
    public function monthly(string $tenantId, array $kpi, array $period, array $filters = []): array
    {
        $q = KpiQueryRegistry::get($kpi['query_key']);
        $basis = $q['date_basis'] ?? throw ValidationException::withMessages(['kpi' => "{$kpi['query_key']} has no date basis."]);
        $b = $this->recordSet($tenantId, $kpi, $period, $filters);
        $month = "to_char({$basis}, 'YYYY-MM')";
        $out = [];
        // Trend charts only: the grouped rows are cached for MONTHLY_TTL seconds under the fingerprint of the final SQL
        // and bindings, which carries the tenant, the portal narrowing (carrier / book) and the period — two callers
        // share an entry only when they would read exactly the same records. Tiles (value()) stay live.
        $rows = fn ($query) => \Illuminate\Support\Facades\Cache::remember('kpi-monthly:'.md5($query->toSql().'|'.json_encode($query->getBindings())), self::MONTHLY_TTL,
            fn () => $query->get()->map(fn ($r) => (array) $r)->all());
        if ($q['unit'] !== KpiQueryRegistry::UNIT_MONEY) {
            foreach ($rows($b->selectRaw("{$month} as m, COUNT(*) as n")->groupByRaw($month)) as $row) {
                $out[(string) $row['m']] = ['value' => (int) $row['n'], 'by_currency' => null];
            }

            return $out;
        }
        foreach ($rows($b->selectRaw("{$month} as m, {$q['currency_column']} as ccy, COALESCE(SUM({$q['sum_column']}), 0) as total")->groupByRaw("{$month}, {$q['currency_column']}")) as $row) {
            $row = (object) $row;
            $out[(string) $row->m]['by_currency'][(string) $row->ccy] = (int) $row->total;
        }
        foreach ($out as $m => $v) {
            ksort($v['by_currency']);
            $out[$m] = ['value' => array_sum($v['by_currency']), 'by_currency' => $v['by_currency']];
        }

        return $out;
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
        $total = \App\Application\Identity\Rbac\RequestMemo::count(clone $b); // R4: the tile of the same KPI already counted it
        $rows = $b->select($q['record']['columns'])->orderBy($q['record']['id'])->forPage($page, $perPage)->get()->map(fn ($r) => (array) $r)->all();

        return ['resource' => $q['record']['resource'], 'columns' => array_map(fn ($c) => substr($c, strrpos($c, '.') + 1), $q['record']['columns']),
            'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @param array<string, mixed> $kpi */
    private function recordSet(string $tenantId, array $kpi, array $period, array $filters): Builder
    {
        $q = KpiQueryRegistry::get($kpi['query_key']);
        // Inside the insurer / broker portal the record set is narrowed to the caller's carrier or book (no-op elsewhere).
        $b = \App\Application\WebExperiences\PortalScope::narrowTable(KpiQueryRegistry::base($kpi['query_key'], $tenantId), (string) ($q['sources'][0] ?? ''));
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
