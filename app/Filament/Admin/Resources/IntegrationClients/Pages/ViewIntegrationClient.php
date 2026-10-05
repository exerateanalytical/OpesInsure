<?php
namespace App\Filament\Admin\Resources\IntegrationClients\Pages;

use App\Application\Identity\Rbac\PlatformAuthority;
use App\Application\Integrations\Developer\Portal\PartnerDeveloperPortalService;
use App\Application\Integrations\IntegrationClientLifecycleService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\IntegrationClients\IntegrationClientResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\{Select,Textarea};
use Illuminate\Support\Facades\DB;
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
            // ui:coverage STAFF_DESKTOP_NEEDED (2026-09-30): the staff side of POST developer/clients/{client}/developers and its
            // revoke — same permission (integrations.manage / integrations.revoke), platform tenant only, same service.
            Action::make('linkDeveloper')
                ->label('Link developer')
                ->icon('lucide-user-plus')
                ->visible(fn () => $this->mayManageDevelopers('integrations.manage'))
                ->schema([
                    Select::make('user_id')->label('User')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => User::query()
                            ->where(fn ($q) => $q->where('email', 'ilike', '%'.$search.'%')->orWhere('full_name', 'ilike', '%'.$search.'%'))
                            ->orderBy('email')->limit(50)->get()->mapWithKeys(fn (User $u) => [$u->id => trim($u->full_name.' <'.$u->email.'>')])->all())
                        ->getOptionLabelUsing(fn ($value) => ($u = User::find($value)) ? trim($u->full_name.' <'.$u->email.'>') : null),
                    Select::make('role')->label('Developer role')->required()->default('DEVELOPER')
                        ->options(array_combine(PartnerDeveloperPortalService::ROLES, array_map(fn ($r) => ucfirst(strtolower($r)), PartnerDeveloperPortalService::ROLES))),
                ])
                ->action(function (array $data) {
                    abort_unless($this->mayManageDevelopers('integrations.manage'), 403);
                    $user = User::findOrFail($data['user_id']);
                    if (ServiceValidation::run(fn () => app(PartnerDeveloperPortalService::class)->linkDeveloper($this->record, $user, $data['role'], auth()->user())) === null) {
                        return;
                    }
                    Notification::make()->title('Developer linked')->success()->send();
                }),
            Action::make('revokeDeveloper')
                ->label('Revoke developer')
                ->color('danger')
                ->icon('lucide-user-x')
                ->visible(fn () => $this->mayManageDevelopers('integrations.revoke') && $this->activeDevelopers() !== [])
                ->requiresConfirmation()
                ->schema([Select::make('user_id')->label('Developer')->required()->options(fn () => $this->activeDevelopers())])
                ->action(function (array $data) {
                    abort_unless($this->mayManageDevelopers('integrations.revoke'), 403);
                    if (ServiceValidation::run(fn () => app(PartnerDeveloperPortalService::class)->unlinkDeveloper($this->record, (string) $data['user_id'], auth()->user())) === null) {
                        return;
                    }
                    Notification::make()->title('Developer access revoked')->warning()->send();
                }),
            Action::make('advance')
                ->label('Advance to next stage')
                ->icon('lucide-circle-arrow-right')
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
                ->icon('lucide-circle-pause')
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
                ->icon('lucide-circle-play')
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
                ->icon('lucide-circle-x')
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

    /** Same gate as the API routes: the permission plus the platform tenant (platform.tenant middleware). */
    private function mayManageDevelopers(string $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && (bool) rescue(fn () => $user->hasPermission($permission), false, false)
            && (bool) rescue(fn () => app(PlatformAuthority::class)->isPlatformTenant(), false, false);
    }

    /** @return array<string, string> ACTIVE developer links of this client: user id => "name <email> (ROLE)" */
    private function activeDevelopers(): array
    {
        return DB::table('integration_client_developers as d')->join('users as u', 'u.id', '=', 'd.user_id')
            ->where('d.integration_client_id', $this->record->id)->where('d.status', 'ACTIVE')->orderBy('u.email')
            ->get(['d.user_id', 'u.full_name', 'u.email', 'd.role'])
            ->mapWithKeys(fn ($r) => [$r->user_id => trim($r->full_name.' <'.$r->email.'> ('.$r->role.')')])->all();
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
            Section::make('Developers')->columnSpanFull()->schema([
                TextEntry::make('developer_links')->hiddenLabel()->listWithLineBreaks()
                    ->state(fn () => DB::table('integration_client_developers as d')->join('users as u', 'u.id', '=', 'd.user_id')
                        ->where('d.integration_client_id', $this->record->id)->orderBy('d.created_at')
                        ->get(['u.full_name', 'u.email', 'd.role', 'd.status'])
                        ->map(fn ($r) => trim($r->full_name.' <'.$r->email.'> — '.$r->role.' — '.$r->status))->all())
                    ->placeholder('No developers linked.'),
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
