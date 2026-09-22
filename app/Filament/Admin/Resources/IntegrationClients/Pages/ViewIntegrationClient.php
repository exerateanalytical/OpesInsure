<?php
namespace App\Filament\Admin\Resources\IntegrationClients\Pages;

use App\Application\Integrations\IntegrationClientLifecycleService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\IntegrationClients\IntegrationClientResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\{RepeatableEntry,TextEntry};
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ViewIntegrationClient extends ViewRecord
{
    protected static string $resource = IntegrationClientResource::class;

    protected function getHeaderActions(): array
    {
        $service = fn () => app(IntegrationClientLifecycleService::class);

        return [
            Action::make('advance')
                ->label('Advance to next stage')
                ->icon('heroicon-o-arrow-right-circle')
                ->visible(fn () => array_key_exists($this->record->status, ['DRAFT' => 1, 'TECHNICAL_REVIEW' => 1, 'SANDBOX_ENABLED' => 1, 'CERTIFICATION' => 1, 'PRODUCTION_APPROVED' => 1]))
                ->schema([Textarea::make('notes')->label('Notes')])
                ->action(function (array $data) use ($service) {
                    if (ServiceValidation::run(fn () => $service()->advance($this->record, 'ADVANCED_VIA_ADMIN', $data['notes'] ?? null, auth()->user())) === null) {
                        return;
                    }
                    Notification::make()->title('Connection advanced')->success()->send();
                }),
            Action::make('suspend')
                ->label('Suspend')
                ->color('warning')
                ->icon('heroicon-o-pause-circle')
                ->visible(fn () => $this->record->status !== 'REVOKED')
                ->requiresConfirmation()
                ->schema([Textarea::make('notes')->required()->minLength(10)])
                ->action(function (array $data) use ($service) {
                    if (ServiceValidation::run(fn () => $service()->suspend($this->record, 'SUSPENDED_VIA_ADMIN', $data['notes'], auth()->user())) === null) {
                        return;
                    }
                    Notification::make()->title('Connection suspended')->warning()->send();
                }),
            Action::make('reinstate')
                ->label('Reinstate')
                ->icon('heroicon-o-play-circle')
                ->visible(fn () => in_array($this->record->status, ['SUSPENDED', 'RESTRICTED'], true))
                ->schema([Textarea::make('notes')->required()->minLength(10)])
                ->action(function (array $data) use ($service) {
                    if (ServiceValidation::run(fn () => $service()->reinstate($this->record, $data['notes'], auth()->user())) === null) {
                        return;
                    }
                    Notification::make()->title('Connection reinstated')->success()->send();
                }),
            Action::make('revoke')
                ->label('Revoke permanently')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->visible(fn () => $this->record->status !== 'REVOKED')
                ->requiresConfirmation()
                ->schema([Textarea::make('notes')->required()->minLength(10)])
                ->action(function (array $data) use ($service) {
                    if (ServiceValidation::run(fn () => $service()->revoke($this->record, 'REVOKED_VIA_ADMIN', $data['notes'], auth()->user())) === null) {
                        return;
                    }
                    Notification::make()->title('Connection revoked')->danger()->send();
                }),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Connection')->columnSpanFull()->columns(2)->schema([
                TextEntry::make('name'),
                TextEntry::make('status')->badge(),
                TextEntry::make('environment')->badge(),
                TextEntry::make('partner.party.display_name')->label('Partner')->placeholder('Platform-wide'),
                TextEntry::make('scopes')->badge()->separator(','),
                TextEntry::make('rate_limit_per_minute')->label('Rate limit')->suffix(' req/min'),
                TextEntry::make('activated_at')->dateTime()->placeholder('—'),
                TextEntry::make('certified_at')->dateTime()->placeholder('—'),
                TextEntry::make('last_used_at')->dateTime()->placeholder('Never'),
            ]),
            Section::make('Webhook subscriptions')->columnSpanFull()->schema([
                RepeatableEntry::make('webhookSubscriptions')->hiddenLabel()
                    ->table([
                        TableColumn::make('Event'), TableColumn::make('Status'), TableColumn::make('Circuit'), TableColumn::make('Consecutive failures'),
                    ])
                    ->schema([
                        TextEntry::make('event_name'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('circuit_state')->badge()->color(fn (string $state) => match ($state) {
                            'OPEN' => 'danger', 'HALF_OPEN' => 'warning', default => 'success',
                        }),
                        TextEntry::make('consecutive_failures'),
                    ])
                    ->placeholder('No webhook subscriptions.'),
            ]),
        ]);
    }
}
