<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\WebExperiences\Money;
use App\Filament\Admin\Pages\Launch\LaunchScreenPage;

/**
 * Launch 2026-10-02 (agent Q10): base of the admin screens that completed the PARTIAL / not-buildable rows of
 * docs/LAUNCH_SCREEN_GAPS_2026-09-29.md §3-§4. Same contract as LaunchScreenPage (opened by the API's GET
 * permission, tenant captured at mount), plus a row of KPI tiles above the table.
 * Labels: resources/lang/{en,fr}/admin_screens.php.
 */
abstract class AdminScreenPage extends LaunchScreenPage
{
    protected string $view = 'filament.admin.pages.admin-screen';

    public static function getNavigationLabel(): string
    {
        return __('admin_screens.nav.'.static::$screen);
    }

    public function getSubheading(): ?string
    {
        return __('admin_screens.intro.'.static::$screen);
    }

    protected static function col(string $key): string
    {
        return __('admin_screens.columns.'.$key);
    }

    protected static function kpi(string $key, int|float|string|null $value, ?string $tone = null, ?string $hint = null): array
    {
        return ['label' => __('admin_screens.kpis.'.$key), 'value' => $value ?? '—', 'tone' => $tone, 'hint' => $hint];
    }

    protected static function money(int|float|null $minor, ?string $currency = 'XAF'): string
    {
        return Money::display($minor === null ? null : (int) $minor, $currency ?: 'XAF');
    }

    /**
     * Search + page an in-memory row set (records() tables are not paginated by Filament).
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  list<string>  $searchIn
     */
    protected static function pageOf(array $rows, ?string $search, array $searchIn, int|string $page, int|string $perPage): \Illuminate\Pagination\LengthAwarePaginator
    {
        if (filled($search)) {
            $needle = mb_strtolower(trim($search));
            $rows = array_filter($rows, fn (array $r) => collect($searchIn)->contains(fn ($k) => str_contains(mb_strtolower((string) ($r[$k] ?? '')), $needle)));
        }
        $per = $perPage === 'all' ? max(1, count($rows)) : max(1, (int) $perPage);
        $page = max(1, (int) $page);

        return new \Illuminate\Pagination\LengthAwarePaginator(array_slice($rows, ($page - 1) * $per, $per, true), count($rows), $per, $page);
    }

    /** @return list<array{label:string, value:mixed, tone:?string, hint:?string}> */
    public function kpis(): array
    {
        return [];
    }

    /** Optional HTML-free extra block: a view name + data rendered between the KPIs and the table. */
    public function extraView(): ?array
    {
        return null;
    }

    public function showTable(): bool
    {
        return true;
    }
}
