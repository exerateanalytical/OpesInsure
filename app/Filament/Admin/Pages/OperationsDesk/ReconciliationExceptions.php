<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\OperationsDesk;

use App\Application\Reconciliation\ManualMatchService;
use App\Filament\Shared\Actions\ReconciliationActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * REQ-PAY-007 unmatched-items workspace — GET reconciliation/workspace/items (reconciliation.read) via
 * ManualMatchService::search: open exception lines, resolved (ignore / adjustment) or manually matched under maker-checker.
 */
final class ReconciliationExceptions extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-circle-alert';

    protected static ?int $navigationSort = 63;

    protected static ?string $slug = 'reconciliation/exceptions';

    protected static array $permissions = ['reconciliation.read'];

    protected static string $screen = 'reconciliation_exceptions';

    protected static string $group = 'Financial operations';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if ($this->tenantId === null) {
                    return [];
                }
                $items = app(ManualMatchService::class)->search($this->tenantId, ['per_page' => 100])->items();
                $pending = ReconciliationActions::pendingMatches(array_map(fn ($i) => (string) $i->id, $items));

                return self::keyed(array_map(fn ($i) => ['id' => $i->id, 'external_reference' => $i->external_reference, 'transaction_at' => $i->transaction_at,
                    'gross_minor' => $i->gross_minor, 'currency' => $i->currency, 'outcome' => $i->outcome, 'exception_code' => $i->exception_code,
                    'status' => $i->status, 'pending_match_id' => $pending[$i->id] ?? null], $items));
            })
            ->columns([
                TextColumn::make('transaction_at')->label(self::col('transaction_at'))->dateTime(),
                TextColumn::make('external_reference')->label(self::col('external_reference')),
                \App\Filament\Shared\Columns::money('gross_minor', 'currency', self::col('amount')),
                TextColumn::make('currency')->label(self::col('currency')),
                TextColumn::make('outcome')->label(self::col('outcome'))->badge(),
                TextColumn::make('exception_code')->label(self::col('exception_code')),
                TextColumn::make('pending_match_id')->label(self::col('manual_match'))->formatStateUsing(fn ($state) => $state ? __('operations_actions.match_pending') : null),
            ])
            ->recordActions([ReconciliationActions::resolve(), ReconciliationActions::requestMatch(), ReconciliationActions::decideMatch()])
            ->emptyStateHeading(__('operations_actions.empty'));
    }
}
