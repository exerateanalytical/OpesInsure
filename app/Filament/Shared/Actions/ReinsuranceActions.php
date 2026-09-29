<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Reinsurance\CessionService;
use App\Application\FinancialDistribution\ReinsuranceReference;
use App\Application\Reinsurance\Facultative\FacultativePlacementService;
use App\Application\Reinsurance\Recoveries\RecoveryService;
use App\Application\Reinsurance\TreatyService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\DB;

/**
 * Reinsurance workbench (UI coverage batch 20). Same service, same permission, same validation as the API routes; the
 * services keep their maker-checker rules (treaty version activated by someone other than its creator, facultative slip
 * approved by an authority holder who is not the maker, approved-security gate on every participant).
 *   reinsurerCreate      POST reinsurance/reinsurers                         reinsurance.reinsurers.manage           TreatyService::createReinsurer
 *   reinsurerStatus      POST reinsurance/reinsurers/{r}/status              reinsurance.reinsurers.manage           TreatyService::updateReinsurerStatus
 *   reinsurerSecurity    POST reinsurance/reinsurers/{r}/security            reinsurance.reinsurers.approve_security TreatyService::approveSecurity
 *   treatyCreate         POST reinsurance/treaties                           reinsurance.treaties.manage             TreatyService::createTreaty
 *   treatyAddVersion     POST reinsurance/treaties/{t}/versions              reinsurance.treaties.manage             TreatyService::addVersion
 *   treatyActivate       POST reinsurance/treaty-versions/{v}/activate       reinsurance.treaties.approve            TreatyService::activateVersion
 *   treatyThreshold      POST reinsurance/treaties/{t}/large-loss-threshold  reinsurance.treaties.manage             RecoveryService::setLargeLossThreshold
 *   cessionPreview       POST reinsurance/policies/{p}/cessions/preview      reinsurance.cessions.view               CessionService::preview
 *   cessionCede          POST reinsurance/policies/{p}/cessions              reinsurance.cessions.calculate          CessionService::cedePolicy
 *   facCreate            POST reinsurance/facultative                        reinsurance.facultative.manage          FacultativePlacementService::create
 *   facLines             POST reinsurance/facultative/{p}/lines              reinsurance.facultative.manage          FacultativePlacementService::recordWrittenLines
 *   facSubmit            POST reinsurance/facultative/{p}/submit             reinsurance.facultative.manage          FacultativePlacementService::submit
 *   facApprove           POST reinsurance/facultative/{p}/approve            reinsurance.facultative.approve         FacultativePlacementService::approve
 *   facReject            POST reinsurance/facultative/{p}/reject             reinsurance.facultative.approve         FacultativePlacementService::reject
 *   recoveryEstimate     POST reinsurance/claims/{c}/recoveries/estimate     reinsurance.recoveries.manage           RecoveryService::estimate
 *   recoveryNotify       POST reinsurance/recoveries/{r}/notify              reinsurance.recoveries.manage           RecoveryService::notify
 *   recoveryAgree        POST reinsurance/recoveries/{r}/agree               reinsurance.recoveries.approve          RecoveryService::agree
 *   recoveryBill         POST reinsurance/recoveries/{r}/bill                reinsurance.recoveries.bill             RecoveryService::bill
 */
final class ReinsuranceActions
{
    private const L = RiskTransferSupport::L;

    // ---- reinsurers --------------------------------------------------------------------------------------------

