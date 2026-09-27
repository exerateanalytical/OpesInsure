<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Customers\Beneficiaries\BeneficiaryService;
use App\Application\Finance\PremiumStatus\PremiumComponentService;
use App\Application\Health\Eligibility\HealthMemberService;
use App\Application\Policies\Lapse\PolicyRecoveryService;
use App\Application\Policies\Portability\PolicyPortfolioTransferService;
use App\Application\Policies\Special\CargoDeclarationService;
use App\Application\Policies\Special\LifeSurrenderService;
use App\Application\Policies\Special\PolicyScheduleService;
use App\Application\Policies\Suspension\PolicySuspensionService;
use App\Application\Stickers\StickerCustodyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use App\Models\StickerStock;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\DB;

/**
 * Policy operations (mount on the policy detail page; insurer and broker panels reuse PolicyResource). Same services / permissions as:
 *   policyReplaceBeneficiaries PUT  policies/{p}/beneficiaries                 beneficiaries.manage              BeneficiaryService::replace
 *   policyDeclareCargo         POST policies/{p}/cargo-declarations            cargo_declarations.declare        CargoDeclarationService::declare
 *   policyEnrolHealthMember    POST policies/{p}/health-members                health.members.manage             HealthMemberService::enrol
 *   policyLinkHealthNetwork    POST policies/{p}/health-networks               health.members.manage             HealthMemberService::linkNetwork
 *   policyPremiumComponents    POST policies/{p}/premium-components            premium_components.manage         PremiumComponentService::policy|captureFromTerms|record
 *   policyAddScheduleItem      POST policies/{p}/schedule-items                special_policies.schedule.manage  PolicyScheduleService::addItem
 *   policySpecialProfile       POST policies/{p}/special-profile               special_policies.manage           PolicyScheduleService::createProfile
 *   policyAssignSticker        POST policies/{p}/sticker                       stickers.assign                   StickerCustodyService::assignToPolicy
 *   policySurrenderQuote       POST policies/{p}/surrender-quotes              life_surrender.quote              LifeSurrenderService::quote
 *   policySuspend              POST policies/{p}/suspend                       policies.suspend                  PolicySuspensionService::suspend
 *   policyTransferDecide       POST policy-portfolio-transfers/{t}/approve|reject          policies.portfolio_transfer.approve  PolicyPortfolioTransferService::approve|reject
 *   policyTransferConsent      POST policy-portfolio-transfers/{t}/policies/{p}/consent    policies.portfolio_transfer.request  PolicyPortfolioTransferService::recordConsent
 *   policySettleInstalment     POST policy-premium-instalments/{i}/settle      policy.recovery.request           PolicyRecoveryService::settleInstalment
 *   policyWaiveInstalment      POST policy-premium-instalments/{i}/waive       policy.premium.waive              PolicyRecoveryService::waiveInstalment
 *   policyRecoveryDecide       POST policy-recovery-cases/{c}/approve|reject   policy.recovery.approve           PolicyRecoveryService::approve|reject
 */
