<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Vehicles\Power\FiscalPowerBands;
use App\Application\Vehicles\Power\FiscalPowerService;
use App\Application\Vehicles\Power\PowerUnits;
use App\Application\Vehicles\Power\VehiclePowerService;
use App\Application\Vehicles\Power\VehicleStampDutyService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Pages\GovernanceRegisterPage;
use App\Models\Vehicles\VehicleVariant;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\DB;

/**
 * Vehicle power & fiscal power master (batch 19). Same permission + service + validation as
 * App\Application\Vehicles\Power\Http\VehiclePowerController (routes/api.php master-data/...):
 *   vpRecordPower         POST master-data/vehicles/{variant}/power                  vehicle_power.manage            VehiclePowerService::record
 *   vpSubmitFiscal        POST master-data/vehicles/{variant}/fiscal-power           vehicle_power.fiscal.submit     FiscalPowerService::submit
 *   vpReportConflict      POST master-data/vehicles/{variant}/fiscal-power/conflict  vehicle_power.fiscal.submit     FiscalPowerService::reportConflict
 *   vpVerifyVariant       POST master-data/vehicles/{variant}/fiscal-power/verify    vehicle_power.fiscal.verify     FiscalPowerService::find + verify
 *   vpSubmitRecord        POST master-data/fiscal-power/records                      vehicle_power.fiscal.submit     FiscalPowerService::submit (registration / VIN)
 *   vpAttachSource        POST master-data/fiscal-power/records/{r}/source           vehicle_power.fiscal.submit     FiscalPowerService::attachSource
 *   vpVerifyRecord        POST master-data/fiscal-power/records/{r}/verify           vehicle_power.fiscal.verify     FiscalPowerService::verify
 *   vpRejectRecord        POST master-data/fiscal-power/records/{r}/reject           vehicle_power.fiscal.verify     FiscalPowerService::reject
 *   vpResolveConflict     POST master-data/fiscal-power/conflicts/{c}/resolve        vehicle_power.fiscal.verify     FiscalPowerService::resolveConflict
 *   vpScheduleVersion     POST master-data/fiscal-power/rate-schedules/version       vehicle_power.stamp_duty.manage VehicleStampDutyService::createVersion
 *   vpScheduleApprove     POST master-data/fiscal-power/rate-schedules/{s}/approve   vehicle_power.stamp_duty.approve VehicleStampDutyService::approve
 *   vpRecordLicence       POST master-data/vehicles/transport-licences               vehicle_power.fiscal.submit     VehicleStampDutyService::recordLicence
 *   vpDecideLicence       POST master-data/vehicles/transport-licences/{l}/decision  vehicle_power.fiscal.verify     VehicleStampDutyService::decideLicence
 * Maker-checker (verifier ≠ capturer, approver ≠ maker) is enforced by the services; refusals are shown.
 */
final class VehiclePowerActions
{
    private const LANG = 'masterdata_actions';

    /** @return list<Action> variant detail page header actions */
    public static function variantActions(): array
    {
        return [self::recordPower(), self::submitFiscal(), self::reportConflict(), self::verifyVariant()];
    }

    public static function recordPower(): Action
    {
        $p = 'vehicle_power.manage';

        return WorkflowAction::make('vpRecordPower', $p, self::LANG)->icon('lucide-gauge')
            ->schema([
                TextInput::make('power_source_value')->label(self::f('power_source_value'))->required()->numeric(),
                Select::make('power_source_unit')->label(self::f('power_source_unit'))->required()->options(array_combine(PowerUnits::SOURCE_UNITS, PowerUnits::SOURCE_UNITS)),
                Select::make('power_source_type')->label(self::f('source_type'))->options(array_combine(VehiclePowerService::SOURCE_TYPES, VehiclePowerService::SOURCE_TYPES)),
                TextInput::make('power_source_reference')->label(self::f('source_reference'))->maxLength(191),
                TextInput::make('displacement_cc')->label(self::f('displacement_cc'))->integer()->minValue(1),
                DatePicker::make('effective_from')->label(self::f('effective_from')),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, VehicleVariant $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(VehiclePowerService::class)->record(VehicleVariant::findOrFail($record->id), self::clean($data), auth()->user()), __('masterdata_actions.vpRecordPower.done')));
    }

    public static function submitFiscal(): Action
    {
        $p = 'vehicle_power.fiscal.submit';

        return WorkflowAction::make('vpSubmitFiscal', $p, self::LANG)->icon('lucide-file-plus')
            ->schema(self::fiscalFields(true))
            ->action(fn (Action $action, VehicleVariant $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(FiscalPowerService::class)->submit(['variant_id' => VehicleVariant::findOrFail($record->id)->id] + self::clean($data), auth()->user(), self::tenant()),
                __('masterdata_actions.vpSubmitFiscal.done')));
    }

