<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CaseRecords;

use App\Application\Cases\Models\WorkCase;
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

/** REQ-CAS-001 / REQ-CAL-001 - Cases (navigation group "Cases & tasks"). */
final class CaseRecordResource extends Resource
{
    use CasesAccess;

    protected static ?string $model = WorkCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Cases';

    protected static ?int $navigationSort = 601;

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

        return $query->where('tenant_id', $tenant);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('case_number')->disabled(),
            Forms\Components\TextInput::make('case_type_code')->disabled(),
            Forms\Components\TextInput::make('title')->disabled(),
            Forms\Components\TextInput::make('status')->disabled(),
            Forms\Components\TextInput::make('priority')->disabled(),
            Forms\Components\TextInput::make('confidentiality')->disabled(),
            Forms\Components\TextInput::make('owner_user_id')->disabled(),
            Forms\Components\TextInput::make('queue_id')->disabled(),
            Forms\Components\TextInput::make('subject_type')->disabled(),
            Forms\Components\TextInput::make('subject_id')->disabled(),
            Forms\Components\TextInput::make('opened_at')->disabled(),
            Forms\Components\TextInput::make('due_at')->disabled(),
            Forms\Components\TextInput::make('closed_at')->disabled(),
            Forms\Components\TextInput::make('outcome')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('case_number')->searchable()->copyable(),
            Tables\Columns\TextColumn::make('case_type_code')->label('Type')->badge(),
            Tables\Columns\TextColumn::make('title')->searchable()->limit(50),
            Tables\Columns\TextColumn::make('status')->badge(),
            Tables\Columns\TextColumn::make('priority')->badge(),
            Tables\Columns\TextColumn::make('confidentiality')->badge()->toggleable(),
            Tables\Columns\TextColumn::make('due_at')->dateTime()->sortable(),
            Tables\Columns\TextColumn::make('opened_at')->dateTime()->sortable()->toggleable(),
        ])->recordActions([Actions\ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCaseRecords::route('/'), 'view' => Pages\ViewCaseRecord::route('/{record}')];
    }
}
