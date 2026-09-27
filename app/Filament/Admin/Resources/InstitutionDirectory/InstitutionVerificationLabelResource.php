<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InstitutionDirectory;

use App\Application\Directory\InstitutionDirectoryService;
use App\Filament\Admin\Concerns\CimaRegulatoryAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\Directory\InstitutionVerificationLabel;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** EN/FR display labels of the verification statuses shown by the app and website (audited). */
final class InstitutionVerificationLabelResource extends Resource
{
    use CimaRegulatoryAccess;

    protected static ?string $model = InstitutionVerificationLabel::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-badge-check';

    protected static ?string $navigationLabel = 'Verification labels';

    protected static ?int $navigationSort = 231;

    protected static ?string $modelLabel = 'verification label';

    public static function getNavigationGroup(): ?string
    {
        return 'Institutional directory';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')->columns([
            Tables\Columns\TextColumn::make('code')->searchable()->fontFamily('mono'),
            Tables\Columns\TextColumn::make('label_en')->searchable()->label('English'),
            Tables\Columns\TextColumn::make('label_fr')->searchable()->label('French'),
            \App\Filament\Shared\Columns::date('updated_at'),
        ])->recordActions([
            Actions\Action::make('editLabel')->label('Edit labels')->icon('lucide-square-pen')
                ->visible(fn () => static::canAccessCima())
                ->fillForm(fn (InstitutionVerificationLabel $record) => ['label_en' => $record->label_en, 'label_fr' => $record->label_fr])
                ->schema([
                    Forms\Components\TextInput::make('label_en')->label('English')->required()->maxLength(120),
                    Forms\Components\TextInput::make('label_fr')->label('French')->required()->maxLength(120),
                ])
                ->action(function (InstitutionVerificationLabel $record, array $data) {
                    if (ServiceValidation::run(fn () => app(InstitutionDirectoryService::class)->updateLabel($record->code, $data['label_en'], $data['label_fr'], auth()->user()))) {
                        Notification::make()->title('Label updated')->success()->send();
                    }
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInstitutionVerificationLabels::route('/'), 'view' => Pages\ViewInstitutionVerificationLabel::route('/{record}')];
    }
}
