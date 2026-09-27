<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\PartnerWorkspace\CarrierWorkspaceActions;
use App\Application\Policies\IssuanceQueue\IssuanceException;
use App\Application\Policies\IssuanceQueue\IssuanceQueueService;
use App\Application\Policies\PolicyIssuanceService;
use App\Domain\Tenancy\TenantContext;
use App\Models\PolicyIssuanceRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/**
 * Issuance maker-checker (insurer + admin panels) and the issuance exception queue. Same services / permissions as:
 *   verify              POST mobile/carrier/issuance/{i}/verify               carrier.referrals.decide  PolicyIssuanceService::verify
 *   requestCorrection   POST mobile/carrier/issuance/{i}/request-correction   carrier.referrals.decide  PolicyIssuanceService::requestCorrection
 *   approve             POST mobile/partner/carrier/issuance/{i}/approve      carrier.referrals.decide  CarrierWorkspaceActions::approveIssuance
 *   secondApprove       POST mobile/carrier/issuance/{i}/second-approve       carrier.referrals.decide  PolicyIssuanceService::secondApprove
 *   reject              POST mobile/partner/carrier/issuance/{i}/reject       carrier.referrals.decide  CarrierWorkspaceActions::rejectIssuance
 * (internal staff use policies.issue.approve, the permission of POST policy-issuance-requests/{i}/approve|reject)
 *   exceptionScan       POST issuance-exceptions/scan                 policies.issuance_queue.manage   IssuanceQueueService::scan
 *   exceptionRetry      POST issuance-exceptions/{e}/retry            policies.issuance_queue.manage   IssuanceQueueService::retry
 *   exceptionEscalate   POST issuance-exceptions/{e}/escalate         policies.issuance_queue.manage   IssuanceQueueService::escalate
 *   exceptionResolve    POST issuance-exceptions/{e}/resolve          policies.issuance_queue.resolve  IssuanceQueueService::resolve
 *
 * Labels: resources/lang/{en,fr}/issuance_maker_checker.php. Maker-checker is enforced by the service (and DB constraints);
 * the actions are also hidden when the service would refuse (capabilities()).
 */
final class IssuanceActions
{
    /** WorkflowAction with this feature's own label file. */
    public static function make(string $name, ?string $permission): Action
    {
        $help = __("issuance_maker_checker.actions.{$name}.help");

        return WorkflowAction::make($name, $permission)
            ->label(__("issuance_maker_checker.actions.{$name}.label"))
            ->modalHeading(__("issuance_maker_checker.actions.{$name}.label"))
            ->modalDescription(str_starts_with($help, 'issuance_maker_checker.') ? null : $help);
    }

    public static function done(string $name): string
    {
        return __("issuance_maker_checker.actions.{$name}.done");
    }

    /** The deciding permission the user holds: carrier.referrals.decide (insurer) or policies.issue.approve (internal). */
    public static function decidePermission(): string
    {
        $u = auth()->user();

        return $u && $u->hasPermission('carrier.referrals.decide') ? 'carrier.referrals.decide' : 'policies.issue.approve';
    }

