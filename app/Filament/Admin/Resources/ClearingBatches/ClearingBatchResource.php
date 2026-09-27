<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ClearingBatches;

use App\Application\Finance\Clearing\ClearingBatch;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Columns;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** REQ-PAY-011 mobile-money clearing batches (GET /api/v1/clearing/batches); actions in ClearingActions. */
final class ClearingBatchResource extends Resource
{
    protected static ?string $model = ClearingBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-landmark';

    protected static string|\UnitEnum|null $navigationGroup = 'Financial operations';

    protected static ?int $navigationSort = 75;

    protected static ?string $navigationLabel = 'Mobile money clearing';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasPermission('clearing.view');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', app(TenantContext::class)->id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('settlement_reference')->label(__('workflow_actions.fields.settlement_reference'))->searchable(),
            Columns::text('provider', __('workflow_actions.fields.provider'))->searchable(),
            Columns::date('settlement_date', false, __('workflow_actions.fields.settlement_date')),
            Columns::money('expected_minor', 'currency', __('workflow_actions.fields.expected_minor')),
            Columns::money('settled_minor', 'currency', __('workflow_actions.fields.settled_minor')),
            Columns::money('variance_minor', 'currency', __('workflow_actions.fields.variance_minor')),
            Columns::status('status', __('workflow_actions.fields.status')),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(collect(['OPEN', 'SETTLED', 'RECONCILED', 'VARIANCE'])->mapWithKeys(fn ($s) => [$s => Columns::humanise($s)])->all()),
        ])->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListClearingBatches::route('/'), 'view' => Pages\ViewClearingBatch::route('/{record}')];
    }
}
