<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LegacyMigrations;

use App\Application\Import\Legacy\LegacyMigrationPipeline;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Columns;
use App\Models\Import\ImportBatch;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** REQ-IMP-002 legacy migration batches (GET legacy-migrations, legacy_migration.manage); actions in LegacyMigrationActions. */
final class LegacyMigrationResource extends \App\Filament\Shared\LocalizedResource
{
    protected static ?string $model = ImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-database';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'legacy-migrations';

    protected static ?string $modelLabel = 'legacy migration batch';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->hasPermission('legacy_migration.manage');
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('masterdata_actions.screens.groups.migration');
    }

    public static function getNavigationLabel(): string
    {
        return __('masterdata_actions.screens.legacy');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', app(TenantContext::class)->id())->where('pipeline', LegacyMigrationPipeline::PIPELINE);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('filename')->label(__('masterdata_actions.fields.file'))->searchable(),
            Tables\Columns\TextColumn::make('target')->label(__('masterdata_actions.fields.entity'))->badge(),
            Tables\Columns\TextColumn::make('source_system')->label(__('masterdata_actions.fields.integration_client')),
            Columns::status('status', __('masterdata_actions.fields.status')),
            Tables\Columns\TextColumn::make('imported_count')->label(__('masterdata_actions.fields.imported_count'))->placeholder('—'),
            Columns::date('created_at'),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(array_combine($s = [...LegacyMigrationPipeline::OPEN, 'PENDING_APPROVAL', 'APPROVED', ...LegacyMigrationPipeline::FINAL], $s)),
        ])->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLegacyMigrations::route('/'), 'view' => Pages\ViewLegacyMigration::route('/{record}')];
    }
}
