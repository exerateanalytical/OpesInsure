<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use App\Application\Providers\Portal\ProviderPortalService;
use App\Application\Providers\Portal\ProviderScope;
use App\Application\Providers\Workspace\Http\ProviderWorkspaceController;
use App\Application\Providers\Workspace\ProviderWorkspaceRegister;
use BackedEnum;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Provider Portal screen "reports" (Gap-Free spec): pick one of ProviderWorkspaceRegister::REPORTS, apply the canonical
 * filters, see the rows and export exactly those rows as CSV (same controller action as
 * GET /api/v1/provider-portal/reports/{report}?format=csv — permission provider.reports.export, export audited).
 */
final class ReportsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-chart-column';

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'reports';

    protected static string $permission = 'provider.reports.view';

    protected static string $screen = 'reports';

    public string $report = 'CLAIMS_BY_STATUS';

    /** @var array<string, ?string> */
    public array $filters = ['date_from' => null, 'date_to' => null, 'facility_id' => null, 'claim_status' => null];

    public function extraView(): ?string
    {
        return 'provider-workspace.reports-filter';
    }

    /** @return list<string> */
    public function reportOptions(): array
    {
        return ProviderWorkspaceRegister::REPORTS;
    }

    public function canExport(): bool
    {
        return (bool) rescue(fn () => $this->user()->hasPermission('provider.reports.export'), false, false);
    }

    /** @return array<string, string> */
    private function activeFilters(): array
    {
        $f = array_filter(array_map(fn ($v) => $v === null ? null : trim((string) $v), $this->filters), fn ($v) => $v !== null && $v !== '');
        // Live filters have no submit step: a malformed date or facility id is ignored (as the API would refuse it), never sent to the query.
        foreach (['date_from', 'date_to'] as $k) {
            $d = isset($f[$k]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $f[$k]) : null;
            if (isset($f[$k]) && ($d === false || $d->format('Y-m-d') !== $f[$k])) {
                unset($f[$k]);
            }
        }
        if (isset($f['facility_id']) && ! \Illuminate\Support\Str::isUuid($f['facility_id'])) {
            unset($f['facility_id']);
        }

        return $f;
    }

    public function exportCsv(): ?StreamedResponse
    {
        if (! $this->canExport() || ! in_array($this->report, ProviderWorkspaceRegister::REPORTS, true)) {
            $this->state = 'PERMISSION_DENIED';

            return null;
        }
        $r = Request::create('/api/v1/provider-portal/reports/'.strtolower($this->report), 'GET', $this->activeFilters() + ['format' => 'csv']);
        $r->setUserResolver(fn () => $this->user());
        $r->attributes->set(ProviderScope::ATTRIBUTE, $this->scope());
        $csv = (string) app(ProviderWorkspaceController::class)->report($r, $this->report)->getContent();

        return response()->streamDownload(fn () => print ($csv), strtolower($this->report).'.csv', ['Content-Type' => 'text/csv']);
    }

    protected function rows(): array
    {
        if (! in_array($this->report, ProviderWorkspaceRegister::REPORTS, true)) {
            return [];
        }

        return $this->ws()->report($this->user(), $this->scope(), $this->report, $this->activeFilters());
    }
}
