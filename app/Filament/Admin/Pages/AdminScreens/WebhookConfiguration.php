<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Audit\AuditWriter;
use App\Application\Integrations\WebhookSubscriptionService;
use App\Filament\Shared\Actions\WorkflowAction;
use App\Filament\Shared\Columns;
use App\Models\CanonicalEventSchema;
use App\Models\IntegrationClient;
use App\Models\IntegrationWebhookSubscription;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

/**
 * DEV-009 Webhook configuration — every webhook subscription of the partner connections, with its delivery health
 * (last 24 hours, consecutive failures, circuit), and "Add webhook" = POST integrations/clients/{client}/webhooks
 * (integrations.manage) through WebhookSubscriptionService: ACTIVE connection only, active canonical event, https
 * endpoint; the signing secret is shown here exactly once. Endpoints are shown host-only.
 */
final class WebhookConfiguration extends AdminScreenPage
{
    public const P = 'integrations.manage';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-webhook';

    protected static ?int $navigationSort = 93;

    protected static ?string $slug = 'integrations/webhooks';

    protected static array $permissions = [self::P];

    protected static string $screen = 'webhook_configuration';

    protected static string $group = 'Integrations';

    #[Locked]
    public ?array $issued = null;

    public function extraView(): ?array
    {
        return $this->issued ? ['filament.admin.pages.partials.issued-secret', ['issued' => $this->issued]] : null;
    }

    public function dismissSecret(): void
    {
        $this->issued = null;
    }

    public function kpis(): array
    {
        $day = DB::table('integration_delivery_attempts')->where('created_at', '>=', now()->subDay());

        return [
            self::kpi('subscriptions', IntegrationWebhookSubscription::where('status', 'ACTIVE')->count()),
            self::kpi('deliveries_24h', (clone $day)->count()),
            self::kpi('failed_deliveries_24h', (clone $day)->whereIn('status', ['FAILED', 'DEAD_LETTERED', 'RETRY_SCHEDULED'])->count(), 'warning'),
            self::kpi('open_circuits', IntegrationWebhookSubscription::where('circuit_state', 'OPEN')->count(), IntegrationWebhookSubscription::where('circuit_state', 'OPEN')->exists() ? 'danger' : 'success'),
        ];
    }

    /** R1: integration clients are platform-wide (no tenant_id) — only the PLATFORM tenant manages their webhooks. */
    public static function canAccess(): bool
    {
        return parent::canAccess() && app(\App\Application\Identity\Rbac\PlatformAuthority::class)->isPlatformTenant();
    }

    public function subscribe(array $data): array
    {
        abort_unless(WorkflowAction::allowed(self::P) && self::canAccess(), 403);
        $client = IntegrationClient::query()->findOrFail($data['integration_client_id'] ?? null);
        $result = app(WebhookSubscriptionService::class)->subscribe($client, (string) ($data['event_name'] ?? ''), (string) ($data['endpoint'] ?? ''));
        app(AuditWriter::class)->record('integration.webhook.created', 'integration_webhook_subscription', $client->id, []);

        return $result + ['client' => $client];
    }

    protected function getHeaderActions(): array
    {
        return [
            WorkflowAction::make('webhookCreate', self::P, 'admin_screens')->icon('lucide-plus')
                ->schema([
                    Select::make('integration_client_id')->label(self::col('client'))->required()
                        ->options(fn () => IntegrationClient::where('status', 'ACTIVE')->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('event_name')->label(self::col('event'))->required()->searchable()
                        ->options(fn () => CanonicalEventSchema::where('status', 'ACTIVE')->orderBy('event_name')->pluck('event_name', 'event_name')->all()),
                    TextInput::make('endpoint')->label(self::col('endpoint'))->required()->url()->maxLength(500)->startsWith(['https://']),
                ])
                ->action(function (Action $action, array $data): void {
                    $result = WorkflowAction::run($action, self::P, fn () => $this->subscribe($data), __('admin_screens.webhookCreate.done'));
                    if (is_array($result)) {
                        $this->issued = ['name' => $result['client']->name.' · '.$result['subscription']->event_name, 'client_id' => $result['subscription']->id, 'secret' => $result['signing_secret']];
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->deferLoading()
            ->query(fn () => IntegrationWebhookSubscription::query()->with('client'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('client.name')->label(self::col('client'))->placeholder('—'),
                TextColumn::make('event_name')->label(self::col('event'))->fontFamily('mono'),
                TextColumn::make('endpoint_host')->label(self::col('endpoint'))
                    ->state(fn (IntegrationWebhookSubscription $r) => rescue(fn () => parse_url(Crypt::decryptString($r->endpoint_encrypted), PHP_URL_HOST), '—', false) ?: '—'),
                Columns::status('status', self::col('status')),
                TextColumn::make('deliveries_24h')->label(self::col('deliveries_24h'))
                    ->state(fn (IntegrationWebhookSubscription $r) => DB::table('integration_delivery_attempts')->where('integration_webhook_subscription_id', $r->id)->where('created_at', '>=', now()->subDay())->count()),
                TextColumn::make('consecutive_failures')->label(self::col('consecutive_failures'))->numeric()->placeholder('0'),
                TextColumn::make('circuit_state')->label(self::col('circuit'))->badge()->placeholder('CLOSED')
                    ->color(fn ($state): string => $state === 'OPEN' ? 'danger' : 'success'),
                Columns::date('created_at', true, self::col('created_at')),
            ])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
