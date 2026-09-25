<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InsuranceProducts;

use App\Application\Catalogue\Governance\Models\ProductTestCase;
use App\Application\Catalogue\Governance\Models\ProductTestRun;
use App\Application\Catalogue\Governance\ProductCompletenessService;
use App\Application\Catalogue\Governance\ProductGovernanceService;
use App\Application\Catalogue\ProductHierarchyService;
use App\Application\Catalogue\Sandbox\ProductSandbox;
use App\Filament\Shared\Components\RecordShell;
use App\Models\InsuranceProduct;
use Closure;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Model;

/**
 * REQ-PRD-010 — INS-PRODUCT-VERSION product builder tabs (PRE §93–99) on the shared RecordShell
 * (header + failure banners + timeline + documents): Overview (hierarchy), Governance (PRE §74 stage,
 * history, attributes), Completeness (blocking vs warnings), Tests (pack + sandbox runs), Changes (diff).
 */
final class ProductVersionTabs
{
    /** @return array<int, \Filament\Schemas\Components\Component> */
    public static function schema(): array
    {
        return [
            RecordShell::detailHeader(),
            RecordShell::failureStates(),
            Tabs::make('product_version')->columnSpanFull()->tabs([
                Tabs\Tab::make(__('product_builder.tabs.overview'))->schema([self::view('overview', fn (InsuranceProduct $v) => ['tree' => rescue(fn () => app(ProductHierarchyService::class)->forVersion($v), [], false)])]),
                Tabs\Tab::make(__('product_builder.tabs.governance'))->schema([self::view('governance', fn (InsuranceProduct $v) => self::governance($v))]),
                Tabs\Tab::make(__('product_builder.tabs.completeness'))->schema([self::view('completeness', fn (InsuranceProduct $v) => ['report' => app(ProductCompletenessService::class)->evaluate($v)])]),
                Tabs\Tab::make(__('product_builder.tabs.tests'))->schema([self::view('tests', fn (InsuranceProduct $v) => [
                    'cases' => ProductTestCase::where('insurance_product_id', $v->id)->orderBy('code')->get(),
                    'runs' => ProductTestRun::where('insurance_product_id', $v->id)->orderByDesc('ran_at')->limit(10)->get(),
                    'latest' => app(ProductSandbox::class)->latestRun($v),
                ])]),
                Tabs\Tab::make(__('product_builder.tabs.changes'))->schema([self::view('diff', fn (InsuranceProduct $v) => ['diff' => app(ProductGovernanceService::class)->diff($v)])]),
                Tabs\Tab::make(__('web_experience.tabs.timeline'))->schema([RecordShell::timeline('insurance_product')]),
                Tabs\Tab::make(__('web_experience.tabs.documents'))->schema([RecordShell::documentViewer()]),
            ]),
        ];
    }

    /** @return array<string,mixed> */
    public static function governance(InsuranceProduct $v): array
    {
        $svc = app(ProductGovernanceService::class);
        $state = $svc->state($v);

        return ['state' => $state, 'history' => $svc->history($v), 'next' => ProductGovernanceService::NEXT[$state->stage] ?? null, 'stages' => ProductGovernanceService::STAGES];
    }

    private static function view(string $name, Closure $data): View
    {
        return View::make('filament.product-builder.'.$name)
            ->viewData(fn (?Model $record) => $record instanceof InsuranceProduct ? $data($record) : [])
            ->columnSpanFull();
    }
}
