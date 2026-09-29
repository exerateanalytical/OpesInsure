<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Integrations\Carriers\CarrierConnectorService;
use App\Models\ExternalRecordMapping;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/**
 * Carrier connectors (UI coverage batch 25). Same service, same permission, same validation as the API routes. The
 * connector base URL is stored encrypted by the service and never read back into a form; signing keys are referenced by id.
 *   connectorConfigure        PUT  carrier-connectors/{carrier}                         integrations.carrier_connectors.manage  CarrierConnectorService::configure
 *   connectorSync             POST carrier-connectors/{carrier}/sync                    integrations.carrier_connectors.manage  CarrierConnectorService::syncRecord
 *   connectorDispatch         POST carrier-connectors/messages/{m}/dispatch             integrations.carrier_connectors.manage  CarrierConnectorService::dispatch
 *   connectorResolveFallback  POST carrier-connectors/messages/{m}/resolve              integrations.carrier_connectors.manage  CarrierConnectorService::resolveFallback
 *   connectorResolveConflict  POST carrier-connectors/mappings/{m}/resolve-conflict     integrations.carrier_connectors.manage  CarrierConnectorService::resolveConflict
 */
final class CarrierConnectorActions
{
    private const L = RiskTransferSupport::L;

    public const P = 'integrations.carrier_connectors.manage';

    public static function connectorConfigure(): Action
    {
        $p = self::P;

        return WorkflowAction::make('connectorConfigure', $p, self::L)->icon('lucide-plug')
            ->schema([
                Select::make('carrier_id')->label(RiskTransferSupport::f('carrier'))->options(fn () => FinanceOptions::carriers())->searchable()->required(),
                Select::make('transport')->label(RiskTransferSupport::f('transport'))->options(['API' => 'API', 'MANUAL' => 'MANUAL'])->required(),
                TextInput::make('base_url')->label(RiskTransferSupport::f('base_url'))->url()->startsWith(['https://'])->maxLength(500),
                KeyValue::make('endpoints')->label(RiskTransferSupport::f('endpoints')),
                TextInput::make('signing_key_id')->label(RiskTransferSupport::f('signing_key_id'))->maxLength(64),
                Select::make('integration_client_id')->label(RiskTransferSupport::f('integration_client'))->options(fn () => DeveloperPortalActions::clients())->searchable(),
                TextInput::make('timeout_seconds')->label(RiskTransferSupport::f('timeout_seconds'))->integer()->minValue(1)->maxValue(120),
                TextInput::make('max_attempts')->label(RiskTransferSupport::f('max_attempts'))->integer()->minValue(1)->maxValue(20),
                TextInput::make('base_backoff_seconds')->label(RiskTransferSupport::f('base_backoff_seconds'))->integer()->minValue(1)->maxValue(86400),
                Select::make('status')->label(RiskTransferSupport::f('status'))->options(['ACTIVE' => 'ACTIVE', 'DISABLED' => 'DISABLED']),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $carrier = $data['carrier_id'];
                $d = RiskTransferSupport::clean(collect($data)->except('carrier_id')->all());
                foreach (['timeout_seconds', 'max_attempts', 'base_backoff_seconds'] as $k) {
                    if (isset($d[$k])) {
                        $d[$k] = (int) $d[$k];
                    }
                }

                return WorkflowAction::run($action, $p, function () use ($carrier, $d) {
                    abort_unless(DB::table('carriers')->where('id', $carrier)->exists(), 404);

                    return app(CarrierConnectorService::class)->configure($carrier, $d, RiskTransferSupport::user());
                }, __(self::L.'.connectorConfigure.done'));
            });
    }

    public static function connectorSync(): Action
    {
        $p = self::P;

        return WorkflowAction::make('connectorSync', $p, self::L)->icon('lucide-refresh-cw')
            ->schema([
                Select::make('carrier_id')->label(RiskTransferSupport::f('carrier'))->options(fn () => FinanceOptions::carriers())->searchable()->required(),
                TextInput::make('record_type')->label(RiskTransferSupport::f('record_type'))->required()->maxLength(64),
                TextInput::make('external_record_id')->label(RiskTransferSupport::f('external_record_id'))->required()->maxLength(190),
                TextInput::make('opesinsure_record_id')->label(RiskTransferSupport::f('opesinsure_record_id'))->uuid()->required(),
                TextInput::make('external_version')->label(RiskTransferSupport::f('external_version'))->maxLength(64),
                DateTimePicker::make('last_external_modified_at')->label(RiskTransferSupport::f('last_external_modified_at')),
                KeyValue::make('fields')->label(RiskTransferSupport::f('fields')),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CarrierConnectorService::class)->syncRecord($data['carrier_id'], RiskTransferSupport::clean(collect($data)->except('carrier_id')->all())),
                __(self::L.'.connectorSync.done')));
    }

    public static function connectorDispatch(): Action
    {
        $p = self::P;

        return WorkflowAction::make('connectorDispatch', $p, self::L)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (mixed $record) => in_array($record['status'] ?? null, CarrierConnectorService::DISPATCHABLE, true))
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(CarrierConnectorService::class)->dispatch(WorkflowAction::id($record)), __(self::L.'.connectorDispatch.done')));
    }

    public static function connectorResolveFallback(): Action
    {
        $p = self::P;

        return WorkflowAction::make('connectorResolveFallback', $p, self::L)->icon('lucide-hand')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'MANUAL_FALLBACK')
            ->schema([
                Select::make('resolution')->label(RiskTransferSupport::f('resolution'))->options(RiskTransferSupport::codes(['SENT_MANUALLY', 'REQUEUED', 'CANCELLED']))->required(),
                Textarea::make('note')->label(RiskTransferSupport::f('note'))->required()->maxLength(1000),
                TextInput::make('external_reference')->label(RiskTransferSupport::f('external_reference'))->maxLength(190),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CarrierConnectorService::class)->resolveFallback(WorkflowAction::id($record), $data['resolution'], $data['note'], ($data['external_reference'] ?? null) ?: null, RiskTransferSupport::user()),
                __(self::L.'.connectorResolveFallback.done')));
    }

    public static function connectorResolveConflict(): Action
    {
        $p = self::P;

        return WorkflowAction::make('connectorResolveConflict', $p, self::L)->icon('lucide-git-merge')
            ->schema([
                Select::make('mapping_id')->label(RiskTransferSupport::f('mapping'))->required()
                    ->options(fn () => ExternalRecordMapping::query()->where('synchronization_status', 'CONFLICT')->orderByDesc('updated_at')->limit(200)->get()
                        ->mapWithKeys(fn (ExternalRecordMapping $m) => [$m->id => $m->record_type.' · '.$m->external_record_id])->all()),
                Select::make('resolution')->label(RiskTransferSupport::f('resolution'))->options(RiskTransferSupport::codes(['KEEP_OPESINSURE', 'ACCEPT_EXTERNAL']))->required(),
                Textarea::make('note')->label(RiskTransferSupport::f('note'))->required()->maxLength(1000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CarrierConnectorService::class)->resolveConflict($data['mapping_id'], $data['resolution'], $data['note'], RiskTransferSupport::user()),
                __(self::L.'.connectorResolveConflict.done')));
    }
}
