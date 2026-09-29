<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Integrations;

use App\Application\Integrations\Activa\ActivaCircuitBreaker;
use App\Application\Integrations\Activa\ActivaConnections;
use App\Application\Integrations\Activa\ActivaPolicySync;
use App\Application\Integrations\Activa\ActivaReconciliation;
use App\Application\Integrations\Activa\ActivaReferenceDataSync;
use App\Application\Integrations\Activa\ActivaServices;
use App\Filament\Shared\Actions\FinanceOptions;
use App\Filament\Shared\Actions\WorkflowAction;
use App\Models\CarrierApiConnection;
use App\Models\CarrierApiSyncRecord;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Integrations → Activa Assurances (permission integrations.manage).
 * Per connection and service: health (last auth verdict, circuit breaker), last reference sync / reconciliation;
 * the queue of steps that did not reach Activa, with retry. Actions: enter credentials (write-only, never shown back),
 * test connection (each service's auth → OK / 401), run the reference sync, run the reconciliation.
 */
final class ActivaIntegration extends Page implements HasTable
{
    use InteractsWithTable;

    public const P = 'integrations.manage';

    private const L = 'activa_integration';

    protected string $view = 'filament.admin.pages.activa-integration';

    protected static string|\BackedEnum|null $navigationIcon = 'lucide-plug-zap';

    protected static ?int $navigationSort = 93;

    protected static ?string $slug = 'integrations/activa';

    /** Last "Test connection" verdict per environment/service, shown until the next render cycle. */
    public array $lastTest = [];

    public static function canAccess(): bool
    {
        return WorkflowAction::allowed(self::P);
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
        $connections = app(ActivaConnections::class);
        $breaker = app(ActivaCircuitBreaker::class);

        return [
            'connections' => array_map(function (CarrierApiConnection $c) use ($connections, $breaker) {
                $p = $connections->present($c);
                foreach (ActivaServices::ALL as $s) {
                    $p['services'][$s]['circuit'] = $breaker->state($c->id, $s);
                }
                $p['open_failures'] = CarrierApiSyncRecord::where('connection_id', $c->id)->whereIn('status', CarrierApiSyncRecord::FAILURES)->count();
                $p['synced'] = CarrierApiSyncRecord::where('connection_id', $c->id)->where('status', 'SYNCED')->count();
                $p['calls_24h'] = DB::table('carrier_api_calls')->where('connection_id', $c->id)->where('created_at', '>=', now()->subDay())->count();

                return $p;
            }, $connections->all()),
            'services' => ActivaServices::ALL,
            'lastTest' => $this->lastTest,
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => auth()->user() === null ? [] : $this->rows())
            ->columns(collect(['operation' => 'text', 'subject_type' => 'text', 'status' => 'status', 'attempts' => 'text', 'external_reference' => 'text',
                'last_error_code' => 'text', 'next_attempt_at' => 'date', 'updated_at' => 'date'])
                ->map(function (string $kind, string $name) {
                    $c = TextColumn::make($name)->label(__(self::L.'.columns.'.$name));

                    return match ($kind) {
                        'status' => $c->badge(),
                        'date' => $c->dateTime(),
                        default => $c->wrap(),
                    };
                })->values()->all())
            ->headerActions([$this->configureAction(), $this->testAction(), $this->referenceSyncAction(), $this->reconcileAction()])
            ->recordActions([$this->retryAction()])
            ->emptyStateHeading(__(self::L.'.empty'));
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $out = [];
        foreach (CarrierApiSyncRecord::query()->orderByRaw("CASE WHEN status IN ('FAILED','MAPPING_REQUIRED','RETRY_PENDING','CONFIG_REQUIRED') THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')->limit(300)->get() as $r) {
            $out[$r->id] = ['__key' => $r->id, 'id' => $r->id, 'operation' => $r->operation, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id,
                'status' => $r->status, 'attempts' => $r->attempts, 'external_reference' => $r->external_reference, 'last_error_code' => $r->last_error_code,
                'next_attempt_at' => $r->next_attempt_at, 'updated_at' => $r->updated_at];
        }

        return $out;
    }

    private function configureAction(): Action
    {
        $secret = fn (string $name, string $label) => TextInput::make($name)->label(__(self::L.'.fields.'.$label))->password()->autocomplete('new-password')
            ->maxLength(500)->helperText(__(self::L.'.secret_help'));
        $plain = fn (string $name, string $label) => TextInput::make($name)->label(__(self::L.'.fields.'.$label))->maxLength(255);
        $default = app(ActivaConnections::class)->defaultCarrier()?->id;

        return WorkflowAction::make('configure', self::P, self::L)->icon('lucide-key-round')
            ->schema([
                Select::make('carrier_id')->label(__(self::L.'.fields.carrier'))->options(fn () => FinanceOptions::carriers())->default($default)->searchable()->required(),
                Select::make('environment')->label(__(self::L.'.fields.environment'))->options(['SANDBOX' => 'SANDBOX', 'PRODUCTION' => 'PRODUCTION'])->default('SANDBOX')->required(),
                $secret('subscription_key', 'subscription_key'),
                Section::make(__(self::L.'.sections.travel'))->columns(2)->schema([$plain('credentials.travel.client_id', 'client_id'), $secret('credentials.travel.client_secret', 'client_secret'),
                    $plain('credentials.travel.scope', 'scope'), $secret('credentials.travel.subscription_key', 'service_subscription_key')]),
                Section::make(__(self::L.'.sections.pricing'))->columns(2)->schema([$plain('credentials.pricing.email', 'email'), $secret('credentials.pricing.password', 'password'),
                    $secret('credentials.pricing.subscription_key', 'service_subscription_key')]),
                Section::make(__(self::L.'.sections.subscription'))->columns(2)->schema([$plain('credentials.subscription.user_id', 'user_id'), $secret('credentials.subscription.password', 'password'),
                    $secret('credentials.subscription.subscription_key', 'service_subscription_key')]),
                Section::make(__(self::L.'.sections.documents'))->columns(2)->schema([$plain('credentials.documents.user_id', 'user_id'), $secret('credentials.documents.password', 'password'),
                    $secret('credentials.documents.subscription_key', 'service_subscription_key')]),
                Section::make(__(self::L.'.sections.settings'))->collapsed()->columns(2)->schema([
                    TextInput::make('settings.gateway_url')->label(__(self::L.'.fields.gateway_url'))->url()->maxLength(255),
                    TextInput::make('settings.api_version')->label(__(self::L.'.fields.api_version'))->maxLength(8),
                    KeyValue::make('settings.paths')->label(__(self::L.'.fields.paths')),
                    KeyValue::make('settings.intermediary')->label(__(self::L.'.fields.intermediary')),
                    KeyValue::make('settings.categories')->label(__(self::L.'.fields.categories')),
                    KeyValue::make('settings.travel')->label(__(self::L.'.fields.travel')),
                    KeyValue::make('settings.attestation')->label(__(self::L.'.fields.attestation')),
                    KeyValue::make('settings.payment.modes')->label(__(self::L.'.fields.payment_modes')),
                ]),
                Toggle::make('disabled')->label(__(self::L.'.fields.disabled')),
            ])
            ->action(function (Action $action, array $data) {
                $payload = ['carrier_id' => $data['carrier_id'], 'environment' => $data['environment'], 'subscription_key' => $data['subscription_key'] ?? null,
                    'credentials' => $data['credentials'] ?? [], 'settings' => array_filter((array) ($data['settings'] ?? []), fn ($v) => $v !== null), 'disabled' => (bool) ($data['disabled'] ?? false)];

                return WorkflowAction::run($action, self::P, fn () => app(ActivaConnections::class)->save($payload, auth()->user()), __(self::L.'.configure.done'));
            });
    }

    private function testAction(): Action
    {
        return WorkflowAction::make('testConnection', self::P, self::L)->icon('lucide-activity')->requiresConfirmation()
            ->action(function (Action $action) {
                $results = WorkflowAction::run($action, self::P, function () {
                    $out = [];
                    foreach (app(ActivaConnections::class)->all() as $c) {
                        $out[$c->environment] = app(ActivaConnections::class)->test($c);
                    }

                    return $out;
                }, __(self::L.'.testConnection.done'));
                $this->lastTest = (array) $results;
                foreach ((array) $results as $env => $services) {
                    $lines = collect($services)->map(fn ($r, $s) => "{$s}: {$r['state']}".($r['http_status'] ? " ({$r['http_status']})" : ''))->implode(' · ');
                    Notification::make()->title($env)->body($lines)->{collect($services)->contains(fn ($r) => $r['state'] === 'OK') ? 'success' : 'warning'}()->send();
                }
            });
    }

    private function referenceSyncAction(): Action
    {
        return WorkflowAction::make('referenceSync', self::P, self::L)->icon('lucide-database')->requiresConfirmation()
            ->action(function (Action $action) {
                $s = WorkflowAction::run($action, self::P, fn () => app(ActivaReferenceDataSync::class)->run(), __(self::L.'.referenceSync.done'));
                if (is_array($s)) {
                    Notification::make()->title(__(self::L.'.referenceSync.label'))->body(__(self::L.'.referenceSync.summary', $s + ['errors' => implode(', ', $s['errors'])]))->info()->send();
                }
            });
    }

    private function reconcileAction(): Action
    {
        return WorkflowAction::make('reconcile', self::P, self::L)->icon('lucide-refresh-cw')->requiresConfirmation()
            ->schema([Toggle::make('include_failed')->label(__(self::L.'.fields.include_failed'))])
            ->action(function (Action $action, array $data) {
                $s = WorkflowAction::run($action, self::P, fn () => app(ActivaReconciliation::class)->run(200, (bool) ($data['include_failed'] ?? false)), __(self::L.'.reconcile.done'));
                if (is_array($s)) {
                    Notification::make()->title(__(self::L.'.reconcile.label'))->body(__(self::L.'.reconcile.summary', $s))->info()->send();
                }
            });
    }

    private function retryAction(): Action
    {
        return WorkflowAction::make('retry', self::P, self::L)->icon('lucide-rotate-cw')->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) !== 'SYNCED')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, self::P, function () use ($record) {
                $sync = app(ActivaPolicySync::class);
                if (($record['subject_type'] ?? null) === 'payment_intent') {
                    $p = PaymentIntentRecord::findOrFail($record['subject_id']);

                    return $sync->syncPayment($p, true);
                }

                return $sync->syncPolicy(Policy::findOrFail($record['subject_id']), true);
            }, __(self::L.'.retry.done')));
    }
}
