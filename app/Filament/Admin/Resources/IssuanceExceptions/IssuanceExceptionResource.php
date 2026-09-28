<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\IssuanceExceptions;

use App\Application\Policies\IssuanceQueue\IssuanceException;
use App\Application\WebExperiences\PortalScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\WorkflowAction;
use App\Filament\Shared\Columns;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** REQ-POL-004 failed / paid-not-issued issuance queue (same data and permissions as GET /issuance-exceptions). */
final class IssuanceExceptionResource extends \App\Filament\Shared\LocalizedResource
{
    protected static ?string $model = IssuanceException::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-triangle-alert';

    protected static string|\UnitEnum|null $navigationGroup = 'Policy operations';

    protected static ?int $navigationSort = 71;

    public static function getNavigationLabel(): string
    {
        return __('issuance_maker_checker.exceptions_nav');
    }

    public static function getPluralModelLabel(): string
    {
        return __('issuance_maker_checker.exceptions_nav');
    }

    public static function canViewAny(): bool
    {
        return WorkflowAction::allowed('policies.issuance_queue.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->where('tenant_id', app(TenantContext::class)->id());
        $carrier = PortalScope::carrierId();

        return $carrier ? $q->whereHas('proposal.offer', fn ($o) => $o->where('carrier_id', $carrier)) : $q;
    }

    public static function form(Schema $s): Schema
    {
        return $s->components([]);
    }

    public static function table(Table $t): Table
    {
        return $t->columns([
            TextColumn::make('proposal.proposal_number')->label(__('issuance_maker_checker.fields.proposal'))->searchable()->copyable(),
            TextColumn::make('kind')->label(__('issuance_maker_checker.fields.kind'))->badge(),
            TextColumn::make('reason_code')->label(__('issuance_maker_checker.fields.reason'))->searchable(),
            TextColumn::make('attempts')->label(__('issuance_maker_checker.fields.attempts'))->numeric(),
            Columns::status('status'),
            Columns::date('last_attempt_at'),
        ])->defaultSort('last_attempt_at', 'desc')
            ->filters([SelectFilter::make('status')->options(['OPEN' => 'OPEN', 'ESCALATED' => 'ESCALATED', 'RESOLVED' => 'RESOLVED'])])
            ->recordActions([ViewAction::make()])
            ->emptyStateIcon('lucide-circle-check');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListIssuanceExceptions::route('/'), 'view' => Pages\ViewIssuanceException::route('/{record}')];
    }
}
