<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataReviews;

use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Models\MasterData\MasterDataReviewItem;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** MDM-008 Pending suggestions · MDM-009 Suggestion review · MDM-010 Duplicate detection. "Other / Not listed" entries never block the transaction; approve as new, merge (text becomes an alias) or reject. */
final class MasterDataReviewResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = MasterDataReviewItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Suggestions';

    protected static ?int $navigationSort = 404;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = \App\Models\MasterData\MasterDataReviewItem::whereIn('status', \App\Models\MasterData\MasterDataReviewItem::OPEN)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $open = fn ($record) => in_array($record->status, \App\Models\MasterData\MasterDataReviewItem::OPEN, true);
        $svc = fn () => app(\App\Application\MasterData\MasterDataReviewService::class);

        return $table->defaultSort('submission_count', 'desc')->columns([
            Tables\Columns\TextColumn::make('raw_input')->label('Submitted text')->searchable()->wrap(),
            Tables\Columns\TextColumn::make('list')->label('List')->state(fn ($record) => "{$record->domain_code}.{$record->list_code}"),
            Tables\Columns\TextColumn::make('parent_code')->label('Under')->placeholder('—'),
            Tables\Columns\TextColumn::make('submission_count')->label('Count')->sortable()->badge(),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) { 'DUPLICATE_FOUND' => 'warning', 'APPROVED', 'MERGED' => 'success', 'REJECTED' => 'danger', default => 'gray' }),
            Tables\Columns\TextColumn::make('possible_duplicates')->label('Possible duplicates')->state(fn ($record) => collect($record->possible_duplicates ?? [])->map(fn ($d) => $d['label']['en'] ?? $d['code'])->join(', '))->placeholder('—')->wrap(),
            Tables\Columns\TextColumn::make('screen')->toggleable(), Tables\Columns\TextColumn::make('line_code')->label('Line')->toggleable(),
            Tables\Columns\TextColumn::make('tenant_id')->label('Tenant')->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('created_at')->dateTime()->label('First submitted'),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->multiple()->default(\App\Models\MasterData\MasterDataReviewItem::OPEN)->options(array_combine(\App\Models\MasterData\MasterDataReviewItem::STATUSES, \App\Models\MasterData\MasterDataReviewItem::STATUSES)),
            Tables\Filters\SelectFilter::make('domain_code')->label('Domain')->options(fn () => \App\Models\MasterData\MasterDataDomain::orderBy('code')->pluck('code', 'code')->all()),
        ])->recordActions([
            Actions\Action::make('startReview')->label('Start review')->visible(fn ($record) => $record->status === 'SUBMITTED')->action(fn ($record) => $record->update(['status' => 'UNDER_REVIEW'])),
            Actions\Action::make('approve')->label('Approve as new')->color('success')->icon(Heroicon::OutlinedCheck)->visible($open)
                ->fillForm(fn ($record) => ['label_en' => $record->raw_input, 'label_fr' => $record->raw_input, 'code' => \App\Application\MasterData\MasterDataNormalizer::codeFrom($record->raw_input), 'parent_code' => $record->parent_code])
                ->schema([Forms\Components\TextInput::make('code')->required()->regex('/^[A-Z0-9_]+$/'), Forms\Components\TextInput::make('label_en')->required(),
                    Forms\Components\TextInput::make('label_fr')->required(), Forms\Components\TextInput::make('parent_code'), Forms\Components\Textarea::make('note')])
                ->action(fn ($record, array $data) => \App\Filament\Admin\Concerns\ServiceValidation::run(fn () => $svc()->approve($record, $data, auth()->id()))),
            Actions\Action::make('merge')->label('Merge into existing')->icon(Heroicon::OutlinedArrowsRightLeft)->visible($open)
                ->schema(fn ($record) => [Forms\Components\Select::make('value_id')->label('Existing value')->required()->searchable()
                    ->options(\App\Models\MasterData\MasterDataValue::where('list_id', $record->list_id)->where('status', 'ACTIVE')->where('is_other', false)->orderBy('label_en')->pluck('label_en', 'id')->all()),
                    Forms\Components\Textarea::make('note')])
                ->action(fn ($record, array $data) => \App\Filament\Admin\Concerns\ServiceValidation::run(fn () => $svc()->merge($record, \App\Models\MasterData\MasterDataValue::findOrFail($data['value_id']), auth()->id(), $data['note'] ?? null))),
            Actions\Action::make('reject')->label('Reject')->color('danger')->visible($open)->schema([Forms\Components\Textarea::make('note')->label('Reason')->required()])
                ->action(fn ($record, array $data) => \App\Filament\Admin\Concerns\ServiceValidation::run(fn () => $svc()->reject($record, auth()->id(), $data['note']))),
            Actions\Action::make('archive')->label('Archive')->visible(fn ($record) => in_array($record->status, ['APPROVED', 'MERGED', 'REJECTED'], true))->action(fn ($record) => $record->update(['status' => 'ARCHIVED'])),
        ])->emptyStateHeading('No suggestions')->emptyStateDescription('Values users enter through "Other / Not listed" appear here, most frequent first.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterDataReviews::route('/'),
        ];
    }
}
