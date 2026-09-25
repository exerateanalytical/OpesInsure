<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CaseTasks;

use App\Application\Cases\Models\CaseTask;
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

/** REQ-CAS-001 / REQ-CAL-001 - Tasks (navigation group "Cases & tasks"). */
final class CaseTaskResource extends Resource
{
    use CasesAccess;

    protected static ?string $model = CaseTask::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?string $navigationLabel = 'Tasks';

    protected static ?int $navigationSort = 602;

    protected static function casesPermission(): string
    {
        return 'cases.view';
    }

    protected static function casesWritePermission(): ?string
    {
        return null;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);

        return $query->whereIn('case_id', \App\Application\Cases\Models\WorkCase::query()->where('tenant_id', $tenant)->select('id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('title')->disabled(),
            Forms\Components\TextInput::make('status')->disabled(),
            Forms\Components\TextInput::make('template_code')->disabled(),
            Forms\Components\TextInput::make('assignee_user_id')->disabled(),
            Forms\Components\TextInput::make('due_at')->disabled(),
            Forms\Components\TextInput::make('completed_at')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('title')->searchable()->limit(60),
            Tables\Columns\TextColumn::make('status')->badge(),
            Tables\Columns\TextColumn::make('assignee_user_id')->label('Assignee')->toggleable(),
            Tables\Columns\TextColumn::make('due_at')->dateTime()->sortable(),
        ])->recordActions([Actions\ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCaseTasks::route('/'), 'view' => Pages\ViewCaseTask::route('/{record}')];
    }
}
