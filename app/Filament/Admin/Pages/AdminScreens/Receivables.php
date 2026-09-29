<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Finance\Obligations\ObligationService;
use App\Filament\Shared\Columns;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * FIN-003 Receivables — read model over the finance-wide obligations API: GET finance/obligations?kind=RECEIVABLE and
 * GET finance/obligations/aging?kind=RECEIVABLE (finance.obligations.view, tenant-scoped, ObligationService::aging /
 * ::bucket). Write-off and cancellation stay on the finance operations desk (maker-checker there).
 */
final class Receivables extends AdminScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-hand-coins';

    protected static ?int $navigationSort = 61;

    protected static ?string $slug = 'finance/receivables';

    protected static array $permissions = ['finance.obligations.view'];

    protected static string $screen = 'receivables';

    protected static string $group = 'Financial operations';

    public function kpis(): array
    {
        if ($this->tenantId === null) {
            return [];
        }
        $aging = app(ObligationService::class)->aging($this->tenantId, 'RECEIVABLE');
        $sum = fn (array $buckets) => collect($aging)->map(fn ($b, $ccy) => self::money(array_sum(array_intersect_key($b, array_flip($buckets))), $ccy))->implode(' · ') ?: self::money(0);

        return [
            self::kpi('outstanding', $sum(ObligationService::BUCKETS)),
            self::kpi('current', $sum(['CURRENT']), 'success'),
            self::kpi('overdue_1_60', $sum(['1_30', '31_60']), 'warning'),
            self::kpi('overdue_60_plus', $sum(['61_90', '90_PLUS']), 'danger'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters, int|string $page, int|string $recordsPerPage) {
                $status = $filters['status']['value'] ?? 'OPEN_ANY';
                $q = DB::table('financial_obligations')->where('tenant_id', $this->tenantId ?? '00000000-0000-0000-0000-000000000000')->where('kind', 'RECEIVABLE')
                    ->when($status === 'OPEN_ANY', fn ($q) => $q->whereIn('status', ObligationService::OPEN_STATUSES))
                    ->when($status === 'OVERDUE', fn ($q) => $q->whereIn('status', ObligationService::OPEN_STATUSES)->where('due_at', '<', now()))
                    ->when(! in_array($status, ['OPEN_ANY', 'OVERDUE', ''], true), fn ($q) => $q->where('status', $status))
                    ->orderBy('due_at');
                $per = $recordsPerPage === 'all' ? 500 : (int) $recordsPerPage;
                $p = $q->paginate(max(1, $per), ['*'], 'page', max(1, (int) $page));
                $p->setCollection($p->getCollection()->mapWithKeys(fn ($o) => [$o->id => [
                    '__key' => $o->id, 'id' => $o->id, 'reference' => $o->source_reference ?: ($o->description ?: '—'), 'type' => $o->type,
                    'debtor' => Columns::humanise($o->debtor_type), 'amount' => (int) $o->amount_minor, 'outstanding' => (int) $o->outstanding_minor, 'currency' => $o->currency,
                    'due_at' => $o->due_at, 'status' => $o->status,
                    'bucket' => in_array($o->status, ObligationService::OPEN_STATUSES, true) ? ObligationService::bucket($o->due_at) : null,
                ]]));

                return $p;
            })
            ->columns([
                TextColumn::make('reference')->label(self::col('reference'))->wrap(),
                TextColumn::make('type')->label(self::col('type'))->formatStateUsing(fn ($state) => Columns::humanise($state)),
                TextColumn::make('debtor')->label(self::col('debtor')),
                Columns::money('amount', 'currency', self::col('amount')),
                Columns::money('outstanding', 'currency', self::col('outstanding')),
                Columns::date('due_at', false, self::col('due')),
                TextColumn::make('bucket')->label(self::col('aging_bucket'))->badge()->placeholder('—')
                    ->formatStateUsing(fn ($state) => __('admin_screens.buckets.'.$state))
                    ->color(fn ($state): string => match ($state) { 'CURRENT' => 'success', '1_30', '31_60' => 'warning', default => 'danger' }),
                Columns::status('status', self::col('status')),
            ])
            ->filters([
                SelectFilter::make('status')->label(self::col('status'))->default('OPEN_ANY')->options([
                    'OPEN_ANY' => __('admin_screens.filters.open'), 'OVERDUE' => __('admin_screens.filters.overdue'),
                    'SETTLED' => Columns::humanise('SETTLED'), 'WRITTEN_OFF' => Columns::humanise('WRITTEN_OFF'), 'CANCELLED' => Columns::humanise('CANCELLED'),
                ]),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