    public static function reportConflict(): Action
    {
        $p = 'vehicle_power.fiscal.submit';

        return WorkflowAction::make('vpReportConflict', $p, self::LANG)->icon('lucide-git-compare')->color('warning')
            ->schema(self::fiscalFields(true))
            ->action(fn (Action $action, VehicleVariant $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(FiscalPowerService::class)->reportConflict(self::clean($data) + ['variant_id' => VehicleVariant::findOrFail($record->id)->id], auth()->user(), self::tenant()),
                __('masterdata_actions.vpReportConflict.done')));
    }

    public static function verifyVariant(): Action
    {
        $p = 'vehicle_power.fiscal.verify';

        return WorkflowAction::make('vpVerifyVariant', $p, self::LANG)->icon('lucide-badge-check')->color('success')
            ->schema(fn (VehicleVariant $record) => [
                Select::make('record_id')->label(self::f('record'))->required()
                    ->options(fn () => DB::table('vehicle_fiscal_power_records')->where('variant_id', $record->id)->where('review_state', 'PENDING_REVIEW')->orderByDesc('version')
                        ->get()->mapWithKeys(fn ($r) => [$r->id => 'v'.$r->version.' · '.$r->fiscal_power_cv.' CV · '.$r->source_type])->all()),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, VehicleVariant $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $svc = app(FiscalPowerService::class);
                $rec = $svc->find($data['record_id']);
                abort_unless($rec->variant_id === $record->id, 404);

                return $svc->verify($rec->id, auth()->user(), $data['notes'] ?? null, self::tenant());
            }, __('masterdata_actions.vpVerifyVariant.done')));
    }

    // ------------------------------------------------------------------ fiscal power records register

    public static function submitRecord(): Action
    {
        $p = 'vehicle_power.fiscal.submit';

        return WorkflowAction::make('vpSubmitRecord', $p, self::LANG)->icon('lucide-plus')
            ->schema([
                TextInput::make('registration_number')->label(self::f('registration_number'))->maxLength(40),
                TextInput::make('vin')->label(self::f('vin'))->maxLength(40),
                ...self::fiscalFields(true),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(FiscalPowerService::class)->submit(self::clean($data), auth()->user(), self::tenant()), __('masterdata_actions.vpSubmitRecord.done')));
    }

    public static function attachSource(): Action
    {
        $p = 'vehicle_power.fiscal.submit';

        return WorkflowAction::make('vpAttachSource', $p, self::LANG)->icon('lucide-paperclip')
            ->visible(fn (array $record) => $record['review_state'] === 'DRAFT')
            ->schema(self::fiscalFields(false))
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(FiscalPowerService::class)->attachSource(self::record($record)->id, self::clean($data), auth()->user()), __('masterdata_actions.vpAttachSource.done')));
    }

    public static function verifyRecord(): Action
    {
        $p = 'vehicle_power.fiscal.verify';

        return WorkflowAction::make('vpVerifyRecord', $p, self::LANG)->icon('lucide-badge-check')->color('success')
            ->visible(fn (array $record) => $record['review_state'] === 'PENDING_REVIEW')
            ->schema([Textarea::make('notes')->label(self::f('notes'))->maxLength(2000)])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(FiscalPowerService::class)->verify(self::record($record)->id, auth()->user(), $data['notes'] ?? null, self::tenant()), __('masterdata_actions.vpVerifyRecord.done')));
    }

    public static function rejectRecord(): Action
    {
        $p = 'vehicle_power.fiscal.verify';

        return WorkflowAction::make('vpRejectRecord', $p, self::LANG)->icon('lucide-x-circle')->color('danger')
            ->visible(fn (array $record) => in_array($record['review_state'], ['DRAFT', 'SOURCE_ATTACHED', 'PENDING_REVIEW', 'CONFLICT_REVIEW_REQUIRED'], true))
            ->schema([Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(FiscalPowerService::class)->reject(self::record($record)->id, auth()->user(), $data['reason']), __('masterdata_actions.vpRejectRecord.done')));
    }

    public static function resolveConflict(): Action
    {
        $p = 'vehicle_power.fiscal.verify';

        return WorkflowAction::make('vpResolveConflict', $p, self::LANG)->icon('lucide-scale')
            ->visible(fn (array $record) => $record['status'] === 'OPEN')
            ->schema([
                Toggle::make('accept_challenger')->label(self::f('accept_challenger'))->default(false),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(2000),
            ])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $c = DB::table('vehicle_fiscal_power_conflicts')->where('id', $record['id'])->first() ?? abort(404);
                self::record(['id' => $c->record_id]);

                return app(FiscalPowerService::class)->resolveConflict($c->id, (bool) ($data['accept_challenger'] ?? false), auth()->user(), $data['reason']);
            }, __('masterdata_actions.vpResolveConflict.done')));
    }

    // ------------------------------------------------------------------ stamp duty schedules

    public static function scheduleVersion(): Action
    {
        $p = 'vehicle_power.stamp_duty.manage';

        return WorkflowAction::make('vpScheduleVersion', $p, self::LANG)->icon('lucide-plus')
            ->schema(fn () => [
                Select::make('schedule_code')->label(self::f('schedule_code'))->required()->options(array_combine(VehicleStampDutyService::SCHEDULES, VehicleStampDutyService::SCHEDULES)),
                ...array_map(fn ($b) => TextInput::make('rates_xaf.'.$b->code)->label(self::f('rate_xaf').' · '.$b->code)->required()->integer()->minValue(0), app(FiscalPowerBands::class)->all()),
                DatePicker::make('effective_from')->label(self::f('effective_from'))->required(),
                DatePicker::make('effective_until')->label(self::f('effective_until'))->afterOrEqual('effective_from'),
                TextInput::make('legal_reference')->label(self::f('legal_reference'))->required()->maxLength(255),
                TextInput::make('label_fr')->label(self::f('label_fr'))->maxLength(191),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $data['rates_xaf'] = array_map('intval', (array) ($data['rates_xaf'] ?? []));

                return WorkflowAction::run($action, $p, fn () => app(VehicleStampDutyService::class)->createVersion(self::clean($data), auth()->user()), __('masterdata_actions.vpScheduleVersion.done'));
            });
    }

    public static function scheduleApprove(): Action
    {
        $p = 'vehicle_power.stamp_duty.approve';

        return WorkflowAction::make('vpScheduleApprove', $p, self::LANG)->icon('lucide-check-check')->color('success')->requiresConfirmation()
            ->visible(fn (array $record) => $record['status'] === 'DRAFT')
            ->action(fn (Action $action, array $record) => WorkflowAction::run($action, $p,
                fn () => app(VehicleStampDutyService::class)->approve((string) $record['id'], auth()->user()), __('masterdata_actions.vpScheduleApprove.done')));
    }

    // ------------------------------------------------------------------ transport licences

    public static function recordLicence(): Action
    {
        $p = 'vehicle_power.fiscal.submit';

        return WorkflowAction::make('vpRecordLicence', $p, self::LANG)->icon('lucide-plus')
            ->schema([
                TextInput::make('registration_number')->label(self::f('registration_number'))->required()->maxLength(40),
                TextInput::make('licence_number')->label(self::f('licence_number'))->required()->maxLength(80),
                TextInput::make('licence_type')->label(self::f('licence_type'))->maxLength(48),
                TextInput::make('issuing_authority')->label(self::f('issuing_authority'))->maxLength(191),
                DatePicker::make('valid_from')->label(self::f('valid_from'))->required(),
                DatePicker::make('valid_until')->label(self::f('valid_until'))->afterOrEqual('valid_from'),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(VehicleStampDutyService::class)->recordLicence(self::tenant(), self::clean($data), auth()->user()), __('masterdata_actions.vpRecordLicence.done')));
    }

    public static function decideLicence(): Action
    {
        $p = 'vehicle_power.fiscal.verify';
        $next = ['PENDING' => ['VALID', 'REJECTED'], 'VALID' => ['EXPIRED', 'SUSPENDED', 'REVOKED'], 'SUSPENDED' => ['VALID', 'REVOKED']];

        return WorkflowAction::make('vpDecideLicence', $p, self::LANG)->icon('lucide-gavel')
            ->visible(fn (array $record) => isset($next[$record['status']]))
            ->schema(fn (array $record) => [
                Select::make('status')->label(self::f('status'))->required()->options(array_combine($next[$record['status']] ?? [], $next[$record['status']] ?? [])),
                Textarea::make('notes')->label(self::f('notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, array $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(VehicleStampDutyService::class)->decideLicence(self::tenant(), (string) $record['id'], $data['status'], auth()->user(), $data['notes'] ?? null),
                __('masterdata_actions.vpDecideLicence.done')));
    }

    // ------------------------------------------------------------------ helpers

    /** Fiscal input fields (VehiclePowerController::fiscalInput). */
    private static function fiscalFields(bool $cvRequired): array
    {
        return [
            TextInput::make('fiscal_power_cv')->label(self::f('fiscal_power_cv'))->required($cvRequired)->integer(),
            Select::make('source_type')->label(self::f('source_type'))->options(array_combine(array_keys(FiscalPowerService::SOURCE_PRIORITY), array_keys(FiscalPowerService::SOURCE_PRIORITY))),
            TextInput::make('source_reference')->label(self::f('source_reference'))->maxLength(191),
            TextInput::make('source_document_id')->label(self::f('source_document_id'))->uuid(),
            TextInput::make('source_url')->label(self::f('source_url'))->url()->maxLength(500),
            DatePicker::make('effective_from')->label(self::f('effective_from')),
            Textarea::make('notes')->label(self::f('notes'))->maxLength(2000),
        ];
    }

    /** Record in the caller's register (platform variant rows or the tenant's registration rows). */
    private static function record(array $record): object
    {
        return GovernanceRegisterPage::row('vehicle_fiscal_power_records', (string) $record['id'], self::tenant(), true);
    }

    private static function tenant(): ?string
    {
        return rescue(fn () => app(TenantContext::class)->id(), null, false);
    }

    private static function clean(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private static function f(string $k): string
    {
        return __("masterdata_actions.fields.{$k}");
    }
}
