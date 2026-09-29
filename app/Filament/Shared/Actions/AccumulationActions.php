<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Accumulation\CapacityService;
use App\Application\Accumulation\CatastropheEventService;
use App\Application\Accumulation\ExposureService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/**
 * Exposure accumulation, capacity and catastrophe events (UI coverage batch 27). Same service, same permission, same
 * validation as the API routes (AccumulationController).
 *   zoneCreate        POST accumulation/zones               accumulation.manage        ExposureService::createZone
 *   locationsRebuild  POST accumulation/locations/rebuild   accumulation.manage        ExposureService::rebuildLocations
 *   snapshotTake      POST accumulation/snapshots           accumulation.manage        ExposureService::snapshot
 *   capacitySetLimit  POST accumulation/capacity-limits     accumulation.manage        CapacityService::setLimit
 *   catDeclare        POST catastrophe-events               catastrophe.events.manage  CatastropheEventService::declare
 *   catLinkClaim      POST catastrophe-events/{e}/claims    catastrophe.events.manage  CatastropheEventService::linkClaim
 *   catAggregate      POST catastrophe-events/{e}/aggregate catastrophe.events.manage  CatastropheEventService::aggregate
 *   catClose          POST catastrophe-events/{e}/close     catastrophe.events.manage  CatastropheEventService::close
 */
final class AccumulationActions
{
    private const L = RiskTransferSupport::L;

    /** @return array<string, string> */
    public static function zones(): array
    {
        return DB::table('accumulation_zones')->where('tenant_id', RiskTransferSupport::tenant())->orderBy('code')->limit(500)->get()
            ->mapWithKeys(fn ($z) => [$z->id => $z->code.' · '.$z->name])->all();
    }

    public static function zoneCreate(): Action
    {
        $p = 'accumulation.manage';

        return WorkflowAction::make('zoneCreate', $p, self::L)->icon('lucide-map-pin-plus')
            ->schema([
                TextInput::make('code')->label(RiskTransferSupport::f('code'))->required()->maxLength(64),
                TextInput::make('name')->label(RiskTransferSupport::f('name'))->required()->maxLength(191),
                TextInput::make('country_code')->label(RiskTransferSupport::f('country_code'))->length(2)->default('CM'),
                TagsInput::make('geography_codes')->label(RiskTransferSupport::f('geography_codes')),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ExposureService::class)->createZone(RiskTransferSupport::tenant(), RiskTransferSupport::clean($data)), __(self::L.'.zoneCreate.done')));
    }

    public static function locationsRebuild(): Action
    {
        $p = 'accumulation.manage';

        return WorkflowAction::make('locationsRebuild', $p, self::L)->icon('lucide-refresh-cw')->color('gray')->requiresConfirmation()
            ->action(function (Action $action) use ($p) {
                $out = WorkflowAction::run($action, $p, fn () => app(ExposureService::class)->rebuildLocations(RiskTransferSupport::tenant()), __(self::L.'.locationsRebuild.done'));
                RiskTransferSupport::show(__(self::L.'.locationsRebuild.label'), $out);
            });
    }

    public static function snapshotTake(): Action
    {
        $p = 'accumulation.manage';

        return WorkflowAction::make('snapshotTake', $p, self::L)->icon('lucide-camera')
            ->schema([TextInput::make('currency')->label(RiskTransferSupport::f('currency'))->length(3)->default('XAF')->required()])
            ->action(function (Action $action, array $data) use ($p) {
                $out = WorkflowAction::run($action, $p, fn () => app(ExposureService::class)->snapshot(RiskTransferSupport::tenant(), strtoupper($data['currency']), auth()->id()),
                    __(self::L.'.snapshotTake.done'));
                RiskTransferSupport::show(__(self::L.'.snapshotTake.label'), $out);
            });
    }

    public static function capacitySetLimit(): Action
    {
        $p = 'accumulation.manage';

        return WorkflowAction::make('capacitySetLimit', $p, self::L)->icon('lucide-gauge')
            ->schema([
                Select::make('zone_id')->label(RiskTransferSupport::f('zone'))->options(fn () => self::zones()),
                TextInput::make('peril_code')->label(RiskTransferSupport::f('peril_code'))->maxLength(32),
                TextInput::make('currency')->label(RiskTransferSupport::f('currency'))->length(3)->default('XAF')->required(),
                TextInput::make('retention_limit_minor')->label(RiskTransferSupport::f('retention_limit_minor'))->integer()->minValue(0),
                TextInput::make('gross_limit_minor')->label(RiskTransferSupport::f('gross_limit_minor'))->integer()->minValue(0),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $d = RiskTransferSupport::clean($data);
                foreach (['retention_limit_minor', 'gross_limit_minor'] as $k) {
                    if (isset($d[$k])) {
                        $d[$k] = (int) $d[$k];
                    }
                }

                return WorkflowAction::run($action, $p, fn () => app(CapacityService::class)->setLimit(RiskTransferSupport::tenant(), $d, auth()->id()), __(self::L.'.capacitySetLimit.done'));
            });
    }

    public static function catDeclare(): Action
    {
        $p = 'catastrophe.events.manage';

        return WorkflowAction::make('catDeclare', $p, self::L)->icon('lucide-cloud-lightning')
            ->schema([
                TextInput::make('code')->label(RiskTransferSupport::f('code'))->required()->maxLength(64),
                TextInput::make('name')->label(RiskTransferSupport::f('name'))->required()->maxLength(191),
                TextInput::make('peril_code')->label(RiskTransferSupport::f('peril_code'))->required()->maxLength(32),
                Select::make('zone_ids')->label(RiskTransferSupport::f('zones'))->multiple()->options(fn () => self::zones()),
                DateTimePicker::make('starts_at')->label(RiskTransferSupport::f('starts_at'))->required(),
                DateTimePicker::make('ends_at')->label(RiskTransferSupport::f('ends_at'))->required()->afterOrEqual('starts_at'),
                TextInput::make('currency')->label(RiskTransferSupport::f('currency'))->length(3)->default('XAF')->required(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CatastropheEventService::class)->declare(RiskTransferSupport::tenant(), RiskTransferSupport::clean($data), auth()->id()), __(self::L.'.catDeclare.done')));
    }

    public static function catLinkClaim(): Action
    {
        $p = 'catastrophe.events.manage';

        return WorkflowAction::make('catLinkClaim', $p, self::L)->icon('lucide-link')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) !== 'CLOSED')
            ->schema([RiskTransferSupport::claimSelect()->required()])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CatastropheEventService::class)->linkClaim(RiskTransferSupport::tenant(), WorkflowAction::id($record), $data['claim_id'], auth()->id()), __(self::L.'.catLinkClaim.done')));
    }

    public static function catAggregate(): Action
    {
        $p = 'catastrophe.events.manage';

        return WorkflowAction::make('catAggregate', $p, self::L)->icon('lucide-sigma')->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) !== 'CLOSED')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(CatastropheEventService::class)->aggregate(RiskTransferSupport::tenant(), WorkflowAction::id($record)), __(self::L.'.catAggregate.done')));
    }

    public static function catClose(): Action
    {
        $p = 'catastrophe.events.manage';

        return WorkflowAction::make('catClose', $p, self::L)->icon('lucide-lock')->color('danger')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) !== 'CLOSED')
            ->schema([Textarea::make('reason')->label(RiskTransferSupport::f('reason'))->required()->maxLength(500)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CatastropheEventService::class)->close(RiskTransferSupport::tenant(), WorkflowAction::id($record), $data['reason']), __(self::L.'.catClose.done')));
    }
}
