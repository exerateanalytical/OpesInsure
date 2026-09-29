<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Integrations;

use App\Application\Identity\Rbac\PlatformAuthority;
use App\Application\Notifications\Otp\OtpDeliveryService;
use App\Application\Notifications\Sms\SmsDeliveryException;
use App\Application\Notifications\Sms\SmsGateway;
use App\Application\Notifications\Sms\SmsProviderConnections;
use App\Filament\Shared\Actions\WorkflowAction;
use App\Models\SmsMessage;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Integrations → SMS providers (S14; platform administrators of the platform tenant only).
 * Configure Orange / Twilio / Africa's Talking / generic HTTP gateway (credentials write-only, encrypted at rest),
 * choose primary + fallback, send a test SMS to a typed number, per-provider health and the masked delivery log.
 */
final class SmsProviders extends Page implements HasTable
{
    use InteractsWithTable;

    private const L = 'sms_providers';

    protected string $view = 'filament.admin.pages.sms-providers';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-message-square-text';

    protected static ?int $navigationSort = 92;

    protected static ?string $slug = 'integrations/sms-providers';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (bool) rescue(fn () => app(PlatformAuthority::class)->isPlatformAdmin($user), false, false);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Integrations';
    }

    public static function getNavigationLabel(): string
    {
        return __(self::L.'.nav');
    }

    public function getTitle(): string
    {
        return __(self::L.'.title');
    }

    protected function getViewData(): array
    {
        return [
            'gatewayStatus' => app(SmsGateway::class)->status(),
            'otpStatus' => app(OtpDeliveryService::class)->status(),
            'log' => SmsMessage::query()->latest()->limit(50)->get(['created_at', 'provider', 'purpose', 'destination_masked', 'encoding', 'segments', 'status', 'attempt', 'error']),
        ];
    }

    public function table(Table $table): Table
    {
        $L = self::L;

        return $table
            ->records(fn (): array => self::canAccess() ? collect(app(SmsProviderConnections::class)->present())->keyBy('id')->all() : [])
            ->columns([
                TextColumn::make('label')->label(__("{$L}.columns.label"))->description(fn (array $record) => __("{$L}.providers.{$record['provider']}")),
                TextColumn::make('role')->label(__("{$L}.columns.role"))->badge()->formatStateUsing(fn ($state) => __("{$L}.roles.{$state}"))
                    ->color(fn ($state) => match ($state) { 'PRIMARY' => 'success', 'FALLBACK' => 'info', default => 'gray' }),
                TextColumn::make('status')->label(__("{$L}.columns.status"))->badge()->color(fn ($state) => $state === 'ACTIVE' ? 'success' : 'gray'),
                TextColumn::make('complete')->label(__("{$L}.columns.complete"))->badge()
                    ->formatStateUsing(fn ($state) => $state ? __("{$L}.complete_yes") : __("{$L}.complete_no"))->color(fn ($state) => $state ? 'success' : 'danger'),
                TextColumn::make('health_status')->label(__("{$L}.columns.health_status"))->badge()->formatStateUsing(fn ($state) => __("{$L}.health.{$state}"))
                    ->color(fn ($state) => match ($state) { 'OK' => 'success', 'FAILING' => 'danger', default => 'gray' }),
                TextColumn::make('sent_24h')->label(__("{$L}.columns.sent_24h"))->formatStateUsing(fn ($state, array $record) => "{$record['sent_24h']} / {$record['total_24h']}"),
                TextColumn::make('last_success_at')->label(__("{$L}.columns.last_success_at"))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('last_error')->label(__("{$L}.columns.last_error"))->wrap()->limit(80)->placeholder('—'),
            ])
            ->headerActions([$this->createAction(), $this->testAction()])
            ->recordActions([
                $this->editAction(),
                $this->roleAction('makePrimary', 'PRIMARY', 'lucide-star'),
                $this->roleAction('makeFallback', 'FALLBACK', 'lucide-life-buoy'),
                $this->roleAction('makeStandby', 'STANDBY', 'lucide-pause'),
                WorkflowAction::make('toggle', null, self::L)->icon('lucide-power')->requiresConfirmation()
                    ->action(fn (Action $action, array $record) => WorkflowAction::run($action, null,
                        fn () => app(SmsProviderConnections::class)->setStatus($record['id'], $record['status'] !== 'ACTIVE', auth()->user()))),
            ])
            ->paginated(false)
            ->emptyStateHeading(__("{$L}.empty"));
    }

    /** @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Field> */
    private function formSchema(bool $editing): array
    {
        $L = self::L;
        $f = fn (string $k) => __("{$L}.fields.{$k}");
        $on = fn (string ...$providers) => fn (Get $get) => in_array($get('provider'), $providers, true);
        $secret = fn (string $k) => TextInput::make("secrets.{$k}")->label($f($k))->password()->autocomplete('new-password')->maxLength(2000)->helperText(__("{$L}.secret_help"));

        return [
            Select::make('provider')->label($f('provider'))->options(__("{$L}.providers"))->required()->live()->disabled($editing)->dehydrated()
                ->helperText(__("{$L}.mtn_note")),
            TextInput::make('label')->label($f('label'))->required()->maxLength(120),
            Select::make('role')->label($f('role'))->options(__("{$L}.roles"))->default('PRIMARY')->required()->hidden($editing),
            Toggle::make('active')->label($f('active'))->default(true),
            Section::make(__("{$L}.providers.orange"))->visible($on('orange'))->columns(2)->schema([
                TextInput::make('settings.sender_address')->label($f('sender_address'))->placeholder('tel:+2376XXXXXXXX')->maxLength(40),
                TextInput::make('settings.sender_name')->label($f('sender_name'))->maxLength(11),
                $secret('client_id'), $secret('client_secret'),
                TextInput::make('settings.base_url')->label($f('base_url'))->url()->placeholder('https://api.orange.com')->maxLength(255),
            ]),
            Section::make(__("{$L}.providers.twilio"))->visible($on('twilio'))->columns(2)->schema([
                TextInput::make('settings.from')->label($f('from'))->maxLength(32), $secret('account_sid'), $secret('auth_token'),
            ]),
            Section::make(__("{$L}.providers.africastalking"))->visible($on('africastalking'))->columns(2)->schema([
                TextInput::make('settings.username')->label($f('username'))->maxLength(120), TextInput::make('settings.sender_id')->label($f('sender_id'))->maxLength(11),
                $secret('api_key'), Toggle::make('settings.sandbox')->label($f('sandbox')),
            ]),
            Section::make(__("{$L}.providers.generic_http"))->visible($on('generic_http'))->description(__("{$L}.generic_help"))->columns(2)->schema([
                TextInput::make('settings.url')->label($f('url'))->url()->maxLength(500)->columnSpanFull(),
                Select::make('settings.method')->label($f('method'))->options(['POST' => 'POST', 'GET' => 'GET'])->default('POST'),
                Select::make('settings.format')->label($f('format'))->options(['form' => 'form', 'json' => 'json', 'query' => 'query'])->default('form'),
                Select::make('settings.auth')->label($f('auth'))->options(['none' => 'none', 'basic' => 'basic', 'bearer' => 'bearer'])->default('none'),
                TextInput::make('settings.sender')->label($f('sender'))->maxLength(20),
                KeyValue::make('settings.params')->label($f('params'))->default(['to' => '{to_digits}', 'message' => '{message}', 'sender' => '{sender}']),
                KeyValue::make('settings.headers')->label($f('headers')),
                TextInput::make('settings.success_contains')->label($f('success_contains'))->maxLength(120),
                TextInput::make('settings.reference_path')->label($f('reference_path'))->maxLength(120),
                $secret('username'), $secret('password'), $secret('api_key'), $secret('token'),
            ]),
        ];
    }

    private function payload(array $data, ?string $id = null, ?string $role = null): array
    {
        return ['id' => $id, 'provider' => $data['provider'] ?? null, 'label' => $data['label'] ?? '', 'role' => $role ?? ($data['role'] ?? 'STANDBY'),
            'status' => ($data['active'] ?? true) ? 'ACTIVE' : 'DISABLED', 'settings' => (array) ($data['settings'] ?? []), 'secrets' => (array) ($data['secrets'] ?? [])];
    }

    private function createAction(): Action
    {
        return WorkflowAction::make('create', null, self::L)->icon('lucide-plus')->schema($this->formSchema(false))
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, null, fn () => app(SmsProviderConnections::class)->save($this->payload($data), auth()->user())));
    }

    private function editAction(): Action
    {
        return WorkflowAction::make('edit', null, self::L)->icon('lucide-pencil')->schema($this->formSchema(true))
            ->fillForm(fn (array $record) => ['provider' => $record['provider'], 'label' => $record['label'], 'active' => $record['status'] === 'ACTIVE', 'settings' => $record['settings']])
            ->action(fn (Action $action, array $data, array $record) => WorkflowAction::run($action, null,
                fn () => app(SmsProviderConnections::class)->save($this->payload(['provider' => $record['provider']] + $data, $record['id'], $record['role']), auth()->user())));
    }

    private function roleAction(string $name, string $role, string $icon): Action
    {
        return WorkflowAction::make($name, null, self::L)->icon($icon)->requiresConfirmation()->visible(fn (array $record) => $record['role'] !== $role)
            ->action(fn (Action $action, array $record) => WorkflowAction::run($action, null, fn () => app(SmsProviderConnections::class)->setRole($record['id'], $role, auth()->user())));
    }

    private function testAction(): Action
    {
        $L = self::L;

        return Action::make('sendTest')->label(__("{$L}.sendTest.label"))->modalHeading(__("{$L}.sendTest.label"))->modalDescription(__("{$L}.sendTest.help"))
            ->icon('lucide-send')->authorize(fn () => self::canAccess())
            ->schema([
                Select::make('connection')->label(__("{$L}.fields.connection"))
                    ->options(fn () => ['' => __("{$L}.fields.chain")] + collect(app(SmsProviderConnections::class)->present())->mapWithKeys(fn ($c) => [$c['id'] => $c['label']])->all())->default(''),
                TextInput::make('phone')->label(__("{$L}.fields.phone"))->tel()->required()->regex('/^\+?\d{8,15}$/')->maxLength(16),
            ])
            ->action(function (Action $action, array $data) use ($L) {
                try {
                    $r = app(SmsProviderConnections::class)->test(($data['connection'] ?? '') !== '' ? $data['connection'] : null, (string) $data['phone'], auth()->user());
                    \Filament\Notifications\Notification::make()->success()->title(__("{$L}.sendTest.done", ['provider' => __("{$L}.providers.{$r['provider']}")]))
                        ->body("{$r['encoding']} · {$r['segments']}")->send();
                } catch (SmsDeliveryException $e) {
                    WorkflowAction::fail($e->status.': '.$e->getMessage());
                    $action->halt();
                }
            });
    }
}
