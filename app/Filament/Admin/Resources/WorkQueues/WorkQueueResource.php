<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkQueues;

use App\Application\Cases\Models\WorkQueue;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Concerns\CasesAccess;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** REQ-CAS-001 / REQ-CAL-001 - Queues (navigation group "Cases & tasks"). */
final class WorkQueueResource extends Resource
{
    use CasesAccess;

    protected static ?string $model = WorkQueue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Queues';

    protected static ?int $navigationSort = 604;

    protected static function casesPermission(): string
    {
        return 'cases.admin';
    }

    protected static function casesWritePermission(): ?string
    {
        return 'cases.admin';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);

        return $query->where('tenant_id', $tenant);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\Hidden::make('tenant_id')->default(fn () => app(TenantContext::class)->id()),
            Forms\Components\TextInput::make('code')->required()->maxLength(64)->disabledOn('edit'),
            Forms\Components\TextInput::make('name')->required()->maxLength(160),
            Forms\Components\TagsInput::make('case_type_codes')->suggestions(array_keys(\App\Application\Cases\CaseTypeCatalogue::SEED)),
            Forms\Components\Select::make('routing_rule')->options(['PULL' => 'Pull', 'LEAST_LOADED' => 'Least loaded', 'ROUND_ROBIN' => 'Round robin'])->default('PULL')->required(),
            Forms\Components\Toggle::make('is_default'),
            Forms\Components\Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->searchable(),
            Tables\Columns\TextColumn::make('name'),
            Tables\Columns\TextColumn::make('routing_rule')->badge(),
            Tables\Columns\IconColumn::make('is_default')->boolean(),
            Tables\Columns\IconColumn::make('active')->boolean(),
        ])->recordActions([Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWorkQueues::route('/'), 'create' => Pages\CreateWorkQueue::route('/create'), 'edit' => Pages\EditWorkQueue::route('/{record}/edit')];
    }
}
