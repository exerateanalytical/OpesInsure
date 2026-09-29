<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\RegulatoryCrm;

use App\Filament\Shared\Actions\RegulatoryReturnActions;
use App\Models\RegulatoryReportDefinition;
use BackedEnum;
use Filament\Tables\Table;

/** REQ-RPT-001 return definitions — GET regulatory/return-definitions (regulatory.returns.view); define / approve / generate. */
final class RegulatoryReturnDefinitions extends RegulatoryCrmPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-spreadsheet';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'regulatory/return-definitions';

    protected static array $permissions = ['regulatory.returns.view'];

    protected static string $screen = 'return_definitions';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->ready() ? self::keyed(RegulatoryReportDefinition::orderBy('code')->orderByDesc('version')->get()) : [])
            ->columns([
                self::col('code'), self::col('version'), self::col('jurisdiction'), self::col('report_type'),
                self::col('status')->badge(), self::col('effective_from')->date(), self::col('effective_until')->date(),
            ])
            ->headerActions([RegulatoryReturnActions::returnDefine()])
            ->recordActions([RegulatoryReturnActions::returnApprove(), RegulatoryReturnActions::returnGenerate()])
            ->emptyStateHeading(__('regulatory_crm_actions.empty'));
    }
}
