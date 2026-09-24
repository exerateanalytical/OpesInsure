<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleMasterReviews;

use App\Application\Vehicles\VehicleMasterReviewService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Concerns\VehicleMasterAccess;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleMasterReview;
use App\Models\Vehicles\VehicleModel;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Other / Not listed" vehicles typed in by customers
 * (MASTER_DATA_REVIEW_REQUIRED). Approve as a new make/model or merge into an
 * existing one; reviewer and time are recorded.
 */
final class VehicleMasterReviewResource extends Resource
{
    use VehicleMasterAccess;

    protected static ?string $model = VehicleMasterReview::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Review queue';

    protected static ?string $modelLabel = 'vehicle review entry';

    protected static ?int $navigationSort = 303;

    public static function getNavigationBadge(): ?string
    {
        $n = VehicleMasterReview::where('status', VehicleMasterReview::STATUS_PENDING)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        $pending = fn (VehicleMasterReview $r) => $r->status === VehicleMasterReview::STATUS_PENDING;

        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['resolvedMake', 'resolvedModel', 'reviewer', 'submitter']))
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Submitted')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('make_text')->label('Make (typed)')->searchable(),
                Tables\Columns\TextColumn::make('model_text')->label('Model (typed)')->searchable(),
                Tables\Columns\TextColumn::make('model_year')->label('Year'),
                Tables\Columns\TextColumn::make('body_type')->toggleable(),
                Tables\Columns\TextColumn::make('vin')->label('VIN')->toggleable(),
                Tables\Columns\TextColumn::make('registration_number')->label('Registration')->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    VehicleMasterReview::STATUS_PENDING => 'warning', 'REJECTED' => 'danger', default => 'success'
                }),
                Tables\Columns\TextColumn::make('resolved')->label('Resolved to')->state(fn (VehicleMasterReview $r) => trim(($r->resolvedMake?->name ?? '').' '.($r->resolvedModel?->name ?? '')))->placeholder('-'),
                Tables\Columns\TextColumn::make('reviewer.full_name')->label('Reviewed by')->placeholder('-'),
                Tables\Columns\TextColumn::make('reviewed_at')->dateTime()->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->default(VehicleMasterReview::STATUS_PENDING)->options([
                    VehicleMasterReview::STATUS_PENDING => 'Review required', 'APPROVED_NEW' => 'Approved as new', 'MERGED' => 'Merged', 'REJECTED' => 'Rejected',
                ]),
            ])
            ->recordActions([
                Actions\Action::make('approveNew')->label('Approve as new')->icon(Heroicon::OutlinedCheck)->color('success')->visible($pending)
                    ->modalDescription('Creates the make (if it does not exist) and model with provenance CUSTOMER_SUBMITTED / status UNVERIFIED.')
                    ->schema([
                        Forms\Components\Select::make('segment')->default('PASSENGER')->options(['PASSENGER' => 'Passenger', 'COMMERCIAL' => 'Commercial', 'MIXED' => 'Mixed'])->helperText('Only used when the make is new.'),
                        Forms\Components\TextInput::make('country_of_origin')->label('Country of origin (ISO-2, new make only)')->length(2),
                        Forms\Components\Textarea::make('notes'),
                    ])
                    ->action(function (VehicleMasterReview $r, array $data) {
                        if (ServiceValidation::run(fn () => app(VehicleMasterReviewService::class)->approveAsNew($r, auth()->user(), array_filter($data), $data['notes'] ?? null))) {
                            Notification::make()->title('Added to the vehicle master')->success()->send();
                        }
                    }),
                Actions\Action::make('merge')->label('Merge into existing')->icon(Heroicon::OutlinedArrowsRightLeft)->visible($pending)
                    ->fillForm(fn (VehicleMasterReview $r) => ['make_id' => $r->resolved_make_id])
                    ->schema([
                        Forms\Components\Select::make('make_id')->label('Make')->required()->searchable()->live()
                            ->options(fn () => VehicleMake::where('active', true)->orderBy('name')->pluck('name', 'id')->all()),
                        Forms\Components\Select::make('model_id')->label('Model')->searchable()
                            ->options(fn (Get $get) => $get('make_id') ? VehicleModel::where('make_id', $get('make_id'))->where('active', true)->orderBy('name')->pluck('name', 'id')->all() : []),
                        Forms\Components\Textarea::make('notes'),
                    ])
                    ->modalDescription('The typed make/model become aliases of the chosen entries.')
                    ->action(function (VehicleMasterReview $r, array $data) {
                        if (ServiceValidation::run(fn () => app(VehicleMasterReviewService::class)->merge($r, auth()->user(), $data['make_id'], $data['model_id'] ?? null, $data['notes'] ?? null))) {
                            Notification::make()->title('Merged')->success()->send();
                        }
                    }),
                Actions\Action::make('reject')->label('Reject')->color('danger')->visible($pending)
                    ->schema([Forms\Components\Textarea::make('notes')->label('Reason')->required()->minLength(5)])
                    ->action(fn (VehicleMasterReview $r, array $data) => ServiceValidation::run(fn () => app(VehicleMasterReviewService::class)->reject($r, auth()->user(), $data['notes']))),
            ])
            ->emptyStateHeading('Nothing to review')
            ->emptyStateDescription('Vehicles customers add with "Can\'t find your vehicle?" appear here.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVehicleMasterReviews::route('/')];
    }
}