final class PolicyOperationsActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make(self::all())->label(__('workflow_actions.policy_operations_group'))->icon('lucide-settings-2')->button();
    }

    /** @return list<Action> */
    public static function all(): array
    {
        return [
            self::suspend(), self::replaceBeneficiaries(), self::premiumComponents(), self::assignSticker(), self::specialProfile(), self::addScheduleItem(),
            self::declareCargo(), self::enrolHealthMember(), self::linkHealthNetwork(), self::surrenderQuote(), self::transferDecide(), self::transferConsent(),
            self::settleInstalment(), self::waiveInstalment(), self::recoveryDecide(),
        ];
    }

    private static function tenant(): string
    {
        return (string) app(TenantContext::class)->id();
    }

    private static function f(string $key): string
    {
        return __("workflow_actions.policy_ops_fields.{$key}");
    }

    public static function suspend(): Action
    {
        $p = 'policies.suspend';

        return WorkflowAction::make('policySuspend', $p)->icon('lucide-pause-circle')->color('warning')->requiresConfirmation()
            ->visible(fn (Policy $record) => $record->status === 'ACTIVE')
            ->schema([
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                DateTimePicker::make('effective_at')->label(__('workflow_actions.fields.effective_at')),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PolicySuspensionService::class)->suspend(
                $record, $data['reason_code'], auth()->user(), ['source' => 'MANUAL', 'notes' => $data['notes'] ?? null, 'effective_at' => $data['effective_at'] ?? null])));
    }

    public static function replaceBeneficiaries(): Action
    {
        $p = 'beneficiaries.manage';

        return WorkflowAction::make('policyReplaceBeneficiaries', $p)->icon('lucide-users')
            ->fillForm(fn (Policy $record) => ['beneficiaries' => app(BeneficiaryService::class)->current($record)->map(fn ($b) => [
                'designation' => $b->designation, 'full_name' => $b->full_name ?? null, 'relationship' => $b->relationship ?? null,
                'date_of_birth' => $b->date_of_birth ?? null, 'allocation_pct' => $b->allocation_pct, 'revocable' => (bool) ($b->revocable ?? true),
            ])->values()->all()])
            ->schema([
                Repeater::make('beneficiaries')->label(self::f('beneficiaries'))->maxItems(20)->columns(3)->schema([
                    Select::make('designation')->label(self::f('designation'))->required()->options(WorkflowAction::options(BeneficiaryService::DESIGNATIONS)),
                    TextInput::make('full_name')->label(self::f('full_name'))->required()->maxLength(160),
                    TextInput::make('relationship')->label(self::f('relationship'))->maxLength(32),
                    DatePicker::make('date_of_birth')->label(self::f('date_of_birth'))->maxDate(now()),
                    TextInput::make('allocation_pct')->label(self::f('allocation_pct'))->numeric()->required()->minValue(0)->maxValue(100),
                    Toggle::make('revocable')->label(self::f('revocable'))->default(true),
                ]),
                TextInput::make('reason')->label(__('workflow_actions.fields.reason'))->required()->maxLength(255),
                TextInput::make('irrevocable_consent_reference')->label(self::f('irrevocable_consent_reference'))->maxLength(120),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p, fn () => app(BeneficiaryService::class)->replace(
                $record, array_values($data['beneficiaries'] ?? []), $data['reason'], filled($data['irrevocable_consent_reference'] ?? null) ? $data['irrevocable_consent_reference'] : null, auth()->user())));
    }

    public static function premiumComponents(): Action
    {
        $p = 'premium_components.manage';

        return WorkflowAction::make('policyPremiumComponents', $p)->icon('lucide-list-plus')
            ->schema([
                Toggle::make('from_terms')->label(self::f('from_terms'))->default(true)->live(),
                Repeater::make('components')->label(self::f('components'))->maxItems(50)->columns(3)->visible(fn (callable $get) => ! $get('from_terms'))->schema([
                    TextInput::make('line_key')->label(self::f('line_key'))->required()->maxLength(64),
                    Select::make('component')->label(self::f('component'))->required()->options(WorkflowAction::options(PremiumComponentService::COMPONENTS)),
                    TextInput::make('amount_minor')->label(self::f('amount_minor'))->integer()->required(),
                    DatePicker::make('due_at')->label(self::f('due_at')),
                ]),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $svc = app(PremiumComponentService::class);
                $policy = $svc->policy($record->id, self::tenant());

                return ! empty($data['from_terms'])
                    ? $svc->captureFromTerms($policy, auth()->user())
                    : $svc->record($policy, array_map(fn ($c) => ['amount_minor' => (int) $c['amount_minor']] + array_filter($c, fn ($v) => $v !== null), array_values($data['components'] ?? [])), 'MANUAL', auth()->user());
            }));
    }

    public static function assignSticker(): Action
    {
        $p = 'stickers.assign';

        return WorkflowAction::make('policyAssignSticker', $p)->icon('lucide-sticker')
            ->visible(fn (Policy $record) => $record->status === 'ACTIVE')
            ->schema([
                Select::make('serial_number')->label(self::f('serial_number'))->required()->searchable()
                    ->options(fn (Policy $record) => StickerStock::where(['carrier_id' => $record->carrier_id, 'custodian_tenant_id' => $record->tenant_id, 'status' => 'IN_STOCK'])
                        ->limit(500)->pluck('serial_number', 'serial_number')->all()),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(StickerCustodyService::class)->assignToPolicy($record, $data['serial_number'], auth()->user())));
    }

    public static function specialProfile(): Action
    {
        $p = 'special_policies.manage';

        return WorkflowAction::make('policySpecialProfile', $p)->icon('lucide-layers')
            ->visible(fn (Policy $record) => ! DB::table('special_policy_profiles')->where('policy_id', $record->id)->exists())
            ->schema([
                Select::make('kind')->label(self::f('kind'))->required()->options(WorkflowAction::options(PolicyScheduleService::KINDS)),
                KeyValue::make('terms')->label(self::f('terms')),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PolicyScheduleService::class)->createProfile(self::tenant(), $record->id, $data['kind'], self::terms($data['terms'] ?? []), auth()->user())));
    }

    public static function addScheduleItem(): Action
    {
        $p = 'special_policies.schedule.manage';

        return WorkflowAction::make('policyAddScheduleItem', $p)->icon('lucide-list-plus')
            ->visible(fn (Policy $record) => DB::table('special_policy_profiles')->where('policy_id', $record->id)->exists())
            ->schema([
                TextInput::make('item_type')->label(self::f('item_type'))->maxLength(24),
                TextInput::make('item_key')->label(self::f('item_key'))->required()->maxLength(100),
                TextInput::make('display_name')->label(self::f('display_name'))->required()->maxLength(191),
                TextInput::make('category')->label(self::f('category'))->maxLength(64),
                TextInput::make('sum_insured_minor')->label(self::f('sum_insured_minor'))->integer()->minValue(0),
                TextInput::make('annual_premium_minor')->label(self::f('annual_premium_minor'))->integer()->minValue(0),
                DatePicker::make('effective_from')->label(self::f('effective_from'))->required()->default(now()),
                KeyValue::make('facts')->label(self::f('facts')),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PolicyScheduleService::class)->addItem(self::tenant(), $record->id, self::clean($data), auth()->user())));
    }

    public static function declareCargo(): Action
    {
        $p = 'cargo_declarations.declare';

        return WorkflowAction::make('policyDeclareCargo', $p)->icon('lucide-ship')
            ->schema([
                Select::make('conveyance')->label(self::f('conveyance'))->required()->options(WorkflowAction::options(CargoDeclarationService::CONVEYANCES)),
                Textarea::make('goods_description')->label(self::f('goods_description'))->required()->maxLength(500),
                TextInput::make('origin')->label(self::f('origin'))->required()->maxLength(120),
                TextInput::make('destination')->label(self::f('destination'))->required()->maxLength(120),
                DatePicker::make('shipment_date')->label(self::f('shipment_date'))->required(),
                TextInput::make('insured_value_minor')->label(self::f('insured_value_minor'))->integer()->required()->minValue(1),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CargoDeclarationService::class)->declare(self::tenant(), $record->id, ['insured_value_minor' => (int) $data['insured_value_minor']] + $data, auth()->user())));
    }

    public static function enrolHealthMember(): Action
    {
        $p = 'health.members.manage';

        return WorkflowAction::make('policyEnrolHealthMember', $p)->icon('lucide-user-plus')
            ->schema([
                Select::make('relationship')->label(self::f('relationship'))->required()->options(WorkflowAction::options(HealthMemberService::RELATIONSHIPS)),
                TextInput::make('display_name')->label(self::f('full_name'))->maxLength(191),
                Select::make('principal_member_id')->label(self::f('principal_member'))->searchable()
                    ->options(fn (Policy $record) => DB::table('health_members')->where('policy_id', $record->id)->where('relationship', 'PRINCIPAL')->where('status', '<>', 'ENDED')->pluck('display_name', 'id')->all()),
                DatePicker::make('date_of_birth')->label(self::f('date_of_birth')),
                DatePicker::make('effective_from')->label(self::f('effective_from')),
                DatePicker::make('effective_to')->label(self::f('effective_to')),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(HealthMemberService::class)->enrol(self::tenant(), $record->id, self::clean($data), auth()->id())));
    }

    public static function linkHealthNetwork(): Action
    {
        $p = 'health.members.manage';

        return WorkflowAction::make('policyLinkHealthNetwork', $p)->icon('lucide-network')
            ->schema([
                Select::make('provider_network_id')->label(self::f('provider_network'))->required()->searchable()
                    ->options(fn (Policy $record) => DB::table('provider_networks')->where('tenant_id', $record->tenant_id)->where('status', 'ACTIVE')->pluck('name', 'id')->all()),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(HealthMemberService::class)->linkNetwork(self::tenant(), $record->id, $data['provider_network_id'], auth()->id())));
    }

    public static function surrenderQuote(): Action
    {
        $p = 'life_surrender.quote';

        return WorkflowAction::make('policySurrenderQuote', $p)->icon('lucide-calculator')
            ->schema([
                DatePicker::make('as_of')->label(self::f('as_of')),
                TextInput::make('basis_minor')->label(self::f('basis_minor'))->integer()->required()->minValue(0),
                TextInput::make('loans_outstanding_minor')->label(self::f('loans_outstanding_minor'))->integer()->minValue(0),
                TextInput::make('reason')->label(__('workflow_actions.fields.reason'))->maxLength(64),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(LifeSurrenderService::class)->quote(self::tenant(), $record->id, self::clean($data), auth()->user())));
    }

    public static function transferDecide(): Action
    {
        $p = 'policies.portfolio_transfer.approve';

        return WorkflowAction::make('policyTransferDecide', $p)->icon('lucide-arrow-left-right')->requiresConfirmation()
            ->visible(fn (Policy $record) => self::transfers($record, 'PENDING_APPROVAL') !== [])
            ->schema([
                Select::make('transfer_id')->label(self::f('transfer'))->required()->options(fn (Policy $record) => self::transfers($record, 'PENDING_APPROVAL')),
                Select::make('outcome')->label(__('workflow_actions.fields.outcome'))->required()->options(['APPROVE' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject')])->live(),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000)->minLength(fn (callable $get) => $get('outcome') === 'REJECT' ? 3 : null)
                    ->required(fn (callable $get) => $get('outcome') === 'REJECT'),
            ])
            ->action(function (Action $action, Policy $record, array $data) use ($p) {
                $svc = app(PolicyPortfolioTransferService::class);

                return WorkflowAction::run($action, $p, fn () => $data['outcome'] === 'APPROVE'
                    ? $svc->approve(self::tenant(), $data['transfer_id'], filled($data['notes'] ?? null) ? $data['notes'] : null, auth()->user())
                    : $svc->reject(self::tenant(), $data['transfer_id'], (string) $data['notes'], auth()->user()));
            });
    }

    public static function transferConsent(): Action
    {
        $p = 'policies.portfolio_transfer.request';

        return WorkflowAction::make('policyTransferConsent', $p)->icon('lucide-file-signature')
            ->visible(fn (Policy $record) => self::transfers($record, 'AWAITING_CONSENT') !== [])
            ->schema([
                Select::make('transfer_id')->label(self::f('transfer'))->required()->options(fn (Policy $record) => self::transfers($record, 'AWAITING_CONSENT')),
                Toggle::make('granted')->label(self::f('consent_granted'))->default(true),
                TextInput::make('evidence')->label(self::f('evidence'))->required()->minLength(3)->maxLength(255),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PolicyPortfolioTransferService::class)->recordConsent(
                self::tenant(), $data['transfer_id'], $record->id, (bool) $data['granted'], $data['evidence'], auth()->user())));
    }

    public static function settleInstalment(): Action
    {
        $p = 'policy.recovery.request';

        return WorkflowAction::make('policySettleInstalment', $p)->icon('lucide-banknote')
            ->visible(fn (Policy $record) => self::openInstalments($record) !== [])
            ->schema([
                Select::make('instalment_id')->label(self::f('instalment'))->required()->options(fn (Policy $record) => self::openInstalments($record)),
                TextInput::make('amount_minor')->label(self::f('amount_minor'))->integer()->required()->minValue(1),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PolicyRecoveryService::class)->settleInstalment(self::instalmentOf($record, $data['instalment_id']), (int) $data['amount_minor'])));
    }

    public static function waiveInstalment(): Action
    {
        $p = 'policy.premium.waive';

        return WorkflowAction::make('policyWaiveInstalment', $p)->icon('lucide-badge-minus')->requiresConfirmation()
            ->visible(fn (Policy $record) => self::openInstalments($record) !== [])
            ->schema([
                Select::make('instalment_id')->label(self::f('instalment'))->required()->options(fn (Policy $record) => self::openInstalments($record)),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->maxLength(2000),
            ])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(PolicyRecoveryService::class)->waiveInstalment(self::instalmentOf($record, $data['instalment_id']), $data['reason'], auth()->user())));
    }

    public static function recoveryDecide(): Action
    {
        $p = 'policy.recovery.approve';
        $open = fn (Policy $record) => DB::table('policy_recovery_cases')->where(['tenant_id' => self::tenant(), 'policy_id' => $record->id, 'status' => 'OPEN'])->pluck('case_number', 'id')->all();

        return WorkflowAction::make('policyRecoveryDecide', $p)->icon('lucide-badge-check')->requiresConfirmation()
            ->visible(fn (Policy $record) => $open($record) !== [])
            ->schema([
                Select::make('case_id')->label(self::f('recovery_case'))->required()->options($open),
                Select::make('outcome')->label(__('workflow_actions.fields.outcome'))->required()->options(['APPROVE' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject')])->live(),
                DatePicker::make('new_coverage_ends_at')->label(__('workflow_actions.fields.new_coverage_ends_at'))->visible(fn (callable $get) => $get('outcome') === 'APPROVE'),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->maxLength(2000)->required(fn (callable $get) => $get('outcome') === 'REJECT'),
            ])
            ->action(function (Action $action, Policy $record, array $data) use ($p, $open) {
                abort_unless(array_key_exists($data['case_id'], $open($record)), 404);
                $svc = app(PolicyRecoveryService::class);

                return WorkflowAction::run($action, $p, fn () => $data['outcome'] === 'APPROVE'
                    ? $svc->approve($data['case_id'], auth()->user(), $data['new_coverage_ends_at'] ?? null)
                    : $svc->reject($data['case_id'], auth()->user(), (string) $data['reason']));
            });
    }

    /** @return array<string, string> transfers of the given status that include this policy (and, for consent, whose item still awaits it). */
    private static function transfers(Policy $policy, string $status): array
    {
        return DB::table('policy_portfolio_transfers as t')->join('policy_portfolio_transfer_items as i', 'i.transfer_id', '=', 't.id')
            ->where('t.tenant_id', self::tenant())->where('i.policy_id', $policy->id)->where('t.status', $status)
            ->when($status === 'AWAITING_CONSENT', fn ($q) => $q->where('i.status', 'AWAITING_CONSENT'))
            ->orderByDesc('t.created_at')->get(['t.id', 't.scope', 't.reason_code', 't.created_at'])
            ->mapWithKeys(fn ($t) => [$t->id => "{$t->scope} · {$t->reason_code} · ".substr((string) $t->created_at, 0, 10)])->all();
    }

    /** @return array<string, string> */
    private static function openInstalments(Policy $policy): array
    {
        return DB::table('policy_premium_instalments')->where(['tenant_id' => self::tenant(), 'policy_id' => $policy->id])->whereNotIn('status', ['PAID', 'WAIVED'])
            ->orderBy('due_date')->get()->mapWithKeys(fn ($i) => [$i->id => "{$i->due_date} · ".number_format((int) $i->amount_minor - (int) $i->paid_minor)." {$i->currency} · {$i->status}"])->all();
    }

    /** Tenant + policy scoping, as the controller's instalmentId(). */
    private static function instalmentOf(Policy $policy, string $id): string
    {
        abort_unless(DB::table('policy_premium_instalments')->where(['tenant_id' => self::tenant(), 'policy_id' => $policy->id, 'id' => $id])->exists(), 404);

        return $id;
    }

    /** KeyValue gives strings: integers become ints, `conveyances` a list (the API takes typed JSON). */
    private static function terms(array $terms): array
    {
        return collect($terms)->mapWithKeys(fn ($v, $k) => [$k => match (true) {
            $k === 'conveyances' => array_values(array_filter(array_map(fn ($c) => strtoupper(trim($c)), explode(',', (string) $v)))),
            is_string($v) && preg_match('/^-?\d+$/', $v) === 1 => (int) $v,
            default => $v,
        }])->all();
    }

    private static function clean(array $data): array
    {
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
        foreach ($data as $k => $v) {
            if (str_ends_with((string) $k, '_minor') && is_numeric($v)) {
                $data[$k] = (int) $v;
            }
        }

        return $data;
    }
}
