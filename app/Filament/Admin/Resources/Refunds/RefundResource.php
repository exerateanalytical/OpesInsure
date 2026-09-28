<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Refunds;

use App\Application\Finance\Refunds\RefundEngine;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Columns;
use App\Models\Refund;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** REQ-PAY-009 / WF-063 refund queue (GET /api/v1/refunds); workflow actions on the detail page (RefundActions). */
final class RefundResource extends \App\Filament\Shared\LocalizedResource
{
    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-rotate-ccw';

    protected static string|\UnitEnum|null $navigationGroup = 'Financial operations';

    protected static ?int $navigationSort = 74;

    protected static ?string $navigationLabel = 'Refunds';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasPermission('refund.view');
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
            Tables\Columns\TextColumn::make('refund_number')->label(__('workflow_actions.fields.refund_number'))->searchable(),
            Columns::text('source_type', __('workflow_actions.fields.source_type')),
            Columns::text('reason_code', __('workflow_actions.fields.reason_code'))->searchable(),
            Columns::money('amount_minor', 'currency', __('workflow_actions.fields.amount_minor')),
            Columns::status('status', __('workflow_actions.fields.status')),
            Columns::date('created_at'),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(collect(RefundEngine::STATUSES)->mapWithKeys(fn ($s) => [$s => Columns::humanise($s)])->all()),
        ])->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRefunds::route('/'), 'view' => Pages\ViewRefund::route('/{record}')];
    }
}
