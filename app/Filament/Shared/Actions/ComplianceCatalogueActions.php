<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Compliance\Catalogue\ComplianceCatalogueService;
use App\Application\DataReadiness\DataStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/**
 * Compliance catalogue: control assessments, fraud-indicator flags and KYC refresh policies (UI coverage batch 27). Same
 * service, same permission, same validation as the API routes; the service keeps maker-checker on refresh policies and
 * refuses a rated status on a control whose requirement text is not verified.
 *   controlAssess   POST compliance-catalogue/controls/{c}/assessments           compliance.controls.assess      ComplianceCatalogueService::assess
 *   indicatorFlag   POST compliance-catalogue/fraud/indicators/{code}/flag       fraud.indicators.flag           ComplianceCatalogueService::flag
 *   refreshConfigure PUT compliance-catalogue/kyc/refresh-policies/{level}       compliance.catalogue.configure  ComplianceCatalogueService::configureRefresh
 *   refreshApprove  POST compliance-catalogue/kyc/refresh-policies/{id}/approve  compliance.catalogue.approve    ComplianceCatalogueService::approveRefresh
 */
final class ComplianceCatalogueActions
{
    private const L = RiskTransferSupport::L;

    public static function controlAssess(): Action
    {
        $p = 'compliance.controls.assess';

        return WorkflowAction::make('controlAssess', $p, self::L)->icon('lucide-clipboard-check')
            ->schema([
                Select::make('status')->label(RiskTransferSupport::f('status'))->options(RiskTransferSupport::codes(ComplianceCatalogueService::CONTROL_STATUSES))->required(),
                TagsInput::make('evidence')->label(RiskTransferSupport::f('evidence')),
                Textarea::make('notes')->label(RiskTransferSupport::f('notes'))->maxLength(10000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ComplianceCatalogueService::class)->assess(RiskTransferSupport::tenant(), WorkflowAction::id($record), RiskTransferSupport::clean($data) + ['status' => $data['status']], RiskTransferSupport::user()),
                __(self::L.'.controlAssess.done')));
    }

    public static function indicatorFlag(): Action
    {
        $p = 'fraud.indicators.flag';

        return WorkflowAction::make('indicatorFlag', $p, self::L)->icon('lucide-flag')->color('warning')
            ->schema([
                Select::make('code')->label(RiskTransferSupport::f('indicator'))->required()->searchable()
                    ->options(fn () => DB::table('fraud_indicators')->orderBy('category')->orderBy('code')->get()->mapWithKeys(fn ($i) => [$i->code => $i->code.' · '.$i->category.' · '.$i->severity])->all()),
                TextInput::make('subject_type')->label(RiskTransferSupport::f('subject_type'))->required()->maxLength(64),
                TextInput::make('subject_id')->label(RiskTransferSupport::f('subject_id'))->uuid()->required(),
                KeyValue::make('facts')->label(RiskTransferSupport::f('facts')),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ComplianceCatalogueService::class)->flag(RiskTransferSupport::tenant(), strtoupper($data['code']), $data['subject_type'], $data['subject_id'], (array) ($data['facts'] ?? []), RiskTransferSupport::user()),
                __(self::L.'.indicatorFlag.done')));
    }

    public static function refreshConfigure(): Action
    {
        $p = 'compliance.catalogue.configure';

        return WorkflowAction::make('refreshConfigure', $p, self::L)->icon('lucide-calendar-sync')
            ->schema([
                Select::make('level')->label(RiskTransferSupport::f('risk_level'))->options(RiskTransferSupport::codes(ComplianceCatalogueService::RISK_LEVELS))->required(),
                TextInput::make('refresh_months')->label(RiskTransferSupport::f('refresh_months'))->integer()->minValue(1)->maxValue(120)->required(),
                TextInput::make('source_policy_id')->label(RiskTransferSupport::f('source_policy_id'))->required()->maxLength(128),
                CheckboxList::make('trigger_events')->label(RiskTransferSupport::f('trigger_events'))->options(RiskTransferSupport::codes(ComplianceCatalogueService::TRIGGER_EVENTS))->columns(2),
                DatePicker::make('effective_from')->label(RiskTransferSupport::f('effective_from')),
                DatePicker::make('effective_until')->label(RiskTransferSupport::f('effective_until'))->after('effective_from'),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $d = RiskTransferSupport::clean(collect($data)->except('level')->all());
                $d['refresh_months'] = (int) $d['refresh_months'];

                return WorkflowAction::run($action, $p, fn () => app(ComplianceCatalogueService::class)->configureRefresh(RiskTransferSupport::tenant(), $data['level'], $d, RiskTransferSupport::user()),
                    __(self::L.'.refreshConfigure.done'));
            });
    }

    public static function refreshApprove(): Action
    {
        $p = 'compliance.catalogue.approve';

        return WorkflowAction::make('refreshApprove', $p, self::L)->icon('lucide-badge-check')->color('success')
            ->schema([
                Select::make('policy_id')->label(RiskTransferSupport::f('refresh_policy'))->required()
                    ->options(fn () => DB::table('kyc_refresh_policies')->where('tenant_id', RiskTransferSupport::tenant())->where('data_status', DataStatus::UNVERIFIED)->orderBy('risk_level')->get()
                        ->mapWithKeys(fn ($r) => [$r->id => $r->risk_level.' · '.$r->refresh_months.' · '.$r->source_policy_id])->all()),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ComplianceCatalogueService::class)->approveRefresh(RiskTransferSupport::tenant(), $data['policy_id'], RiskTransferSupport::user()), __(self::L.'.refreshApprove.done')));
    }
}
