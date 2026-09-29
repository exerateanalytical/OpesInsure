<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Integrations\Developer\DeveloperPortalService;
use App\Application\Integrations\Developer\OAuthScopeCatalogue;
use App\Models\IntegrationClient;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Developer portal: connection keys, rate limits and tenant consents (UI coverage batch 25). Same service, same permission,
 * same validation as the API routes.
 *   devIssueKey       POST developer/clients/{c}/keys               integrations.manage          DeveloperPortalService::issueKey
 *   devRevokeKey      POST developer/clients/{c}/keys/{k}/revoke    integrations.revoke          DeveloperPortalService::revokeKey
 *   devRateLimits     PUT  developer/clients/{c}/rate-limits        integrations.manage          DeveloperPortalService::setRateLimits
 *   devGrantConsent   POST developer/consents                       integrations.consent.manage  DeveloperPortalService::grantConsent
 *   devRevokeConsent  POST developer/consents/{c}/revoke            integrations.consent.manage  DeveloperPortalService::revokeConsent
 *
 * Secret handling: the client secret returned by issueKey is shown ONCE, in a notification the user dismisses; it is never
 * kept in a Livewire property, a form field, the audit trail or a log line (the service stores only its hash).
 */
final class DeveloperPortalActions
{
    private const L = RiskTransferSupport::L;

    /** @return array<string, string> */
    public static function clients(): array
    {
        return IntegrationClient::query()->orderBy('name')->limit(500)->get()->mapWithKeys(fn (IntegrationClient $c) => [$c->id => $c->name.' · '.$c->status])->all();
    }

    public static function devIssueKey(): Action
    {
        $p = 'integrations.manage';

        return WorkflowAction::make('devIssueKey', $p, self::L)->icon('lucide-key-round')
            ->schema([
                Select::make('integration_client_id')->label(RiskTransferSupport::f('integration_client'))->options(fn () => self::clients())->searchable()->required(),
                Select::make('environment')->label(RiskTransferSupport::f('environment'))->options(['sandbox' => 'sandbox', 'production' => 'production'])->required(),
                TextInput::make('label')->label(RiskTransferSupport::f('label'))->maxLength(120),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $out = WorkflowAction::run($action, $p, fn () => app(DeveloperPortalService::class)->issueKey(IntegrationClient::findOrFail($data['integration_client_id']),
                    $data['environment'], ($data['label'] ?? null) ?: null, RiskTransferSupport::user()), __(self::L.'.devIssueKey.done'));
                // Shown once; not stored anywhere on the page.
                Notification::make()->warning()->persistent()->title(__(self::L.'.devIssueKey.secret_title'))
                    ->body(__(self::L.'.devIssueKey.secret_body', ['client_id' => $out['client_id'], 'secret' => $out['client_secret']]))->send();
            });
    }

    public static function devRevokeKey(): Action
    {
        $p = 'integrations.revoke';

        return WorkflowAction::make('devRevokeKey', $p, self::L)->icon('lucide-key-square')->color('danger')->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) !== 'REVOKED')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(DeveloperPortalService::class)->revokeKey(IntegrationClient::findOrFail($record['integration_client_id']), WorkflowAction::id($record), RiskTransferSupport::user()),
                __(self::L.'.devRevokeKey.done')));
    }

    public static function devRateLimits(): Action
    {
        $p = 'integrations.manage';

        return WorkflowAction::make('devRateLimits', $p, self::L)->icon('lucide-gauge')
            ->schema([
                Select::make('integration_client_id')->label(RiskTransferSupport::f('integration_client'))->options(fn () => self::clients())->searchable()->required()->live()
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        $c = $state ? IntegrationClient::find($state) : null;
                        $set('rate_limit_per_minute', $c?->rate_limit_per_minute);
                        $set('sandbox_rate_limit_per_minute', $c?->sandbox_rate_limit_per_minute);
                    }),
                TextInput::make('rate_limit_per_minute')->label(RiskTransferSupport::f('rate_limit_per_minute'))->integer()->minValue(1)->maxValue(1000)->required(),
                TextInput::make('sandbox_rate_limit_per_minute')->label(RiskTransferSupport::f('sandbox_rate_limit_per_minute'))->integer()->minValue(1)->maxValue(1000)->required(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(DeveloperPortalService::class)->setRateLimits(IntegrationClient::findOrFail($data['integration_client_id']), (int) $data['rate_limit_per_minute'],
                    (int) $data['sandbox_rate_limit_per_minute'], RiskTransferSupport::user()), __(self::L.'.devRateLimits.done')));
    }

    public static function devGrantConsent(): Action
    {
        $p = 'integrations.consent.manage';

        return WorkflowAction::make('devGrantConsent', $p, self::L)->icon('lucide-shield-plus')
            ->schema([
                Select::make('integration_client_id')->label(RiskTransferSupport::f('integration_client'))->options(fn () => self::clients())->searchable()->required()->live(),
                CheckboxList::make('scopes')->label(RiskTransferSupport::f('scopes'))->required()->columns(2)
                    ->options(fn (Get $get) => RiskTransferSupport::codes(array_values(array_filter(OAuthScopeCatalogue::scopes(),
                        fn (string $s) => ($c = $get('integration_client_id') ? IntegrationClient::find($get('integration_client_id')) : null) === null || $c->hasScope($s))))),
                DateTimePicker::make('expires_at')->label(RiskTransferSupport::f('expires_at'))->after('now'),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(DeveloperPortalService::class)->grantConsent(IntegrationClient::findOrFail($data['integration_client_id']), RiskTransferSupport::tenant(),
                    array_values($data['scopes']), ($data['expires_at'] ?? null) ?: null, RiskTransferSupport::user()), __(self::L.'.devGrantConsent.done')));
    }

    public static function devRevokeConsent(): Action
    {
        $p = 'integrations.consent.manage';

        return WorkflowAction::make('devRevokeConsent', $p, self::L)->icon('lucide-shield-off')->color('danger')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'GRANTED')
            ->schema([Textarea::make('reason')->label(RiskTransferSupport::f('reason'))->required()->maxLength(500)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(DeveloperPortalService::class)->revokeConsent(WorkflowAction::id($record), RiskTransferSupport::tenant(), $data['reason'], RiskTransferSupport::user()),
                __(self::L.'.devRevokeConsent.done')));
    }
}
