<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Ledger\Periods\AccountingPeriodQueries;
use App\Application\Ledger\Periods\AccountingPeriodService;
use App\Filament\Shared\Actions\WorkflowAction;
use App\Filament\Shared\Columns;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * FIN-023 Financial period closing — the screen of routes/ledger_periods.php, same permissions and service:
 *   start close / close          ledger.periods.close   AccountingPeriodService::startClose / ::close (pre-close checklist, earlier periods first)
 *   request / approve / reject   ledger.periods.reopen  AccountingPeriodService::requestReopen / ::approveReopen / ::rejectReopen
 * Reopening is maker-checker: the approve / reject actions are hidden for the requester and refused by the service.
 */
final class PeriodClosing extends AdminScreenPage
{
    public const CLOSE = 'ledger.periods.close';

    public const REOPEN = 'ledger.periods.reopen';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-calendar-check';

    protected static ?int $navigationSort = 62;

    protected static ?string $slug = 'finance/period-closing';

    protected static array $permissions = ['ledger.read', self::CLOSE, self::REOPEN];

    protected static string $screen = 'period_closing';

    protected static string $group = 'Financial operations';

    /** @return array<string, array<string, mixed>> */
    public function rows(): array
    {
        if ($this->tenantId === null) {
            return [];
        }
        $rows = [];
        foreach (app(AccountingPeriodQueries::class)->list($this->tenantId) as $p) {
            $check = is_string($p->checklist) ? json_decode($p->checklist, true) : null;
            $rows[$p->id] = [
                '__key' => $p->id, 'id' => $p->id, 'period' => sprintf('%d-%02d', $p->fiscal_year, $p->period_number), 'starts_on' => $p->starts_on, 'ends_on' => $p->ends_on,
                'status' => $p->status, 'reopen_status' => $p->reopen_status, 'reopen_requested_by' => $p->reopen_requested_by, 'reopen_reason' => $p->reopen_reason,
                'blocking' => $check === null ? '—' : (implode(', ', (array) ($check['blocking'] ?? [])) ?: __('admin_screens.clear')),
                'closed_at' => $p->closed_at,
            ];
        }

        return $rows;
    }

    public function kpis(): array
    {
        $rows = collect($this->rows());
        $current = $rows->first(fn ($r) => $r['starts_on'] <= now()->toDateString() && $r['ends_on'] >= now()->toDateString());
        $lastClosed = $rows->firstWhere('status', 'CLOSED');

        return [
            self::kpi('current_period', $current ? $current['period'].' · '.Columns::humanise($current['status']) : '—'),
            self::kpi('last_closed', $lastClosed['period'] ?? '—'),
            self::kpi('open_past_periods', $rows->filter(fn ($r) => $r['ends_on'] < now()->toDateString() && $r['status'] !== 'CLOSED')->count(), 'warning'),
            self::kpi('pending_reopen', $rows->where('reopen_status', 'PENDING')->count(), 'warning'),
        ];
    }

    private function call(Action $action, string $permission, callable $fn, string $done): mixed
    {
        return WorkflowAction::run($action, $permission, $fn, __('admin_screens.'.$done.'.done'));
    }

    private function owned(array $record): string
    {
        return app(AccountingPeriodQueries::class)->ownedOrFail((string) $this->tenantId, $record['id'])->id;
    }

    public function table(Table $table): Table
    {
        $svc = fn (): AccountingPeriodService => app(AccountingPeriodService::class);

        return $table
            ->records(fn () => $this->rows())
            ->columns([
                TextColumn::make('period')->label(self::col('period'))->weight('bold'),
                Columns::date('starts_on', false, self::col('from')),
                Columns::date('ends_on', false, self::col('to')),
                Columns::status('status', self::col('status')),
                TextColumn::make('blocking')->label(self::col('checklist'))->wrap(),
                TextColumn::make('reopen_status')->label(self::col('reopen'))->badge()->placeholder('—')->formatStateUsing(fn ($state) => Columns::humanise($state)),
                Columns::date('closed_at', true, self::col('closed_at'))->placeholder('—'),
            ])
            ->recordActions([
                Action::make('periodChecklist')->label(__('admin_screens.periodChecklist.label'))->icon('lucide-list-checks')->color('gray')
                    ->modalHeading(fn (array $record) => $record['period'])->modalSubmitAction(false)
                    ->modalContent(fn (array $record) => view('filament.admin.pages.partials.period-checklist', ['check' => $svc()->checklist($this->owned($record))])),
                WorkflowAction::make('periodStartClose', self::CLOSE, 'admin_screens')->icon('lucide-lock')->requiresConfirmation()
                    ->visible(fn (array $record) => in_array($record['status'], ['OPEN', 'REOPENED'], true))
                    ->action(fn (Action $action, array $record) => $this->call($action, self::CLOSE, fn () => $svc()->startClose($this->owned($record), auth()->user()), 'periodStartClose')),
                WorkflowAction::make('periodClose', self::CLOSE, 'admin_screens')->icon('lucide-lock-keyhole')->color('danger')->requiresConfirmation()
                    ->visible(fn (array $record) => $record['status'] === 'CLOSING')
                    ->action(fn (Action $action, array $record) => $this->call($action, self::CLOSE, fn () => $svc()->close($this->owned($record), auth()->user()), 'periodClose')),
                WorkflowAction::make('periodReopenRequest', self::REOPEN, 'admin_screens')->icon('lucide-lock-open')
                    ->visible(fn (array $record) => $record['status'] === 'CLOSED' && $record['reopen_status'] !== 'PENDING')
                    ->schema([Textarea::make('reason')->label(self::col('reason'))->required()->minLength(10)->maxLength(2000)])
                    ->action(fn (Action $action, array $record, array $data) => $this->call($action, self::REOPEN, fn () => $svc()->requestReopen($this->owned($record), $data['reason'], auth()->user()), 'periodReopenRequest')),
                WorkflowAction::make('periodReopenApprove', self::REOPEN, 'admin_screens')->icon('lucide-check')->color('success')->requiresConfirmation()
                    ->visible(fn (array $record) => $record['reopen_status'] === 'PENDING' && $record['reopen_requested_by'] !== auth()->id())
                    ->modalDescription(fn (array $record) => $record['reopen_reason'])
                    ->action(fn (Action $action, array $record) => $this->call($action, self::REOPEN, fn () => $svc()->approveReopen($this->owned($record), auth()->user()), 'periodReopenApprove')),
                WorkflowAction::make('periodReopenReject', self::REOPEN, 'admin_screens')->icon('lucide-x')->color('danger')
                    ->visible(fn (array $record) => $record['reopen_status'] === 'PENDING' && $record['reopen_requested_by'] !== auth()->id())
                    ->schema([Textarea::make('reason')->label(self::col('reason'))->required()->maxLength(2000)])
                    ->action(fn (Action $action, array $record, array $data) => $this->call($action, self::REOPEN, fn () => $svc()->rejectReopen($this->owned($record), auth()->user(), $data['reason']), 'periodReopenReject')),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
