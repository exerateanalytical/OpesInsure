<?php
namespace App\Filament\Admin\Resources\IntegrationDeliveryAttempts;

use App\Application\Integrations\WebhookDeliveryService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\IntegrationDeliveryAttempts\Pages;
use App\Models\IntegrationDeliveryAttempt;
use BackedEnum;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * The operator-facing dead-letter queue the plan asks for (§16): "dead-letter
 * count," "records awaiting..." — every row here is a real delivery attempt
 * this batch's worker actually made, not a mock-up. Purely a monitoring +
 * controlled-replay surface — attempts are created only by
 * WebhookDeliveryService, never through this Resource's own form.
 */
final class IntegrationDeliveryAttemptResource extends Resource
{
    protected static ?string $model = IntegrationDeliveryAttempt::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;
    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';
    protected static ?string $navigationLabel = 'Delivery attempts';
    protected static ?int $navigationSort = 91;
    protected static ?string $modelLabel = 'delivery attempt';
    protected static ?string $pluralModelLabel = 'delivery attempts';

    public static function form(Schema $s): Schema
    {
        return $s->components([]);
    }

    public static function table(Table $t): Table
    {
        return $t->columns([
            Tables\Columns\TextColumn::make('subscription.client.name')->label('Connection')->searchable(),
            Tables\Columns\TextColumn::make('subscription.event_name')->label('Event')->badge(),
            Tables\Columns\TextColumn::make('attempt')->alignEnd(),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                'DELIVERED' => 'success', 'DEAD_LETTERED' => 'danger', default => 'warning',
            }),
            Tables\Columns\TextColumn::make('response_status')->label('HTTP')->placeholder('—'),
            Tables\Columns\TextColumn::make('duration_ms')->label('Latency')->suffix(' ms')->placeholder('—'),
            Tables\Columns\TextColumn::make('failure_reason')->limit(60)->tooltip(fn ($record) => $record->failure_reason)->placeholder('—'),
            Tables\Columns\TextColumn::make('is_manual_replay')->label('Replay')->badge()->formatStateUsing(fn (bool $state) => $state ? 'Manual' : 'Automatic')->color(fn (bool $state) => $state ? 'info' : 'gray'),
            Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->since(),
        ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['DELIVERED' => 'Delivered', 'RETRY_SCHEDULED' => 'Retry scheduled', 'DEAD_LETTERED' => 'Dead-lettered']),
            ])
            ->recordActions([
                Actions\Action::make('replay')
                    ->label('Replay')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (IntegrationDeliveryAttempt $record) => $record->status === 'DEAD_LETTERED' && $record->attempt === IntegrationDeliveryAttempt::where('integration_webhook_subscription_id', $record->integration_webhook_subscription_id)->where('event_id', $record->event_id)->max('attempt'))
                    ->requiresConfirmation()
                    ->action(function (IntegrationDeliveryAttempt $record) {
                        $message = DB::table('outbox_messages')->find($record->event_id);

                        if (! $message) {
                            Notification::make()->title('The original event no longer exists.')->danger()->send();

                            return;
                        }

                        $result = ServiceValidation::run(fn () => app(WebhookDeliveryService::class)->replay($record, json_decode($message->payload, true), auth()->user()));

                        if ($result === null) {
                            return;
                        }

                        Notification::make()->title('Replay '.($result->status === 'DELIVERED' ? 'succeeded' : 'did not succeed — '.$result->status))->color($result->status === 'DELIVERED' ? 'success' : 'danger')->send();
                    }),
            ])
            ->emptyStateHeading('No delivery attempts yet')
            ->emptyStateIcon(Heroicon::OutlinedExclamationTriangle);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListIntegrationDeliveryAttempts::route('/')];
    }
}
