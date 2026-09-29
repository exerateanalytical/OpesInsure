<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Coinsurance\CoinsuranceService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Str;

/**
 * Co-insurance arrangements (UI coverage batch 25). Same service, same permission, same validation as the API routes;
 * activation stays with coinsurance.approve (the service refuses activation by the arrangement's creator).
 *   coCreate     POST coinsurance/arrangements                       coinsurance.manage     CoinsuranceService::create
 *   coActivate   POST coinsurance/arrangements/{a}/activate          coinsurance.approve    CoinsuranceService::activate
 *   coPreview    POST coinsurance/arrangements/{a}/preview           coinsurance.view       CoinsuranceService::compute
 *   coApportion  POST coinsurance/arrangements/{a}/apportionments    coinsurance.apportion  CoinsuranceService::apportion
 *   coTerminate  POST coinsurance/arrangements/{a}/terminate         coinsurance.approve    CoinsuranceService::terminate
 */
final class CoinsuranceActions
{
    private const L = RiskTransferSupport::L;

    public static function coCreate(): Action
    {
        $p = 'coinsurance.manage';

        return WorkflowAction::make('coCreate', $p, self::L)->icon('lucide-plus')
            ->schema([
                TextInput::make('reference')->label(RiskTransferSupport::f('reference'))->required()->maxLength(64),
                RiskTransferSupport::policySelect(),
                Toggle::make('allow_partial_placement')->label(RiskTransferSupport::f('allow_partial_placement')),
                CheckboxList::make('lead_rights')->label(RiskTransferSupport::f('lead_rights'))->options(RiskTransferSupport::codes(CoinsuranceService::LEAD_RIGHTS))->columns(2),
                DatePicker::make('effective_from')->label(RiskTransferSupport::f('effective_from'))->required(),
                DatePicker::make('effective_until')->label(RiskTransferSupport::f('effective_until'))->after('effective_from'),
                TextInput::make('settlement_method')->label(RiskTransferSupport::f('settlement_method'))->maxLength(32)->regex('/^[A-Z0-9_]+$/'),
                Repeater::make('participants')->label(RiskTransferSupport::f('participants'))->required()->minItems(2)->maxItems(50)->defaultItems(2)->schema([
                    Select::make('carrier_id')->label(RiskTransferSupport::f('carrier'))->options(fn () => FinanceOptions::carriers())->searchable()->required(),
                    Select::make('role')->label(RiskTransferSupport::f('role'))->options(RiskTransferSupport::codes(CoinsuranceService::ROLES))->required(),
                    TextInput::make('share_bps')->label(RiskTransferSupport::f('share_bps'))->integer()->minValue(1)->maxValue(10000)->required(),
                ]),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $d = RiskTransferSupport::clean(collect($data)->except(['participants', 'allow_partial_placement'])->all());
                $d['currency'] = 'XAF';
                $d['allow_partial_placement'] = (bool) ($data['allow_partial_placement'] ?? false);
                $d['participants'] = array_values(array_map(fn ($x) => ['carrier_id' => $x['carrier_id'], 'role' => $x['role'], 'share_bps' => (int) $x['share_bps']], $data['participants'] ?? []));

                return WorkflowAction::run($action, $p, fn () => app(CoinsuranceService::class)->create(RiskTransferSupport::tenant(), $d, RiskTransferSupport::user()), __(self::L.'.coCreate.done'));
            });
    }

    public static function coActivate(): Action
    {
        $p = 'coinsurance.approve';

        return WorkflowAction::make('coActivate', $p, self::L)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'DRAFT')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(CoinsuranceService::class)->activate(RiskTransferSupport::tenant(), WorkflowAction::id($record), RiskTransferSupport::user()), __(self::L.'.coActivate.done')));
    }

    public static function coPreview(): Action
    {
        $p = 'coinsurance.view';

        return WorkflowAction::make('coPreview', $p, self::L)->icon('lucide-calculator')->color('gray')
            ->schema([
                Select::make('basis')->label(RiskTransferSupport::f('basis'))->options(RiskTransferSupport::codes(CoinsuranceService::BASES))->required(),
                TextInput::make('total_minor')->label(RiskTransferSupport::f('total_minor'))->integer()->required(),
            ])
            ->action(function (Action $action, mixed $record, array $data) use ($p) {
                $s = app(CoinsuranceService::class);
                $out = WorkflowAction::run($action, $p, fn () => $s->compute($s->show(RiskTransferSupport::tenant(), WorkflowAction::id($record)), $data['basis'], (int) $data['total_minor']),
                    __(self::L.'.coPreview.done'));
                RiskTransferSupport::show(__(self::L.'.coPreview.label'), collect($out['lines'] ?? $out)->mapWithKeys(fn ($l, $k) => is_array($l) && isset($l['carrier_id'])
                    ? [($l['role'] ?? '').' '.substr((string) $l['carrier_id'], 0, 8) => $l['amount_minor'] ?? null] : [$k => $l])->all());
            });
    }

    public static function coApportion(): Action
    {
        $p = 'coinsurance.apportion';

        return WorkflowAction::make('coApportion', $p, self::L)->icon('lucide-split')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'ACTIVE')
            ->schema([
                Select::make('basis')->label(RiskTransferSupport::f('basis'))->options(RiskTransferSupport::codes(CoinsuranceService::BASES))->required(),
                TextInput::make('total_minor')->label(RiskTransferSupport::f('total_minor'))->integer()->required(),
                TextInput::make('source_type')->label(RiskTransferSupport::f('source_type'))->required()->maxLength(64),
                TextInput::make('source_id')->label(RiskTransferSupport::f('source_id'))->uuid(),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CoinsuranceService::class)->apportion(RiskTransferSupport::tenant(), WorkflowAction::id($record), $data['basis'], (int) $data['total_minor'],
                    $data['source_type'], ($data['source_id'] ?? null) ?: null, 'web-'.Str::uuid(), RiskTransferSupport::user()),
                __(self::L.'.coApportion.done')));
    }

    public static function coTerminate(): Action
    {
        $p = 'coinsurance.approve';

        return WorkflowAction::make('coTerminate', $p, self::L)->icon('lucide-ban')->color('danger')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'ACTIVE')
            ->schema([Textarea::make('reason')->label(RiskTransferSupport::f('reason'))->required()->minLength(10)->maxLength(2000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CoinsuranceService::class)->terminate(RiskTransferSupport::tenant(), WorkflowAction::id($record), $data['reason'], RiskTransferSupport::user()), __(self::L.'.coTerminate.done')));
    }
}
