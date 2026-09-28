<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Aml;

use App\Application\Compliance\Aml\Risk\TransactionMonitoringService;
use App\Filament\Shared\Actions\AmlActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Transaction monitoring (GET aml/transaction-monitoring/rules, aml.risk.view): the active AML_TRANSACTION rules
 * (none = inactive, OQ-5.2) and an "Evaluate transaction" action (aml.monitoring.evaluate) through
 * TransactionMonitoringService::evaluate.
 */
final class AmlTransactionMonitoring extends AmlPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-activity';

    protected static ?int $navigationSort = 42;

    protected static ?string $slug = 'aml/transaction-monitoring';

    protected static string $permission = 'aml.risk.view';

    protected static string $screen = 'monitoring';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => app(TransactionMonitoringService::class)->activeRules()
                ->mapWithKeys(fn ($x) => [$x->id => ['__key' => $x->id, 'id' => $x->id, 'code' => $x->code, 'version' => $x->version, 'risk_points' => $x->risk_points,
                    'conditions' => json_encode($x->conditions), 'effective_from' => (string) $x->effective_from, 'effective_until' => $x->effective_until ? (string) $x->effective_until : null]])->all())
            ->columns([
                TextColumn::make('code')->label(self::col('rule')),
                TextColumn::make('version')->label(self::col('version')),
                TextColumn::make('risk_points')->label(self::col('risk_points')),
                TextColumn::make('conditions')->label(self::col('conditions'))->limit(80)->wrap(),
                TextColumn::make('effective_from')->label(self::col('effective_from'))->date(),
                TextColumn::make('effective_until')->label(self::col('effective_until'))->date(),
            ])
            ->headerActions([AmlActions::monitorEvaluate()])
            ->emptyStateHeading(__('aml_actions.monitoring_inactive'));
    }
}
