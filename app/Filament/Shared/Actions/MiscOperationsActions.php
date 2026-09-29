<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Claims\Recovery\Litigation\LegalMatterService;
use App\Application\Finance\PremiumStatus\PremiumComponentService;
use App\Application\Fraud\ClaimFraudIndicatorService;
use App\Application\Health\Eligibility\HealthCardService;
use App\Application\Health\Eligibility\HealthMemberService;
use App\Application\Logistics\Adapters\CourierAdapterRegistry;
use App\Application\MarketData\MarketDataGates;
use App\Application\Operations\CommunicationLogService;
use App\Application\Partners\AgentHierarchyService;
use App\Application\Policies\Special\CargoDeclarationService;
use App\Application\Policies\Special\PolicyScheduleService;
use App\Application\PrivateOnboarding\PrivateOnboardingService;
use App\Application\Providers\ProviderNetworkService;
use App\Domain\Logistics\FulfilmentStateMachine;
use App\Domain\Support\TicketStateMachine;
use App\Interfaces\Http\Controllers\Api\V1\Logistics\FulfilmentController;
use App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileBrokerOpsController;
use App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace\PartnerCarrierWorkspaceController;
use App\Interfaces\Http\Controllers\Api\V1\Support\SupportController;
use App\Models\Claim;
use App\Models\Partner;
use App\Models\RiskAlert;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Operations desk actions. Same permission + same service (or the API controller itself via ControllerCall where the
 * route's logic is inline) as the API:
 *   fulfilmentCreate          POST fulfilment-orders (alias fulfilments)       fulfilments.manage (alias permission; canonical route has none) FulfilmentController@store
 *   courierAvailability       POST fulfilments/courier-availability          fulfilments.manage          CourierAdapterRegistry::for('INTERNAL')->checkAvailability
 *   fulfilmentTransition      POST fulfilment-orders/{order}/transitions     fulfilment.transition       FulfilmentStateMachine::assert + FulfilmentController@transition
 *   providerNetworkCreate     POST provider-networks                         provider_networks.manage    ProviderNetworkService::createNetwork
 *   providerContractAdd       POST provider-networks/{network}/contracts     provider_networks.manage    ProviderNetworkService::createContract
 *   providerMemberAdd         POST provider-networks/{network}/members       provider_networks.manage    ProviderNetworkService::addMember
 *   providerTariffDraft       POST provider-contracts/{contract}/tariffs     provider_networks.manage    ProviderNetworkService::draftTariff
 *   providerMembershipEnd     POST provider-network-memberships/{m}/end      provider_networks.manage    ProviderNetworkService::endMembership
 *   providerTariffApprove     POST provider-tariffs/{tariff}/approve         provider_tariffs.approve    ProviderNetworkService::approveTariff (maker-checker in service)
 *   fraudClaimAssess          POST fraud/claims/{claim}/assess               fraud.alert.create          ClaimFraudIndicatorService::assess
 *   fraudClaimOutcome         POST fraud/claim-reviews/{alert}/outcome       fraud.alert.decide          ClaimFraudIndicatorService::recordOutcome
 *   healthCardIssue           POST health-members/{member}/card              health.cards.issue          HealthCardService::issue
 *   healthMemberEnd           POST health-members/{member}/end               health.members.manage       HealthMemberService::end
 *   legalDeadlineComplete     POST legal-deadlines/{deadline}/complete       legal.matters.manage        LegalMatterService::completeDeadline
 *   legalHearingRecord        POST legal-hearings/{hearing}/record           legal.matters.manage        LegalMatterService::recordHearing
 *   agreementVerifySource     POST market-data/carrier-broker-agreements/{a}/verify-source distribution.agreements.approve MarketDataGates::verifyAgreementSource
 *   onboardingRecordReview    POST onboarding/private-records/{r}/review     onboarding.private_data.review PrivateOnboardingService::review
 *   scheduleItemRemove        POST policy-schedule-items/{item}/remove       special_policies.schedule.manage PolicyScheduleService::removeItem
 *   premiumComponentClose     POST premium-components/{c}/close              premium_components.close    PremiumComponentService::close
 *   cargoDeclarationCancel    POST cargo-declarations/{d}/cancel             cargo_declarations.cancel   CargoDeclarationService::cancel
 *   partnerPlace              PATCH partners/{partner}/hierarchy             partners.manage             AgentHierarchyService::place
 *   partnerSuspend            POST partners/{partner}/suspension             partners.manage             AgentHierarchyService::suspend
 *   supportTicketCreate       POST support-tickets (alias support/tickets)   support.manage (alias permission) SupportController@store
 *   supportTicketTransition   POST support-tickets/{ticket}/transitions      support.manage              TicketStateMachine::assert + SupportController@transition
 *   communicationLog          POST communications/logs                       communications.manage       CommunicationLogService::record
 *   marketplaceToggle         PATCH mobile/broker/marketplace-publications/{id} broker.marketplace.manage MobileBrokerOpsController@togglePublication
 *   carrierProductStatus      POST mobile/partner/carrier/products/{p}/status carrier.authority.manage   PartnerCarrierWorkspaceController@productStatus (CarrierWorkspaceActions::setProductActive)
 */
final class MiscOperationsActions
{
    public static function groups(): array
    {
        $g = fn (string $key, string $icon, array $actions) => ActionGroup::make($actions)->label(__(MiscSupport::L.'.groups.'.$key))->icon($icon)->button()->color('gray');

        return [
            $g('fulfilment', 'lucide-truck', [self::fulfilmentCreate(), self::courierAvailability(), self::fulfilmentTransition()]),
            $g('providers', 'lucide-hospital', [self::providerNetworkCreate(), self::providerContractAdd(), self::providerMemberAdd(), self::providerTariffDraft(), self::providerMembershipEnd(), self::providerTariffApprove()]),
            $g('health_fraud', 'lucide-shield-alert', [self::fraudClaimAssess(), self::fraudClaimOutcome(), self::healthCardIssue(), self::healthMemberEnd()]),
            $g('legal_policies', 'lucide-gavel', [self::legalDeadlineComplete(), self::legalHearingRecord(), self::scheduleItemRemove(), self::premiumComponentClose(), self::cargoDeclarationCancel()]),
            $g('distribution', 'lucide-handshake', [self::agreementVerifySource(), self::onboardingRecordReview(), self::partnerPlace(), self::partnerSuspend(), self::marketplaceToggle(), self::carrierProductStatus()]),
            $g('support', 'lucide-messages-square', [self::supportTicketCreate(), self::supportTicketTransition(), self::communicationLog()]),
        ];
    }

    public static function permissions(): array
    {
        return ['fulfilments.manage', 'fulfilment.transition', 'provider_networks.manage', 'provider_tariffs.approve', 'fraud.alert.create', 'fraud.alert.decide',
            'health.cards.issue', 'health.members.manage', 'legal.matters.manage', 'distribution.agreements.approve', 'onboarding.private_data.review',
            'special_policies.schedule.manage', 'premium_components.close', 'cargo_declarations.cancel', 'partners.manage', 'support.manage',
            'communications.manage', 'broker.marketplace.manage', 'carrier.authority.manage'];
    }

    private static function f(string $k): string
    {
        return MiscSupport::f($k);
    }

    private static function reason(int $min = 5, int $max = 2000): Textarea
    {
        return Textarea::make('reason')->label(self::f('reason'))->required()->minLength($min)->maxLength($max);
    }

    private static function pick(string $name, string $label, string $table, array $cols, ?\Closure $scope = null): Select
    {
        return Select::make($name)->label(self::f($label))->required()->searchable()->options(fn () => MiscSupport::rows($table, $cols, $scope));
    }

    /** The support / fulfilment state machines refuse with DomainException; surface it as a shown refusal (409). */
    private static function guard(\Closure $assert): void
    {
        try {
            $assert();
        } catch (\DomainException $e) {
            throw new \App\Interfaces\Http\Errors\ApiProblemException('INVALID_TRANSITION', 409, $e->getMessage());
        }
    }

    private static function address(): array
    {
        return [
            TextInput::make('address.line1')->label(self::f('address_line1'))->required()->maxLength(255),
            TextInput::make('address.city')->label(self::f('city'))->required()->maxLength(120),
            TextInput::make('address.region')->label(self::f('region'))->maxLength(120),
            TextInput::make('address.phone')->label(self::f('phone'))->maxLength(32),
        ];
    }

    private static function providers(): array
    {
        return DB::table('provider_profiles as p')->join('parties', 'parties.id', '=', 'p.party_id')->orderBy('parties.display_name')->limit(300)
            ->pluck('parties.display_name', 'p.id')->map(fn ($n) => (string) $n)->all();
    }

    // ---- fulfilment -------------------------------------------------------------------------------------------

    public static function fulfilmentCreate(): Action
    {
        return MiscSupport::op('fulfilmentCreate', 'fulfilments.manage', 'lucide-package-plus', [
            self::pick('policy_id', 'policy', 'policies', ['policy_number', 'status'], fn ($q) => $q->where('status', 'ACTIVE')),
            ...self::address(),
            DateTimePicker::make('sla_due_at')->label(self::f('sla_due_at'))->required()->default(now()->addDays(3)),
        ], fn (array $d) => ControllerCall::invoke(FulfilmentController::class, 'store', [
            'policy_id' => $d['policy_id'], 'delivery_address' => MiscSupport::filled($d['address'] ?? []), 'sla_due_at' => (string) $d['sla_due_at'],
            'idempotency_key' => 'web-'.Str::uuid(),
        ]));
    }

    public static function courierAvailability(): Action
    {
        return MiscSupport::op('courierAvailability', 'fulfilments.manage', 'lucide-map-pinned', self::address(), function (array $d) {
            $address = MiscSupport::filled($d['address'] ?? []);
            MiscSupport::validate(['delivery_address' => $address], ['delivery_address' => 'required|array']);
            $couriers = app(CourierAdapterRegistry::class)->for('INTERNAL')->checkAvailability(MiscSupport::tenant(), $address);

            return ['available' => count($couriers) > 0, 'couriers' => $couriers];
        }, true);
    }

    public static function fulfilmentTransition(): Action
    {
        $statuses = ['READY_FOR_PICKUP', 'ASSIGNED', 'PICKED_UP', 'IN_TRANSIT', 'DELIVERED', 'FAILED_ATTEMPT', 'RETURNING', 'RETURNED', 'CANCELLED'];

        return MiscSupport::op('fulfilmentTransition', 'fulfilment.transition', 'lucide-route', [
            self::pick('order', 'fulfilment_order', 'fulfilment_orders', ['tracking_number', 'status', 'id'], fn ($q) => $q->whereNotIn('status', ['DELIVERED', 'RETURNED', 'CANCELLED'])),
            Select::make('to_status')->label(self::f('to_status'))->required()->options(MiscSupport::options($statuses)),
            Select::make('courier_id')->label(self::f('courier'))->options(fn () => MiscSupport::rows('couriers', ['name', 'code'], fn ($q) => $q->where('status', 'ACTIVE'))),
            TextInput::make('delivery_otp')->label(self::f('delivery_otp'))->length(6),
        ], function (array $d) {
            $order = DB::table('fulfilment_orders')->where(['tenant_id' => MiscSupport::tenant(), 'id' => $d['order']])->first() ?? abort(404);
            self::guard(fn () => app(FulfilmentStateMachine::class)->assert($order->status, $d['to_status'])); // early, same guard the controller applies

            return ControllerCall::invoke(FulfilmentController::class, 'transition', MiscSupport::filled(array_diff_key($d, ['order' => 1])), ['order' => $d['order']]);
        });
    }

    // ---- provider networks ------------------------------------------------------------------------------------

    public static function providerNetworkCreate(): Action
    {
        return MiscSupport::op('providerNetworkCreate', 'provider_networks.manage', 'lucide-network', [
            TextInput::make('code')->label(self::f('code'))->required()->maxLength(64)->regex('/^[A-Za-z0-9_]+$/'),
            TextInput::make('name')->label(self::f('name'))->required()->maxLength(255),
            TextInput::make('network_type_code')->label(self::f('network_type_code'))->required()->maxLength(64),
            Select::make('category')->label(self::f('category'))->options(MiscSupport::options(['HEALTH', 'GARAGE', 'ADJUSTER', 'EXPERT', 'SURVEYOR'])),
        ], fn (array $d) => app(ProviderNetworkService::class)->createNetwork(MiscSupport::tenant(), MiscSupport::validate(MiscSupport::filled($d), [
            'code' => 'required|string|max:64|regex:/^[A-Za-z0-9_]+$/', 'name' => 'required|string|max:255', 'network_type_code' => 'required|string|max:64',
            'category' => 'nullable|in:HEALTH,GARAGE,ADJUSTER,EXPERT,SURVEYOR', 'carrier_id' => 'nullable|uuid|exists:carriers,id',
        ]), MiscSupport::user()->id));
    }

    public static function providerContractAdd(): Action
    {
        return MiscSupport::op('providerContractAdd', 'provider_networks.manage', 'lucide-file-signature', [
            self::pick('network', 'provider_network', 'provider_networks', ['code', 'name']),
            Select::make('provider_id')->label(self::f('provider'))->required()->searchable()->options(fn () => self::providers()),
            TextInput::make('contract_number')->label(self::f('contract_number'))->required()->maxLength(80),
            DatePicker::make('effective_from')->label(self::f('effective_from'))->required(),
            DatePicker::make('effective_to')->label(self::f('effective_to')),
            Select::make('settlement_mode')->label(self::f('settlement_mode'))->options(MiscSupport::options(['CASHLESS', 'REIMBURSEMENT', 'BOTH'])),
            TextInput::make('document_reference')->label(self::f('document_reference'))->maxLength(500),
        ], function (array $d) {
            $in = MiscSupport::validate(MiscSupport::filled(array_diff_key($d, ['network' => 1])), [
                'provider_id' => 'required|uuid', 'contract_number' => 'required|string|max:80', 'effective_from' => 'required|date',
                'effective_to' => 'nullable|date|after:effective_from', 'settlement_mode' => 'nullable|in:CASHLESS,REIMBURSEMENT,BOTH', 'document_reference' => 'nullable|string|max:500',
            ]);

            return app(ProviderNetworkService::class)->createContract(MiscSupport::tenant(), $d['network'], $in, MiscSupport::user()->id);
        });
    }

    public static function providerMemberAdd(): Action
    {
        return MiscSupport::op('providerMemberAdd', 'provider_networks.manage', 'lucide-user-plus', [
            self::pick('network', 'provider_network', 'provider_networks', ['code', 'name']),
            Select::make('provider_id')->label(self::f('provider'))->required()->searchable()->options(fn () => self::providers()),
            DatePicker::make('effective_from')->label(self::f('effective_from'))->required(),
            DatePicker::make('effective_to')->label(self::f('effective_to')),
        ], function (array $d) {
            $in = MiscSupport::validate(MiscSupport::filled(array_diff_key($d, ['network' => 1])), [
                'provider_id' => 'required|uuid', 'facility_id' => 'nullable|uuid', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date',
            ]);

            return app(ProviderNetworkService::class)->addMember(MiscSupport::tenant(), $d['network'], $in, MiscSupport::user()->id);
        });
    }

    public static function providerTariffDraft(): Action
    {
        return MiscSupport::op('providerTariffDraft', 'provider_networks.manage', 'lucide-receipt', [
            self::pick('contract', 'provider_contract', 'provider_contracts', ['contract_number', 'status']),
            DatePicker::make('effective_from')->label(self::f('effective_from'))->required(),
            TextInput::make('currency')->label(self::f('currency'))->length(3)->default('XAF'),
            Repeater::make('lines')->label(self::f('tariff_lines'))->required()->minItems(1)->schema([
                Select::make('medical_service_id')->label(self::f('medical_service'))->required()->searchable()
                    ->options(fn () => MiscSupport::rows('medical_services', ['code', 'name'], null, 'id', false)),
                TextInput::make('price_minor')->label(self::f('price_minor'))->required()->integer()->minValue(0),
                TextInput::make('contracted_price_minor')->label(self::f('contracted_price_minor'))->required()->integer()->minValue(0),
                TextInput::make('copay_minor')->label(self::f('copay_minor'))->integer()->minValue(0),
                TextInput::make('insurer_share_percent')->label(self::f('insurer_share_percent'))->required()->numeric()->minValue(0)->maxValue(100),
            ])->columns(2),
        ], function (array $d) {
            $d['lines'] = array_values(array_map(fn ($l) => MiscSupport::filled($l), $d['lines'] ?? []));
            $v = MiscSupport::validate(MiscSupport::filled(array_diff_key($d, ['contract' => 1])), [
                'effective_from' => 'required|date', 'currency' => 'nullable|string|size:3', 'lines' => 'required|array|min:1',
                'lines.*.medical_service_id' => 'required|uuid|exists:medical_services,id', 'lines.*.price_minor' => 'required|integer|min:0',
                'lines.*.contracted_price_minor' => 'required|integer|min:0', 'lines.*.copay_minor' => 'nullable|integer|min:0',
                'lines.*.insurer_share_percent' => 'required|numeric|between:0,100',
            ]);

            return app(ProviderNetworkService::class)->draftTariff(MiscSupport::tenant(), $d['contract'], (string) $v['effective_from'], $v['currency'] ?? 'XAF', $v['lines'], MiscSupport::user()->id);
        });
    }

    public static function providerMembershipEnd(): Action
    {
        return MiscSupport::op('providerMembershipEnd', 'provider_networks.manage', 'lucide-user-minus', [
            self::pick('membership', 'provider_membership', 'provider_network_memberships', ['network_id', 'provider_id', 'status']),
            DatePicker::make('effective_to')->label(self::f('effective_to'))->required(),
            self::reason(),
        ], fn (array $d) => app(ProviderNetworkService::class)->endMembership(MiscSupport::tenant(), $d['membership'], (string) $d['effective_to'],
            MiscSupport::validate($d, ['effective_to' => 'required|date', 'reason' => 'required|string|min:5|max:2000'])['reason']));
    }

    public static function providerTariffApprove(): Action
    {
        return MiscSupport::op('providerTariffApprove', 'provider_tariffs.approve', 'lucide-badge-check', [
            self::pick('tariff', 'provider_tariff', 'provider_tariff_versions', ['version', 'currency', 'effective_from', 'status'], fn ($q) => $q->where('status', 'DRAFT')),
        ], fn (array $d) => app(ProviderNetworkService::class)->approveTariff(MiscSupport::tenant(), $d['tariff'], (string) MiscSupport::user()->id));
    }

    // ---- health & fraud ---------------------------------------------------------------------------------------

    public static function fraudClaimAssess(): Action
    {
        return MiscSupport::op('fraudClaimAssess', 'fraud.alert.create', 'lucide-scan-search', [
            self::pick('claim', 'claim', 'claims', ['claim_number', 'status']),
        ], function (array $d) {
            $res = app(ClaimFraudIndicatorService::class)->assess(Claim::where('tenant_id', MiscSupport::tenant())->findOrFail($d['claim']), MiscSupport::user());

            return ['fraud_flag' => $res['review'] ? 'REVIEW_REQUIRED' : null, 'indicators' => $res['indicators'], 'risk_alert_id' => $res['review']?->id, 'case_id' => $res['case_id']];
        }, true);
    }

    public static function fraudClaimOutcome(): Action
    {
        return MiscSupport::op('fraudClaimOutcome', 'fraud.alert.decide', 'lucide-gavel', [
            self::pick('alert', 'risk_alert', 'risk_alerts', ['alert_type', 'severity', 'status'], fn ($q) => $q->where('alert_type', ClaimFraudIndicatorService::ALERT_TYPE)),
            Select::make('outcome')->label(self::f('outcome'))->required()->options(MiscSupport::options(array_keys(ClaimFraudIndicatorService::OUTCOMES))),
            Textarea::make('rationale')->label(self::f('rationale'))->required()->minLength(20)->maxLength(4000),
        ], function (array $d) {
            $v = MiscSupport::validate($d, ['outcome' => 'required|in:'.implode(',', array_keys(ClaimFraudIndicatorService::OUTCOMES)), 'rationale' => 'required|string|min:20|max:4000']);
            $alert = RiskAlert::where('tenant_id', MiscSupport::tenant())->where('alert_type', ClaimFraudIndicatorService::ALERT_TYPE)->findOrFail($d['alert']);

            return app(ClaimFraudIndicatorService::class)->recordOutcome($alert, $v['outcome'], $v['rationale'], MiscSupport::user());
        });
    }

    public static function healthCardIssue(): Action
    {
        return MiscSupport::op('healthCardIssue', 'health.cards.issue', 'lucide-id-card', [
            self::pick('member', 'health_member', 'health_members', ['member_number', 'relationship', 'status']),
        ], fn (array $d) => app(HealthCardService::class)->issue(MiscSupport::tenant(), $d['member'], MiscSupport::user()->id));
    }

    public static function healthMemberEnd(): Action
    {
        return MiscSupport::op('healthMemberEnd', 'health.members.manage', 'lucide-user-x', [
            self::pick('member', 'health_member', 'health_members', ['member_number', 'relationship', 'status']),
            DatePicker::make('effective_to')->label(self::f('effective_to'))->required(),
            Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(255),
        ], function (array $d) {
            $v = MiscSupport::validate($d, ['effective_to' => 'required|date', 'reason' => 'required|string|max:255']);

            return app(HealthMemberService::class)->end(MiscSupport::tenant(), $d['member'], (string) $v['effective_to'], $v['reason']);
        });
    }

    // ---- legal & policy records -------------------------------------------------------------------------------

    public static function legalDeadlineComplete(): Action
    {
        return MiscSupport::op('legalDeadlineComplete', 'legal.matters.manage', 'lucide-calendar-check', [
            self::pick('deadline', 'legal_deadline', 'legal_deadlines', ['title', 'deadline_type', 'due_at', 'due_date', 'status']),
        ], fn (array $d) => app(LegalMatterService::class)->completeDeadline(MiscSupport::tenant(), $d['deadline'], MiscSupport::user()));
    }

    public static function legalHearingRecord(): Action
    {
        return MiscSupport::op('legalHearingRecord', 'legal.matters.manage', 'lucide-landmark', [
            self::pick('hearing', 'legal_hearing', 'legal_hearings', ['court', 'scheduled_at', 'hearing_at', 'status']),
            Select::make('status')->label(self::f('hearing_status'))->required()->options(MiscSupport::options(LegalMatterService::HEARING_RESULTS)),
            Textarea::make('result')->label(self::f('result'))->maxLength(4000),
        ], function (array $d) {
            $v = MiscSupport::validate($d, ['status' => ['required', Rule::in(LegalMatterService::HEARING_RESULTS)], 'result' => 'nullable|string|max:4000']);

            return app(LegalMatterService::class)->recordHearing(MiscSupport::tenant(), $d['hearing'], $v['status'], $v['result'] ?? null, MiscSupport::user());
        });
    }

    public static function scheduleItemRemove(): Action
    {
        return MiscSupport::op('scheduleItemRemove', 'special_policies.schedule.manage', 'lucide-list-x', [
            self::pick('item', 'schedule_item', 'policy_schedule_items', ['item_reference', 'description', 'item_type', 'status']),
            DatePicker::make('effective_until')->label(self::f('effective_until'))->required(),
            self::reason(),
        ], function (array $d) {
            $v = MiscSupport::validate($d, ['effective_until' => 'required|date', 'reason' => 'required|string|min:5|max:2000']);

            return app(PolicyScheduleService::class)->removeItem(MiscSupport::tenant(), $d['item'], \Carbon\CarbonImmutable::parse($v['effective_until'])->toDateString(), $v['reason'], MiscSupport::user());
        });
    }

    public static function premiumComponentClose(): Action
    {
        return MiscSupport::op('premiumComponentClose', 'premium_components.close', 'lucide-circle-slash', [
            self::pick('component', 'premium_component', 'premium_components', ['component_type', 'amount_minor', 'currency', 'status']),
            Select::make('closure')->label(self::f('closure'))->required()->options(MiscSupport::options(PremiumComponentService::CLOSURES)),
            Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(255),
        ], function (array $d) {
            $v = MiscSupport::validate($d, ['closure' => ['required', Rule::in(PremiumComponentService::CLOSURES)], 'reason' => 'required|string|max:255']);

            return app(PremiumComponentService::class)->close($d['component'], MiscSupport::tenant(), $v['closure'], $v['reason'], MiscSupport::user());
        });
    }

    public static function cargoDeclarationCancel(): Action
    {
        return MiscSupport::op('cargoDeclarationCancel', 'cargo_declarations.cancel', 'lucide-ship', [
            self::pick('declaration', 'cargo_declaration', 'cargo_declarations', ['declaration_number', 'reference', 'status']),
            self::reason(),
        ], fn (array $d) => app(CargoDeclarationService::class)->cancel(MiscSupport::tenant(), $d['declaration'],
            MiscSupport::validate($d, ['reason' => 'required|string|min:5|max:2000'])['reason'], MiscSupport::user()));
    }

    // ---- distribution -----------------------------------------------------------------------------------------

    public static function agreementVerifySource(): Action
    {
        return MiscSupport::op('agreementVerifySource', 'distribution.agreements.approve', 'lucide-file-check', [
            Select::make('agreement')->label(self::f('agreement'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('carrier_broker_agreements', ['agreement_number', 'reference', 'status'], null, 'id', false)),
            TextInput::make('source_document')->label(self::f('source_document'))->required()->maxLength(512),
            Textarea::make('note')->label(self::f('note'))->maxLength(500),
        ], function (array $d) {
            $v = MiscSupport::validate($d, ['source_document' => ['required', 'string', 'max:512'], 'note' => ['nullable', 'string', 'max:500']]);

            return app(MarketDataGates::class)->verifyAgreementSource($d['agreement'], MiscSupport::user(), $v['source_document'], $v['note'] ?? null);
        });
    }

    public static function onboardingRecordReview(): Action
    {
        return MiscSupport::op('onboardingRecordReview', 'onboarding.private_data.review', 'lucide-clipboard-check', [
            Select::make('record')->label(self::f('onboarding_record'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('tenant_onboarding_records', ['dataset', 'record_key', 'review_status'],
                    fn ($q) => $q->where(fn ($w) => $w->where('tenant_id', MiscSupport::tenant())->orWhereNull('tenant_id')), 'id', false)),
            Select::make('decision')->label(self::f('decision'))->required()->options(MiscSupport::options(['ACCEPTED', 'REJECTED'])),
            Textarea::make('note')->label(self::f('note'))->maxLength(2000),
        ], fn (array $d) => app(PrivateOnboardingService::class)->review($d['record'], $d['decision'], $d['note'] ?? null, MiscSupport::user(), MiscSupport::tenant()));
    }

    private static function partner(array $d): Partner
    {
        return Partner::query()->where('tenant_id', MiscSupport::tenant())->findOrFail($d['partner']);
    }

    private static function partnerPicker(string $name = 'partner', string $label = 'partner', bool $required = true): Select
    {
        return Select::make($name)->label(self::f($label))->required($required)->searchable()
            ->options(fn () => Partner::query()->where('tenant_id', MiscSupport::tenant())->with('party')->limit(300)->get()
                ->mapWithKeys(fn (Partner $p) => [$p->id => trim(($p->party?->display_name ?? $p->id).' · '.($p->partner_type ?? ''), ' ·')])->all());
    }

    public static function partnerPlace(): Action
    {
        return MiscSupport::op('partnerPlace', 'partners.manage', 'lucide-git-branch', [
            self::partnerPicker(),
            self::partnerPicker('supervisor_partner_id', 'supervisor', false),
            Select::make('branch_id')->label(self::f('branch'))->options(fn () => MiscSupport::rows('tenant_branches', ['code', 'name'])),
            Select::make('agent_type')->label(self::f('agent_type'))->options(MiscSupport::options(AgentHierarchyService::AGENT_TYPES)),
            TextInput::make('reason_code')->label(self::f('reason_code'))->required()->maxLength(64),
        ], function (array $d) {
            $v = MiscSupport::validate(MiscSupport::filled(array_diff_key($d, ['partner' => 1])), [
                'supervisor_partner_id' => 'sometimes|nullable|uuid', 'branch_id' => 'sometimes|nullable|uuid',
                'agent_type' => ['sometimes', 'nullable', Rule::in(AgentHierarchyService::AGENT_TYPES)], 'reason_code' => 'required|string|max:64',
            ]);
            $reason = $v['reason_code'];
            unset($v['reason_code']);

            return app(AgentHierarchyService::class)->place(self::partner($d), $v, $reason, MiscSupport::user());
        });
    }

    public static function partnerSuspend(): Action
    {
        return MiscSupport::op('partnerSuspend', 'partners.manage', 'lucide-pause-circle', [
            self::partnerPicker(),
            Textarea::make('notes')->label(self::f('notes'))->required()->minLength(5)->maxLength(2000),
            self::partnerPicker('reassign_to_partner_id', 'reassign_to', false),
        ], function (array $d) {
            $v = MiscSupport::validate($d, ['notes' => 'required|string|min:5|max:2000', 'reassign_to_partner_id' => 'nullable|uuid']);

            return app(AgentHierarchyService::class)->suspend(self::partner($d), $v['notes'], MiscSupport::user(), $v['reassign_to_partner_id'] ?? null);
        });
    }

    public static function marketplaceToggle(): Action
    {
        return MiscSupport::op('marketplaceToggle', 'broker.marketplace.manage', 'lucide-store', [
            self::pick('id', 'marketplace_publication', 'marketplace_publications', ['title', 'product_code', 'status']),
            Toggle::make('enabled')->label(self::f('enabled'))->default(true),
        ], fn (array $d) => ControllerCall::invoke(MobileBrokerOpsController::class, 'togglePublication', ['enabled' => (bool) ($d['enabled'] ?? false)], ['id' => $d['id']], 'PATCH'));
    }

    public static function carrierProductStatus(): Action
    {
        return MiscSupport::op('carrierProductStatus', 'carrier.authority.manage', 'lucide-power', [
            Select::make('product')->label(self::f('product'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('insurance_products', ['code', 'name', 'status'], null, 'id', false)),
            Toggle::make('active')->label(self::f('active'))->default(true),
            Textarea::make('reason')->label(self::f('reason'))->required()->minLength(3)->maxLength(500),
        ], fn (array $d) => ControllerCall::invoke(PartnerCarrierWorkspaceController::class, 'productStatus',
            ['active' => (bool) ($d['active'] ?? false), 'reason' => $d['reason']], ['product' => $d['product']]));
    }

    // ---- support & communications -----------------------------------------------------------------------------

    public static function supportTicketCreate(): Action
    {
        return MiscSupport::op('supportTicketCreate', 'support.manage', 'lucide-message-square-plus', [
            Select::make('party_id')->label(self::f('customer'))->searchable()->options(fn () => MiscSupport::customers()),
            Select::make('type')->label(self::f('ticket_type'))->required()->options(MiscSupport::options(['SUPPORT', 'COMPLAINT', 'REGULATORY_COMPLAINT'])),
            TextInput::make('category')->label(self::f('category'))->required()->maxLength(64),
            Select::make('priority')->label(self::f('priority'))->required()->options(MiscSupport::options(['LOW', 'NORMAL', 'HIGH', 'URGENT']))->default('NORMAL'),
            TextInput::make('subject')->label(self::f('subject'))->required()->maxLength(200),
            Textarea::make('description')->label(self::f('description'))->required()->minLength(20)->maxLength(10000),
        ], fn (array $d) => ControllerCall::invoke(SupportController::class, 'store', MiscSupport::filled($d) + ['idempotency_key' => 'web-'.Str::uuid()]));
    }

    public static function supportTicketTransition(): Action
    {
        return MiscSupport::op('supportTicketTransition', 'support.manage', 'lucide-route', [
            self::pick('ticket', 'support_ticket', 'support_tickets', ['ticket_number', 'subject', 'status'], fn ($q) => $q->whereNotIn('status', ['CLOSED', 'CANCELLED'])),
            Select::make('to_status')->label(self::f('to_status'))->required()
                ->options(MiscSupport::options(['TRIAGED', 'IN_PROGRESS', 'WAITING_CUSTOMER', 'ESCALATED', 'RESOLVED', 'CLOSED', 'REOPENED', 'CANCELLED'])),
            Textarea::make('message')->label(self::f('message'))->required()->minLength(5)->maxLength(5000),
            Select::make('assigned_to')->label(self::f('assignee'))->searchable()->options(fn () => MiscSupport::members()),
        ], function (array $d) {
            $ticket = DB::table('support_tickets')->where(['tenant_id' => MiscSupport::tenant(), 'id' => $d['ticket']])->first() ?? abort(404);
            self::guard(fn () => app(TicketStateMachine::class)->assert($ticket->status, $d['to_status'])); // early, same guard the controller applies

            return ControllerCall::invoke(SupportController::class, 'transition', MiscSupport::filled(array_diff_key($d, ['ticket' => 1])), ['ticket' => $d['ticket']]);
        });
    }

    public static function communicationLog(): Action
    {
        return MiscSupport::op('communicationLog', 'communications.manage', 'lucide-phone-call', [
            Select::make('party_id')->label(self::f('customer'))->searchable()->options(fn () => MiscSupport::customers()),
            Select::make('direction')->label(self::f('direction'))->required()->options(MiscSupport::options(['INBOUND', 'OUTBOUND'])),
            Select::make('channel')->label(self::f('channel'))->required()->options(MiscSupport::options(['PHONE', 'SMS', 'EMAIL', 'WHATSAPP', 'PORTAL'])),
            TextInput::make('purpose')->label(self::f('purpose'))->required()->maxLength(24),
            TextInput::make('counterparty')->label(self::f('counterparty'))->required()->maxLength(190),
            Textarea::make('summary')->label(self::f('summary'))->required()->maxLength(5000),
            Select::make('ticket_id')->label(self::f('support_ticket'))->searchable()->options(fn () => MiscSupport::rows('support_tickets', ['ticket_number', 'subject'])),
            DateTimePicker::make('occurred_at')->label(self::f('occurred_at')),
        ], function (array $d) {
            $d = MiscSupport::validate(MiscSupport::filled($d), ['party_id' => 'nullable|uuid', 'direction' => 'required|in:INBOUND,OUTBOUND',
                'channel' => 'required|in:PHONE,SMS,EMAIL,WHATSAPP,PORTAL', 'purpose' => 'required|string|max:24', 'counterparty' => 'required|string|max:190',
                'summary' => 'required|string|max:5000', 'ticket_id' => 'nullable|uuid', 'metadata' => 'array', 'occurred_at' => 'nullable|date']);
            $t = MiscSupport::tenant();
            // Same tenant checks as SupportController::communication.
            foreach (['party_id' => 'tenant_customers', 'ticket_id' => 'support_tickets'] as $k => $table) {
                if (! empty($d[$k])) {
                    abort_unless(DB::table($table)->where($k === 'party_id' ? ['tenant_id' => $t, 'party_id' => $d[$k]] : ['tenant_id' => $t, 'id' => $d[$k]])->exists(), 404);
                }
            }

            return ['id' => app(CommunicationLogService::class)->record($t, $d, MiscSupport::user()->id)];
        });
    }
}
