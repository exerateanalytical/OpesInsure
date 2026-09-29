<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SecurityFindings;

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\AccountSecurityActions;
use App\Filament\Shared\Actions\WorkflowAction;
use App\Models\SecurityFinding;
use BackedEnum;
use Filament\Actions;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Security centre findings register (GET security-centre/findings, security.findings.read). Tenant-scoped like the API;
 * reporting and transitions go through SecurityFindingService (AccountSecurityActions).
 */
final class SecurityFindingResource extends \App\Filament\Shared\LocalizedResource
{
    protected static ?string $model = SecurityFinding::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-shield-alert';

    protected static string|\UnitEnum|null $navigationGroup = 'Trust & compliance';

    public static function getNavigationLabel(): string
    {
        return __('support_actions.nav.security_findings');
    }

    public static function getModelLabel(): string
    {
        return __('support_actions.nav.security_finding');
    }

    public static function getPluralModelLabel(): string
    {
        return __('support_actions.nav.security_findings');
    }

    public static function canViewAny(): bool
    {
        return WorkflowAction::allowed('security.findings.read');
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', rescue(fn () => app(TenantContext::class)->id(), null, false));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        $c = fn (string $k) => __('support_actions.columns.'.$k);

        return $table->columns([
            Tables\Columns\TextColumn::make('reference')->label($c('reference'))->searchable()->copyable(),
            Tables\Columns\TextColumn::make('title')->label($c('title'))->searchable()->limit(50),
            Tables\Columns\TextColumn::make('severity')->label($c('severity'))->badge(),
            Tables\Columns\TextColumn::make('source')->label($c('source'))->badge()->toggleable(),
            \App\Filament\Shared\Columns::status('status'),
            \App\Filament\Shared\Columns::date('due_at')->sortable(),
        ])->defaultSort('created_at', 'desc')
            ->recordActions([Actions\ViewAction::make(), AccountSecurityActions::findingTransition()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSecurityFindings::route('/'), 'view' => Pages\ViewSecurityFinding::route('/{record}')];
    }
}
