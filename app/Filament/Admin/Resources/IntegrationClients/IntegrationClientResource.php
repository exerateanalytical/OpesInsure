<?php
namespace App\Filament\Admin\Resources\IntegrationClients;
use App\Filament\Admin\Resources\IntegrationClients\Pages;
use App\Models\IntegrationClient;
use BackedEnum;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only from the Filament side by design: a client's real credential
 * is a Passport client-credentials secret shown exactly once at issuance
 * (IntegrationClientLifecycleService::register(), called via the partner
 * API) — per the plan, "production credentials must never be revealed
 * again after initial secure issuance," which a Filament create form
 * can't honor well. Lifecycle transitions happen here as actions instead.
 */
final class IntegrationClientResource extends Resource
{
    protected static ?string $model = IntegrationClient::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;
    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';
    protected static ?string $navigationLabel = 'Partner connections';
    protected static ?int $navigationSort = 90;
    protected static ?string $modelLabel = 'partner connection';
    protected static ?string $pluralModelLabel = 'partner connections';

    public static function form(Schema $s): Schema
    {
        return $s->components([]);
    }

    public static function table(Table $t): Table
    {
        return $t->columns([
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('partner.party.display_name')->label('Partner')->placeholder('Platform-wide'),
            Tables\Columns\TextColumn::make('environment')->badge()->color(fn (string $state) => $state === 'production' ? 'danger' : 'gray'),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                'ACTIVE' => 'success',
                'REVOKED' => 'danger',
                'SUSPENDED', 'RESTRICTED' => 'warning',
                default => 'gray',
            }),
            Tables\Columns\TextColumn::make('webhookSubscriptions_count')->counts('webhookSubscriptions')->label('Subscriptions')->alignEnd(),
            Tables\Columns\TextColumn::make('last_used_at')->dateTime()->since()->placeholder('Never')->label('Last used'),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options(['DRAFT', 'TECHNICAL_REVIEW', 'SANDBOX_ENABLED', 'CERTIFICATION', 'PRODUCTION_APPROVED', 'ACTIVE', 'RESTRICTED', 'SUSPENDED', 'REVOKED']),
        ])->recordActions([
            Actions\ViewAction::make(),
        ])->emptyStateHeading('No partner connections')
            ->emptyStateDescription('Connections are registered through the integrations API (POST /api/v1/integrations/clients), then progressed through certification here.')
            ->emptyStateIcon(Heroicon::OutlinedServerStack);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIntegrationClients::route('/'),
            'view' => Pages\ViewIntegrationClient::route('/{record}'),
        ];
    }
}