    public static function reinsurerCreate(): Action
    {
        $p = 'reinsurance.reinsurers.manage';

        return WorkflowAction::make('reinsurerCreate', $p, self::L)->icon('lucide-plus')
            ->schema([
                TextInput::make('code')->label(RiskTransferSupport::f('code'))->required()->maxLength(64),
                TextInput::make('name')->label(RiskTransferSupport::f('name'))->required()->maxLength(255),
                Select::make('role')->label(RiskTransferSupport::f('role'))->options(RiskTransferSupport::codes(TreatyService::REINSURER_ROLES))->default('REINSURER')->required(),
                TextInput::make('country_code')->label(RiskTransferSupport::f('country_code'))->length(2),
                TextInput::make('rating')->label(RiskTransferSupport::f('rating'))->maxLength(16),
                TextInput::make('rating_agency')->label(RiskTransferSupport::f('rating_agency'))->maxLength(64),
                TextInput::make('regulator')->label(RiskTransferSupport::f('regulator'))->maxLength(128),
                TextInput::make('license_reference')->label(RiskTransferSupport::f('license_reference'))->maxLength(128),
                TextInput::make('website')->label(RiskTransferSupport::f('website'))->url()->maxLength(255),
                TextInput::make('source_url')->label(RiskTransferSupport::f('source_url'))->url()->maxLength(1024),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(TreatyService::class)->createReinsurer(RiskTransferSupport::tenant(), RiskTransferSupport::clean($data)), __(self::L.'.reinsurerCreate.done')));
    }

    public static function reinsurerStatus(): Action
    {
        $p = 'reinsurance.reinsurers.manage';

        return WorkflowAction::make('reinsurerStatus', $p, self::L)->icon('lucide-toggle-right')
            ->schema([
                Select::make('status')->label(RiskTransferSupport::f('status'))->options(RiskTransferSupport::codes(['ACTIVE', 'SUSPENDED', 'INACTIVE']))->required(),
                Textarea::make('reason')->label(RiskTransferSupport::f('reason'))->required()->maxLength(500),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(TreatyService::class)->updateReinsurerStatus(RiskTransferSupport::tenant(), WorkflowAction::id($record), $data['status'], $data['reason']), __(self::L.'.reinsurerStatus.done')));
    }

    public static function reinsurerSecurity(): Action
    {
        $p = 'reinsurance.reinsurers.approve_security';

        return WorkflowAction::make('reinsurerSecurity', $p, self::L)->icon('lucide-shield-check')->color('success')
            ->schema([
                Select::make('approved_security_status')->label(RiskTransferSupport::f('approved_security_status'))->options(RiskTransferSupport::codes(ReinsuranceReference::SECURITY_STATUSES))->required(),
                Textarea::make('reason')->label(RiskTransferSupport::f('reason'))->required()->minLength(5)->maxLength(500),
                TextInput::make('source_url')->label(RiskTransferSupport::f('source_url'))->url()->maxLength(1024),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(TreatyService::class)->approveSecurity(RiskTransferSupport::tenant(), WorkflowAction::id($record), $data['approved_security_status'], $data['reason'], ($data['source_url'] ?? null) ?: null),
                __(self::L.'.reinsurerSecurity.done')));
    }

    // ---- treaties ----------------------------------------------------------------------------------------------

    public static function treatyCreate(): Action
    {
        $p = 'reinsurance.treaties.manage';

        return WorkflowAction::make('treatyCreate', $p, self::L)->icon('lucide-plus')
            ->schema([
                TextInput::make('code')->label(RiskTransferSupport::f('code'))->required()->maxLength(64),
                TextInput::make('name')->label(RiskTransferSupport::f('name'))->required()->maxLength(255),
                Select::make('treaty_type')->label(RiskTransferSupport::f('treaty_type'))->options(RiskTransferSupport::codes(array_keys(ReinsuranceReference::TREATY_FORMS)))->required(),
                TextInput::make('currency')->label(RiskTransferSupport::f('currency'))->length(3)->default('XAF')->required(),
                TextInput::make('underwriting_year')->label(RiskTransferSupport::f('underwriting_year'))->integer()->minValue(1990)->maxValue(2100),
                TextInput::make('treaty_number')->label(RiskTransferSupport::f('treaty_number'))->maxLength(64),
                Select::make('bordereau_frequency')->label(RiskTransferSupport::f('bordereau_frequency'))->options(RiskTransferSupport::codes(ReinsuranceReference::BORDEREAU_FREQUENCIES)),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $d = RiskTransferSupport::clean($data);
                if (isset($d['underwriting_year'])) {
                    $d['underwriting_year'] = (int) $d['underwriting_year'];
                }

                return WorkflowAction::run($action, $p, fn () => app(TreatyService::class)->createTreaty(RiskTransferSupport::tenant(), $d), __(self::L.'.treatyCreate.done'));
            });
    }

