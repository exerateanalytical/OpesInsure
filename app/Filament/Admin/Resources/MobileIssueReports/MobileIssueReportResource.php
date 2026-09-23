<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MobileIssueReports;

use App\Filament\Admin\Resources\MobileIssueReports\Pages;
use App\Models\MobileIssueReport;
use BackedEnum;
use Filament\Actions;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only surface for "Report a problem" submissions filed from inside
 * the mobile app (App\Interfaces\Http\Controllers\Api\V1\Runtime\MobileIssueReportController).
 * There is no create/edit form — a report only ever originates from the app.
 */
final class MobileIssueReportResource extends Resource
{
    protected static ?string $model = MobileIssueReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'App issue reports';

    protected static ?int $navigationSort = 90;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reported issue')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('route')->label('Screen'),
                    Infolists\Components\TextEntry::make('status')->badge()
                        ->color(fn (string $state) => $state === 'RESOLVED' ? 'success' : 'warning'),
                    Infolists\Components\TextEntry::make('user.full_name')->label('Reported by')->placeholder('Not signed in'),
                    Infolists\Components\TextEntry::make('created_at')->label('Reported at')->dateTime(),
                    Infolists\Components\TextEntry::make('platform')->placeholder('—'),
                    Infolists\Components\TextEntry::make('app_version')->label('App version')->placeholder('—'),
                    Infolists\Components\TextEntry::make('note')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('route')->label('Screen')->searchable(),
                Tables\Columns\TextColumn::make('note')->limit(60)->searchable(),
                Tables\Columns\TextColumn::make('user.full_name')->label('Reported by')->placeholder('Not signed in'),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state) => $state === 'RESOLVED' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('created_at')->label('Reported')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['OPEN' => 'Open', 'RESOLVED' => 'Resolved']),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\Action::make('toggleStatus')
                    ->label(fn (MobileIssueReport $record) => $record->status === 'OPEN' ? 'Mark resolved' : 'Reopen')
                    ->icon(fn (MobileIssueReport $record) => $record->status === 'OPEN' ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedArrowPath)
                    ->action(function (MobileIssueReport $record) {
                        $record->update(['status' => $record->status === 'OPEN' ? 'RESOLVED' : 'OPEN']);
                        Notification::make()->title('Report updated')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No issues reported')
            ->emptyStateDescription('Reports filed from the mobile app\'s "Report a problem" button will appear here.')
            ->emptyStateIcon(Heroicon::OutlinedExclamationTriangle);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMobileIssueReports::route('/'),
            'view' => Pages\ViewMobileIssueReport::route('/{record}'),
        ];
    }
}