    /** Insurer users act through the carrier workspace (mobile/partner/carrier/issuance/*); internal staff through policy-issuance-requests/*. */
    private static function viaCarrierWorkspace(): bool
    {
        return self::decidePermission() === 'carrier.referrals.decide';
    }

    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::verify(), self::requestCorrection(), self::approve(), self::secondApprove(), self::reject()])
            ->label(__('issuance_maker_checker.group'))->icon('lucide-shield-check')->button();
    }

    private static function can(PolicyIssuanceRequest $r, string $capability): bool
    {
        $u = auth()->user();

        return $u instanceof User && in_array($capability, app(PolicyIssuanceService::class)->capabilities($r, $u, WorkflowAction::allowed(self::decidePermission())), true);
    }

    public static function verify(): Action
    {
        return self::make('issuanceVerify', self::decidePermission())->icon('lucide-badge-check')
            ->visible(fn (PolicyIssuanceRequest $record) => self::can($record, 'verify'))
            ->schema([Textarea::make('notes')->label(__('issuance_maker_checker.fields.notes'))->maxLength(2000)])
            ->action(fn (Action $action, PolicyIssuanceRequest $record, array $data) => WorkflowAction::run($action, self::decidePermission(),
                fn () => app(PolicyIssuanceService::class)->verify($record, auth()->user(), filled($data['notes'] ?? null) ? $data['notes'] : null), self::done('issuanceVerify')));
    }

    public static function requestCorrection(): Action
    {
        return self::make('issuanceRequestCorrection', self::decidePermission())->icon('lucide-undo-2')->color('warning')
            ->visible(fn (PolicyIssuanceRequest $record) => self::can($record, 'request_correction'))
            ->schema([Textarea::make('reason')->label(__('issuance_maker_checker.fields.reason'))->required()->minLength(5)->maxLength(2000)])
            ->action(fn (Action $action, PolicyIssuanceRequest $record, array $data) => WorkflowAction::run($action, self::decidePermission(),
                fn () => app(PolicyIssuanceService::class)->requestCorrection($record, $data['reason'], auth()->user()), self::done('issuanceRequestCorrection')));
    }

    public static function approve(): Action
    {
        return self::make('issuanceApprove', self::decidePermission())->icon('lucide-shield-check')->color('success')
            ->visible(fn (PolicyIssuanceRequest $record) => self::can($record, 'approve'))
            ->schema([
                TextInput::make('carrier_reference')->label(__('issuance_maker_checker.fields.carrier_reference'))->maxLength(64),
                TextInput::make('previous_policy_id')->label(__('issuance_maker_checker.fields.previous_policy_id'))->uuid(),
            ])
            ->action(fn (Action $action, PolicyIssuanceRequest $record, array $data) => WorkflowAction::run($action, self::decidePermission(),
                fn () => self::viaCarrierWorkspace()
                    ? app(CarrierWorkspaceActions::class)->approveIssuance($record, array_filter($data, fn ($v) => filled($v)), auth()->user())
                    : app(PolicyIssuanceService::class)->approve($record, ['carrier_reference' => filled($data['carrier_reference'] ?? null) ? $data['carrier_reference'] : 'INS-'.strtoupper(\Illuminate\Support\Str::random(10))] + array_filter($data, fn ($v) => filled($v)), auth()->user()),
                self::done('issuanceApprove')));
    }

    public static function secondApprove(): Action
    {
        return self::make('issuanceSecondApprove', self::decidePermission())->icon('lucide-shield-check')->color('success')->requiresConfirmation()
            ->visible(fn (PolicyIssuanceRequest $record) => self::can($record, 'second_approve'))
            ->schema([TextInput::make('carrier_reference')->label(__('issuance_maker_checker.fields.carrier_reference'))->maxLength(64)])
            ->action(fn (Action $action, PolicyIssuanceRequest $record, array $data) => WorkflowAction::run($action, self::decidePermission(),
                fn () => app(PolicyIssuanceService::class)->secondApprove($record, array_filter($data, fn ($v) => filled($v)), auth()->user()), self::done('issuanceSecondApprove')));
    }

    public static function reject(): Action
    {
        return self::make('issuanceReject', self::decidePermission())->icon('lucide-circle-x')->color('danger')
            ->visible(fn (PolicyIssuanceRequest $record) => self::can($record, 'reject'))
            ->schema([Textarea::make('reason')->label(__('issuance_maker_checker.fields.reason'))->required()->minLength(5)->maxLength(2000)])
            ->action(fn (Action $action, PolicyIssuanceRequest $record, array $data) => WorkflowAction::run($action, self::decidePermission(),
                fn () => self::viaCarrierWorkspace()
                    ? app(CarrierWorkspaceActions::class)->rejectIssuance($record, $data['reason'], auth()->user())
                    : app(PolicyIssuanceService::class)->reject($record, $data['reason'], auth()->user()),
                self::done('issuanceReject')));
    }

    // ------------------------------------------------------------------ issuance exceptions (REQ-POL-004)

    public static function exceptionGroup(): ActionGroup
    {
        return ActionGroup::make([self::exceptionRetry(), self::exceptionEscalate(), self::exceptionResolve()])
            ->label(__('issuance_maker_checker.exception_group'))->icon('lucide-zap')->button();
    }

    public static function exceptionScan(): Action
    {
        $p = 'policies.issuance_queue.manage';

        return self::make('exceptionScan', $p)->icon('lucide-scan-search')
            ->schema([
                TextInput::make('grace_minutes')->label(__('issuance_maker_checker.fields.grace_minutes'))->integer()->minValue(0)->maxValue(10080)->default(30)->required(),
                TextInput::make('review_hours')->label(__('issuance_maker_checker.fields.review_hours'))->integer()->minValue(1)->maxValue(720)->default(48)->required(),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $found = WorkflowAction::run($action, $p, fn () => app(IssuanceQueueService::class)->scan((string) app(TenantContext::class)->id(), (int) $data['grace_minutes'], (int) $data['review_hours']),
                    self::done('exceptionScan'));
                \Filament\Notifications\Notification::make()->info()->title(__('issuance_maker_checker.scan_found', ['count' => count($found ?? [])]))->send();
            });
    }

    public static function exceptionRetry(): Action
    {
        $p = 'policies.issuance_queue.manage';

        return self::make('exceptionRetry', $p)->icon('lucide-rotate-cw')->requiresConfirmation()
            ->visible(fn (IssuanceException $record) => $record->status !== 'RESOLVED')
            ->action(fn (Action $action, IssuanceException $record) => WorkflowAction::run($action, $p, fn () => app(IssuanceQueueService::class)->retry($record, auth()->user()), self::done('exceptionRetry')));
    }

    public static function exceptionEscalate(): Action
    {
        $p = 'policies.issuance_queue.manage';

        return self::make('exceptionEscalate', $p)->icon('lucide-arrow-up-right')->color('warning')
            ->visible(fn (IssuanceException $record) => $record->status !== 'RESOLVED')
            ->schema([
                Textarea::make('reason')->label(__('issuance_maker_checker.fields.reason'))->required()->maxLength(1000),
                Select::make('escalated_to')->label(__('issuance_maker_checker.fields.escalated_to'))->searchable()
                    ->options(fn () => User::whereIn('id', DB::table('tenant_memberships')->where(['tenant_id' => app(TenantContext::class)->id(), 'status' => 'ACTIVE'])->select('user_id'))->orderBy('full_name')->limit(500)->pluck('full_name', 'id')),
            ])
            ->action(fn (Action $action, IssuanceException $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(IssuanceQueueService::class)->escalate($record, auth()->user(), $data['reason'], $data['escalated_to'] ?? null), self::done('exceptionEscalate')));
    }

    public static function exceptionResolve(): Action
    {
        $p = 'policies.issuance_queue.resolve';

        return self::make('exceptionResolve', $p)->icon('lucide-circle-check')->color('success')->requiresConfirmation()
            ->visible(fn (IssuanceException $record) => $record->status !== 'RESOLVED')
            ->schema([
                Select::make('resolution')->label(__('issuance_maker_checker.fields.resolution'))->required()
                    ->options(collect(IssuanceQueueService::RESOLUTIONS)->mapWithKeys(fn ($v) => [$v => __("issuance_maker_checker.codes.resolution.{$v}")])->all()),
                Textarea::make('notes')->label(__('issuance_maker_checker.fields.notes'))->required()->maxLength(2000),
            ])
            ->action(fn (Action $action, IssuanceException $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(IssuanceQueueService::class)->resolve($record, auth()->user(), $data['resolution'], $data['notes']), self::done('exceptionResolve')));
    }
}