    public static function treatyAddVersion(): Action
    {
        $p = 'reinsurance.treaties.manage';

        return WorkflowAction::make('treatyAddVersion', $p, self::L)->icon('lucide-git-branch-plus')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) !== 'CANCELLED')
            ->schema([
                DatePicker::make('effective_from')->label(RiskTransferSupport::f('effective_from'))->required(),
                DatePicker::make('effective_to')->label(RiskTransferSupport::f('effective_to'))->afterOrEqual('effective_from'),
                TextInput::make('cession_percent')->label(RiskTransferSupport::f('cession_percent'))->numeric()->minValue(0)->maxValue(100),
                TextInput::make('retention_minor')->label(RiskTransferSupport::f('retention_minor'))->integer()->minValue(0),
                TextInput::make('lines')->label(RiskTransferSupport::f('lines'))->integer()->minValue(1),
                TextInput::make('commission_percent')->label(RiskTransferSupport::f('commission_percent'))->numeric()->minValue(0)->maxValue(100),
                TextInput::make('brokerage_percent')->label(RiskTransferSupport::f('brokerage_percent'))->numeric()->minValue(0)->maxValue(100),
                TextInput::make('tax_percent')->label(RiskTransferSupport::f('tax_percent'))->numeric()->minValue(0)->maxValue(100),
                Textarea::make('notes')->label(RiskTransferSupport::f('notes'))->maxLength(1000),
                Repeater::make('participants')->label(RiskTransferSupport::f('participants'))->required()->minItems(1)->defaultItems(1)->schema([
                    Select::make('reinsurer_id')->label(RiskTransferSupport::f('reinsurer'))->options(fn () => RiskTransferSupport::reinsurers(excludeBrokers: true))->required(),
                    Select::make('broker_id')->label(RiskTransferSupport::f('broker'))->options(fn () => RiskTransferSupport::reinsurers('REINSURANCE_BROKER')),
                    TextInput::make('share_percent')->label(RiskTransferSupport::f('share_percent'))->numeric()->rule('gt:0')->maxValue(100)->required(),
                    Toggle::make('is_lead')->label(RiskTransferSupport::f('is_lead')),
                ]),
            ])
            ->action(function (Action $action, mixed $record, array $data) use ($p) {
                $d = RiskTransferSupport::clean(collect($data)->except('participants')->all());
                foreach (['retention_minor', 'lines'] as $k) {
                    if (isset($d[$k])) {
                        $d[$k] = (int) $d[$k];
                    }
                }
                $d['participants'] = array_values(array_map(fn ($x) => RiskTransferSupport::clean([...$x, 'is_lead' => (bool) ($x['is_lead'] ?? false)]) + ['is_lead' => false], $data['participants'] ?? []));

                return WorkflowAction::run($action, $p, fn () => app(TreatyService::class)->addVersion(RiskTransferSupport::tenant(), WorkflowAction::id($record), $d), __(self::L.'.treatyAddVersion.done'));
            });
    }

    public static function treatyActivate(): Action
    {
        $p = 'reinsurance.treaties.approve';

        return WorkflowAction::make('treatyActivate', $p, self::L)->icon('lucide-badge-check')->color('success')
            ->visible(fn (mixed $record) => DB::table('reinsurance_treaty_versions')->where('treaty_id', WorkflowAction::id($record))->where('status', 'DRAFT')->exists())
            ->schema(fn (mixed $record) => [
                Select::make('version_id')->label(RiskTransferSupport::f('version'))->required()
                    ->options(DB::table('reinsurance_treaty_versions')->where('tenant_id', RiskTransferSupport::tenant())->where('treaty_id', WorkflowAction::id($record))->where('status', 'DRAFT')
                        ->orderBy('version')->get()->mapWithKeys(fn ($v) => [$v->id => 'v'.$v->version.' · '.substr((string) $v->effective_from, 0, 10)])->all()),
                Textarea::make('reason')->label(RiskTransferSupport::f('reason'))->required()->maxLength(500),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(TreatyService::class)->activateVersion(RiskTransferSupport::tenant(), $data['version_id'], $data['reason']), __(self::L.'.treatyActivate.done')));
    }

    public static function treatyThreshold(): Action
    {
        $p = 'reinsurance.treaties.manage';

        return WorkflowAction::make('treatyThreshold', $p, self::L)->icon('lucide-gauge')
            ->schema([
                TextInput::make('threshold_minor')->label(RiskTransferSupport::f('threshold_minor'))->integer()->minValue(1)
                    ->helperText(__(self::L.'.fields.threshold_blank')),
                Textarea::make('reason')->label(RiskTransferSupport::f('reason'))->required()->maxLength(500),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(RecoveryService::class)->setLargeLossThreshold(RiskTransferSupport::tenant(), WorkflowAction::id($record),
                    ($data['threshold_minor'] ?? '') === '' || $data['threshold_minor'] === null ? null : (int) $data['threshold_minor'], $data['reason']),
                __(self::L.'.treatyThreshold.done')));
    }

    // ---- cessions ----------------------------------------------------------------------------------------------

    public static function cessionPreview(): Action
    {
        $p = 'reinsurance.cessions.view';

        return WorkflowAction::make('cessionPreview', $p, self::L)->icon('lucide-calculator')->color('gray')
            ->schema([RiskTransferSupport::policySelect()->required(), TextInput::make('sum_insured_minor')->label(RiskTransferSupport::f('sum_insured_minor'))->integer()->minValue(0)])
            ->action(function (Action $action, array $data) use ($p) {
                $sum = ($data['sum_insured_minor'] ?? '') === '' || $data['sum_insured_minor'] === null ? null : (int) $data['sum_insured_minor'];
                $out = WorkflowAction::run($action, $p, fn () => app(CessionService::class)->preview(RiskTransferSupport::tenant(), $data['policy_id'], $sum), __(self::L.'.cessionPreview.done'));
                RiskTransferSupport::show(__(self::L.'.cessionPreview.label'), $out);
            });
    }

    public static function cessionCede(): Action
    {
        $p = 'reinsurance.cessions.calculate';

        return WorkflowAction::make('cessionCede', $p, self::L)->icon('lucide-share-2')
            ->schema([RiskTransferSupport::policySelect()->required(), TextInput::make('sum_insured_minor')->label(RiskTransferSupport::f('sum_insured_minor'))->integer()->minValue(0)])
            ->action(function (Action $action, array $data) use ($p) {
                $sum = ($data['sum_insured_minor'] ?? '') === '' || $data['sum_insured_minor'] === null ? null : (int) $data['sum_insured_minor'];

                return WorkflowAction::run($action, $p, fn () => app(CessionService::class)->cedePolicy(RiskTransferSupport::tenant(), $data['policy_id'], $sum), __(self::L.'.cessionCede.done'));
            });
    }

    // ---- facultative -------------------------------------------------------------------------------------------

    public static function facCreate(): Action
    {
        $p = 'reinsurance.facultative.manage';

        return WorkflowAction::make('facCreate', $p, self::L)->icon('lucide-plus')
            ->schema([
                RiskTransferSupport::policySelect()->required(),
                TextInput::make('reference')->label(RiskTransferSupport::f('reference'))->maxLength(64),
                Textarea::make('risk_description')->label(RiskTransferSupport::f('risk_description'))->required()->maxLength(1000),
                TextInput::make('sum_insured_minor')->label(RiskTransferSupport::f('sum_insured_minor'))->integer()->minValue(1),
                TextInput::make('premium_minor')->label(RiskTransferSupport::f('premium_minor'))->integer()->minValue(0),
                TextInput::make('placed_share_percent')->label(RiskTransferSupport::f('placed_share_percent'))->numeric()->rule('gt:0')->maxValue(100)->required(),
                TextInput::make('commission_percent')->label(RiskTransferSupport::f('commission_percent'))->numeric(),
                TextInput::make('brokerage_percent')->label(RiskTransferSupport::f('brokerage_percent'))->numeric(),
                TextInput::make('tax_percent')->label(RiskTransferSupport::f('tax_percent'))->numeric(),
                DatePicker::make('period_from')->label(RiskTransferSupport::f('period_from'))->required(),
                DatePicker::make('period_to')->label(RiskTransferSupport::f('period_to'))->required(),
                Select::make('broker_id')->label(RiskTransferSupport::f('broker'))->options(fn () => RiskTransferSupport::reinsurers('REINSURANCE_BROKER')),
                Repeater::make('participants')->label(RiskTransferSupport::f('participants'))->required()->minItems(1)->defaultItems(1)->schema([
                    Select::make('reinsurer_id')->label(RiskTransferSupport::f('reinsurer'))->options(fn () => RiskTransferSupport::reinsurers(excludeBrokers: true))->required(),
                    TextInput::make('offered_percent')->label(RiskTransferSupport::f('offered_percent'))->numeric()->rule('gt:0')->maxValue(100)->required(),
                    TextInput::make('written_percent')->label(RiskTransferSupport::f('written_percent'))->numeric()->minValue(0)->maxValue(100),
                    Toggle::make('is_lead')->label(RiskTransferSupport::f('is_lead')),
                ]),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $d = RiskTransferSupport::clean(collect($data)->except('participants')->all());
                foreach (['sum_insured_minor', 'premium_minor'] as $k) {
                    if (isset($d[$k])) {
                        $d[$k] = (int) $d[$k];
                    }
                }
                $d['participants'] = array_values(array_map(fn ($x) => RiskTransferSupport::clean($x) + ['is_lead' => (bool) ($x['is_lead'] ?? false)], $data['participants'] ?? []));

                return WorkflowAction::run($action, $p, fn () => app(FacultativePlacementService::class)->create(RiskTransferSupport::tenant(), $d), __(self::L.'.facCreate.done'));
            });
    }

    public static function facLines(): Action
    {
        $p = 'reinsurance.facultative.manage';

        return WorkflowAction::make('facLines', $p, self::L)->icon('lucide-list-checks')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'DRAFT')
            ->fillForm(fn (mixed $record) => ['lines' => DB::table('facultative_participants')->where('placement_id', WorkflowAction::id($record))->orderBy('created_at')->get()
                ->map(fn ($x) => ['reinsurer_id' => $x->reinsurer_id, 'written_percent' => $x->written_percent])->all()])
            ->schema([
                Repeater::make('lines')->label(RiskTransferSupport::f('lines_written'))->required()->minItems(1)->schema([
                    Select::make('reinsurer_id')->label(RiskTransferSupport::f('reinsurer'))->options(fn () => RiskTransferSupport::reinsurers())->required(),
                    TextInput::make('written_percent')->label(RiskTransferSupport::f('written_percent'))->numeric()->minValue(0)->maxValue(100)->required(),
                ]),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(FacultativePlacementService::class)->recordWrittenLines(RiskTransferSupport::tenant(), WorkflowAction::id($record), array_values($data['lines'] ?? [])),
                __(self::L.'.facLines.done')));
    }

    public static function facSubmit(): Action
    {
        $p = 'reinsurance.facultative.manage';

        return WorkflowAction::make('facSubmit', $p, self::L)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'DRAFT')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(FacultativePlacementService::class)->submit(RiskTransferSupport::tenant(), WorkflowAction::id($record)), __(self::L.'.facSubmit.done')));
    }

    public static function facApprove(): Action
    {
        $p = 'reinsurance.facultative.approve';

        return WorkflowAction::make('facApprove', $p, self::L)->icon('lucide-badge-check')->color('success')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'SUBMITTED')
            ->schema([Textarea::make('reason')->label(RiskTransferSupport::f('reason'))->required()->maxLength(500)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(FacultativePlacementService::class)->approve(RiskTransferSupport::tenant(), WorkflowAction::id($record), RiskTransferSupport::user(), $data['reason']), __(self::L.'.facApprove.done')));
    }

    public static function facReject(): Action
    {
        $p = 'reinsurance.facultative.approve';

        return WorkflowAction::make('facReject', $p, self::L)->icon('lucide-circle-x')->color('danger')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'SUBMITTED')
            ->schema([Textarea::make('reason')->label(RiskTransferSupport::f('reason'))->required()->maxLength(500)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(FacultativePlacementService::class)->reject(RiskTransferSupport::tenant(), WorkflowAction::id($record), RiskTransferSupport::user(), $data['reason']), __(self::L.'.facReject.done')));
    }

    // ---- recoveries --------------------------------------------------------------------------------------------

    public static function recoveryEstimate(): Action
    {
        $p = 'reinsurance.recoveries.manage';

        return WorkflowAction::make('recoveryEstimate', $p, self::L)->icon('lucide-calculator')
            ->schema([RiskTransferSupport::claimSelect()->required()])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p,
                fn () => app(RecoveryService::class)->estimate(RiskTransferSupport::tenant(), $data['claim_id'], auth()->id()), __(self::L.'.recoveryEstimate.done')));
    }

    public static function recoveryNotify(): Action
    {
        $p = 'reinsurance.recoveries.manage';

        return WorkflowAction::make('recoveryNotify', $p, self::L)->icon('lucide-bell')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'ESTIMATED')
            ->schema([Textarea::make('note')->label(RiskTransferSupport::f('note'))->maxLength(1000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(RecoveryService::class)->notify(RiskTransferSupport::tenant(), WorkflowAction::id($record), auth()->id(), ($data['note'] ?? null) ?: null), __(self::L.'.recoveryNotify.done')));
    }

    public static function recoveryAgree(): Action
    {
        $p = 'reinsurance.recoveries.approve';

        return WorkflowAction::make('recoveryAgree', $p, self::L)->icon('lucide-handshake')->color('success')
            ->visible(fn (mixed $record) => in_array($record['status'] ?? null, ['NOTIFIED', 'DISPUTED'], true))
            ->schema([
                TextInput::make('amount_minor')->label(RiskTransferSupport::f('amount_minor'))->integer()->minValue(1),
                Textarea::make('note')->label(RiskTransferSupport::f('note'))->maxLength(1000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(RecoveryService::class)->agree(RiskTransferSupport::tenant(), WorkflowAction::id($record),
                    ($data['amount_minor'] ?? '') === '' || $data['amount_minor'] === null ? null : (int) $data['amount_minor'], auth()->id(), ($data['note'] ?? null) ?: null),
                __(self::L.'.recoveryAgree.done')));
    }

    public static function recoveryBill(): Action
    {
        $p = 'reinsurance.recoveries.bill';

        return WorkflowAction::make('recoveryBill', $p, self::L)->icon('lucide-receipt')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'AGREED')
            ->schema([DatePicker::make('due_at')->label(RiskTransferSupport::f('due_at'))->required()])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(RecoveryService::class)->bill(RiskTransferSupport::tenant(), WorkflowAction::id($record), (string) $data['due_at'], auth()->id()), __(self::L.'.recoveryBill.done')));
    }
}
