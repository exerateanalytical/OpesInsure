<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataMergeRequests;

use App\Application\Approvals\ApprovalService;
use App\Application\MasterData\MasterDataMergeService;
use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\ApprovalRequest;
use App\Models\MasterData\MasterDataMergeRequest;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * MDM-011 Merge records — REQ-MDM-007 maker-checker queue (approval action entity.merge). Requests are raised from
 * Values ("Request merge into…", "Possible duplicates" filter = MDM-010); a different admin approves or rejects here.
 */
final class MasterDataMergeRequestResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = MasterDataMergeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Merge requests';

    protected static ?string $modelLabel = 'merge request';

    protected static ?int $navigationSort = 407;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['from', 'into']))->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            Tables\Columns\TextColumn::make('list')->state(fn ($record) => "{$record->domain_code}.{$record->list_code}"),
            Tables\Columns\TextColumn::make('from.label_en')->label('Retire')->description(fn ($record) => $record->from?->code),
            Tables\Columns\TextColumn::make('into.label_en')->label('Keep')->description(fn ($record) => $record->into?->code),
            Tables\Columns\TextColumn::make('reason')->wrap()->placeholder('—'),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) { 'MERGED' => 'success', 'REJECTED' => 'danger', 'PENDING' => 'warning', default => 'gray' }),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(['PENDING' => 'Pending', 'MERGED' => 'Merged', 'REJECTED' => 'Rejected'])->default('PENDING'),
        ])->recordActions([
            Actions\Action::make('approve')->label('Approve merge')->color('success')->icon(Heroicon::OutlinedCheck)->requiresConfirmation()
                ->visible(fn ($record) => self::canDecide($record))
                ->schema([Forms\Components\Textarea::make('note')->maxLength(500)])
                ->action(fn ($record, array $data) => ServiceValidation::run(fn () => app(MasterDataMergeService::class)->approveRequest($record, auth()->user(), $data['note'] ?? null))),
            Actions\Action::make('reject')->label('Reject')->color('danger')->icon(Heroicon::OutlinedXMark)
                ->visible(fn ($record) => self::canDecide($record))
                ->schema([Forms\Components\Textarea::make('note')->required()->minLength(3)->maxLength(500)])
                ->action(fn ($record, array $data) => ServiceValidation::run(fn () => app(MasterDataMergeService::class)->rejectRequest($record, auth()->user(), $data['note']))),
        ]);
    }

    private static function canDecide(MasterDataMergeRequest $mr): bool
    {
        $req = $mr->status === 'PENDING' && $mr->approval_request_id ? ApprovalRequest::find($mr->approval_request_id) : null;

        return $req !== null && auth()->user() !== null && app(ApprovalService::class)->canDecide($req, auth()->user());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterDataMergeRequests::route('/'),
        ];
    }
}
