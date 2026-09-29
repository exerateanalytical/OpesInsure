<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\OperationsDesk;

use App\Application\Claims\Adjusters\ExpertAssignmentService;
use App\Filament\Shared\Actions\AdjusterActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** REQ-CLM-009 adjuster workspace — GET adjuster/assignments (claims.experts.work) via ExpertAssignmentService::forAdjuster: own assignments only. */
final class AdjusterAssignments extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-hard-hat';

    protected static ?int $navigationSort = 45;

    protected static ?string $slug = 'adjuster/assignments';

    protected static array $permissions = ['claims.experts.work'];

    protected static string $screen = 'adjuster_assignments';

    protected static string $group = 'Claims operations';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->tenantId === null || auth()->user() === null ? []
                : self::keyed(app(ExpertAssignmentService::class)->forAdjuster($this->tenantId, auth()->user(), null)))
            ->columns([
                TextColumn::make('claim_number')->label(self::col('claim_number')),
                TextColumn::make('status')->label(self::col('status'))->badge(),
                TextColumn::make('assigned_at')->label(self::col('assigned_at'))->dateTime(),
                TextColumn::make('loss_location')->label(self::col('loss_location'))->limit(40),
                TextColumn::make('inspection_scheduled_for')->label(self::col('inspection_scheduled_for'))->dateTime(),
                TextColumn::make('instructions')->label(self::col('instructions'))->limit(60)->wrap(),
            ])
            ->recordActions([AdjusterActions::accept(), AdjusterActions::decline(), AdjusterActions::scheduleInspection(),
                AdjusterActions::recordInspection(), AdjusterActions::submitReport()])
            ->emptyStateHeading(__('operations_actions.empty'));
    }
}
