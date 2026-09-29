<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Audit\AuditWriter;
use App\Application\Integrations\Developer\OAuthScopeCatalogue;
use App\Application\Integrations\IntegrationClientLifecycleService;
use App\Filament\Admin\Resources\IntegrationClients\IntegrationClientResource;
use App\Filament\Shared\Actions\WorkflowAction;
use App\Filament\Shared\Columns;
use App\Models\IntegrationClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * DEV-005 Create API client — POST integrations/clients (integrations.manage) through
 * IntegrationClientLifecycleService::register: a real client-credentials OAuth client is created and its secret is
 * shown here exactly once (never stored in clear, never recoverable). The new connection starts in DRAFT and is
 * progressed through certification on its Partner connection page.
 */
final class ApiClients extends AdminScreenPage
{
    public const P = 'integrations.manage';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-key-round';

    protected static ?int $navigationSort = 92;

    protected static ?string $slug = 'integrations/api-clients';

    protected static array $permissions = [self::P];

    protected static string $screen = 'api_clients';

    protected static string $group = 'Integrations';

    /** Shown once after creation; cleared by dismissSecret() and never persisted. */
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
        return [
            self::kpi('clients', IntegrationClient::count()),
            self::kpi('active_clients', IntegrationClient::where('status', 'ACTIVE')->count(), 'success'),
            self::kpi('in_certification', IntegrationClient::whereIn('status', ['DRAFT', 'TECHNICAL_REVIEW', 'SANDBOX_ENABLED', 'CERTIFICATION', 'PRODUCTION_APPROVED'])->count()),
            self::kpi('suspended_revoked', IntegrationClient::whereIn('status', ['SUSPENDED', 'REVOKED', 'RESTRICTED'])->count()),
        ];
    }

    /** Same validation as IntegrationController::createClient, then the lifecycle service and the same audit entry. */
    /** R1: integration clients are platform-wide (no tenant_id) — only the PLATFORM tenant manages them, never an insurer's '*' roles. */
    public static function canAccess(): bool
    {
        return parent::canAccess() && app(\App\Application\Identity\Rbac\PlatformAuthority::class)->isPlatformTenant();
    }

    public function register(array $data): array
    {
        abort_unless(WorkflowAction::allowed(self::P) && self::canAccess(), 403);
        $d = validator($data, [
            'partner_id' => 'nullable|uuid|exists:partners,id',
            'name' => 'required|string|max:160',
            'scopes' => 'required|array|min:1',
            'scopes.*' => ['string', \Illuminate\Validation\Rule::in(OAuthScopeCatalogue::scopes())],
            'allowed_ips' => 'sometimes|array',
            'allowed_ips.*' => 'ip',
            'rate_limit_per_minute' => 'required|integer|min:1|max:1000',
            'environment' => 'sometimes|in:sandbox,production',
        ])->validate();
        $d = array_filter($d, fn ($v) => $v !== null && $v !== []) + ['scopes' => $d['scopes']];
        try {
            $result = app(IntegrationClientLifecycleService::class)->register($d, auth()->user());
        } catch (ValidationException $e) {
            app(AuditWriter::class)->record('integration.client.created.denied', 'integration_client', null, [], $e->validator->errors()->first());
            throw $e;
        }
        app(AuditWriter::class)->record('integration.client.created', 'integration_client', $result['integration_client']->id, []);

        return $result;
    }

    protected function getHeaderActions(): array
    {
        return [
            WorkflowAction::make('apiClientCreate', self::P, 'admin_screens')->icon('lucide-plus')
                ->schema([
                    TextInput::make('name')->label(self::col('name'))->required()->maxLength(160),
                    Select::make('partner_id')->label(self::col('partner'))->searchable()
                        ->options(fn () => DB::table('partners')->join('parties', 'parties.id', '=', 'partners.party_id')->orderBy('parties.display_name')->limit(500)->pluck('parties.display_name', 'partners.id')->all()),
                    Select::make('environment')->label(self::col('environment'))->options(['sandbox' => 'Sandbox', 'production' => 'Production'])->default('sandbox')->required(),
                    TextInput::make('rate_limit_per_minute')->label(self::col('rate_limit'))->numeric()->minValue(1)->maxValue(1000)->default(60)->required(),
                    CheckboxList::make('scopes')->label(self::col('scopes'))->options(fn () => collect(OAuthScopeCatalogue::SCOPES)->mapWithKeys(fn ($def, $scope) => [$scope => $scope.' — '.($def['description'] ?? '')])->all())
                        ->required()->columns(2),
                    TagsInput::make('allowed_ips')->label(self::col('allowed_ips')),
                ])
                ->action(function (Action $action, array $data): void {
                    $result = WorkflowAction::run($action, self::P, fn () => $this->register($data), __('admin_screens.apiClientCreate.done'));
                    if (is_array($result)) {
                        $this->issued = ['name' => $result['integration_client']->name, 'client_id' => $result['client_id'], 'secret' => $result['client_secret']];
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => IntegrationClient::query())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(self::col('name'))->searchable(),
                TextColumn::make('environment')->label(self::col('environment'))->badge(),
                Columns::status('status', self::col('status')),
                TextColumn::make('scopes')->label(self::col('scopes'))->state(fn (IntegrationClient $r) => count((array) $r->scopes)),
                TextColumn::make('rate_limit_per_minute')->label(self::col('rate_limit'))->numeric(),
                Columns::date('last_used_at', true, self::col('last_used'))->placeholder('—'),
                Columns::date('created_at', true, self::col('created_at')),
            ])
            ->recordUrl(fn (IntegrationClient $r) => rescue(fn () => IntegrationClientResource::getUrl('view', ['record' => $r]), null, false))
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
