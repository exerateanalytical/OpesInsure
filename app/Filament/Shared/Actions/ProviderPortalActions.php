<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Health\Benefits\BenefitSchedule;
use App\Application\Health\Eligibility\EligibilityService;
use App\Application\Health\Eligibility\HealthCardService;
use App\Application\Health\Preauth\PreauthLifecycle;
use App\Application\Health\Preauth\PreauthorizationService;
use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * UI coverage batches 16/17 — insurer-staff (desktop) side of the provider network and of the provider-facing health
 * API. Each action validates with the API controller's rules, then calls the SAME service with the SAME permission:
 *   providerRegister            POST providers                                  providers.manage       ProviderRegistry::register
 *   providerCredential          POST providers/{p}/credentialing                providers.credential   ProviderRegistry::transition
 *   providerAddFacility         POST providers/{p}/facilities                   providers.manage       ProviderRegistry::addFacility
 *   providerAddFacilityService  POST provider-facilities/{f}/services           providers.manage       ProviderRegistry::addFacilityService
 *   providerMapCode             POST providers/{p}/code-mappings                providers.manage       ProviderNetworkService::mapProviderCode
 *   providerRelate              POST providers/{p}/relationships                providers.manage       ProviderRegistry::relate
 *   medicalServiceAdd           POST medical-services                           providers.manage       ProviderNetworkService::addMedicalService
 *   benefitScheduleCreate       POST health/benefit-schedules                   health.benefits.manage BenefitSchedule::create
 *   eligibilityCheck            POST health/eligibility/check                   health.eligibility.check  EligibilityService::check
 *   eligibilityScan             POST health/eligibility/scan                    health.eligibility.scan   HealthCardService::scan
 *   preauthRequest              POST health/preauthorizations                   health.preauth.request PreauthorizationService::request
 *   preauthProvideInfo          POST health/preauthorizations/{p}/info          health.preauth.request PreauthorizationService::provideInfo
 *   preauthAdmit                POST health/preauthorizations/{p}/admission     health.preauth.request PreauthorizationService::admit
 *   preauthDischarge            POST health/preauthorizations/{p}/discharge     health.preauth.request PreauthorizationService::discharge
 *   preauthRequestExtension     POST health/preauthorizations/{p}/extensions    health.preauth.request PreauthorizationService::requestExtension
 *   providerClaimCreate         POST health/provider-claims                     health.provider_claims.capture ProviderClaimService::create
 *   providerClaimSubmit         POST health/provider-claims/{c}/submit          health.provider_claims.capture ProviderClaimService::submit
 *   providerClaimDispute        POST health/provider-claims/{c}/dispute         health.provider_claims.dispute ProviderClaimService::dispute
 * Clinical fields (clinical_notes, diagnosis) are only captured here, never listed back.
 */
final class ProviderPortalActions
{
    private const LANG = 'provider_portal_actions';

    private static function make(string $name, string $permission): Action
    {
        return WorkflowAction::make($name, $permission, self::LANG);
    }

    private static function done(string $name): string
    {
        return __(self::LANG.'.'.$name.'.done');
    }

    private static function f(string $key): string
    {
        return __(self::LANG.'.fields.'.$key);
    }

    /** Validate $data with the API controller's rules (a ValidationException is surfaced by WorkflowAction::run). @return array<string, mixed> */
    private static function valid(array $data, array $rules): array
    {
        return Validator::make(array_map(fn ($v) => $v === '' ? null : $v, $data), $rules)->validate();
    }

    // ----- Provider registry (providers.manage / providers.credential) ---------------------------------------------

    public static function providerRegister(): Action
    {
        $p = 'providers.manage';

        return self::make('providerRegister', $p)->icon('lucide-plus')
            ->schema([
                Select::make('category')->label(self::f('category'))->options(array_combine(array_keys(ProviderRegistry::CATEGORIES), array_keys(ProviderRegistry::CATEGORIES)))->required()->default('HEALTH'),
                TextInput::make('name')->label(self::f('name'))->required()->maxLength(255),
                TextInput::make('provider_type_code')->label(self::f('provider_type_code'))->required()->maxLength(64),
                TextInput::make('registration_number')->label(self::f('registration_number'))->maxLength(120),
                TextInput::make('city_code')->label(self::f('city_code'))->maxLength(64),
                TextInput::make('region_code')->label(self::f('region_code'))->maxLength(64),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(ProviderRegistry::class)->register(self::valid($data, [
                'category' => ['required', Rule::in(array_keys(ProviderRegistry::CATEGORIES))], 'name' => 'required|string|max:255',
                'provider_type_code' => 'required|string|max:64', 'party_id' => 'nullable|uuid|exists:parties,id',
                'registration_number' => 'nullable|string|max:120', 'city_code' => 'nullable|string|max:64', 'region_code' => 'nullable|string|max:64',
            ]), auth()->id()), self::done('providerRegister')));
    }

    public static function providerCredential(): Action
    {
        $p = 'providers.credential';

        return self::make('providerCredential', $p)->icon('lucide-badge-check')
            ->visible(fn ($record) => (ProviderRegistry::TRANSITIONS[self::val($record, 'credentialing_status')] ?? []) !== [])
            ->schema(fn ($record) => [
                Select::make('to')->label(self::f('to_status'))->required()
                    ->options(collect(ProviderRegistry::TRANSITIONS[self::val($record, 'credentialing_status')] ?? [])->mapWithKeys(fn ($s) => [$s => $s])->all()),
                Textarea::make('reason')->label(self::f('reason'))->maxLength(2000),
                TextInput::make('evidence_reference')->label(self::f('evidence_reference'))->maxLength(500),
            ])
            ->action(function (Action $action, $record, array $data) use ($p) {
                WorkflowAction::run($action, $p, function () use ($record, $data) {
                    $d = self::valid($data, ['to' => ['required', Rule::in(array_keys(ProviderRegistry::TRANSITIONS))], 'reason' => 'nullable|string|max:2000', 'evidence_reference' => 'nullable|string|max:500']);

                    return app(ProviderRegistry::class)->transition(WorkflowAction::id($record), $d['to'], $d['reason'] ?? null, $d['evidence_reference'] ?? null, auth()->id());
                }, self::done('providerCredential'));
            });
    }

    public static function providerAddFacility(): Action
    {
        $p = 'providers.manage';

        return self::make('providerAddFacility', $p)->icon('lucide-building-2')
            ->schema([
                TextInput::make('code')->label(self::f('code'))->required()->maxLength(64),
                TextInput::make('name')->label(self::f('name'))->required()->maxLength(255),
                TextInput::make('facility_type_code')->label(self::f('facility_type_code'))->maxLength(64),
                TextInput::make('city_code')->label(self::f('city_code'))->maxLength(64),
                TextInput::make('region_code')->label(self::f('region_code'))->maxLength(64),
                Textarea::make('address')->label(self::f('address'))->maxLength(1000),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ProviderRegistry::class)->addFacility(WorkflowAction::id($record), self::valid($data, [
                'code' => 'required|string|max:64', 'name' => 'required|string|max:255', 'facility_type_code' => 'nullable|string|max:64',
                'city_code' => 'nullable|string|max:64', 'region_code' => 'nullable|string|max:64', 'address' => 'nullable|string|max:1000',
            ])), self::done('providerAddFacility')));
    }

    public static function providerAddFacilityService(): Action
    {
        $p = 'providers.manage';

        return self::make('providerAddFacilityService', $p)->icon('lucide-stethoscope')
            ->schema(fn ($record) => [
                Select::make('facility_id')->label(self::f('facility'))->required()
                    ->options(fn () => DB::table('provider_facilities')->where('provider_profile_id', WorkflowAction::id($record))->orderBy('code')->pluck('name', 'id')->all()),
                Select::make('medical_service_id')->label(self::f('medical_service'))->required()->searchable()->options(fn () => self::medicalServices()),
                TextInput::make('specialty_code')->label(self::f('specialty_code'))->maxLength(64),
            ])
            ->action(function (Action $action, $record, array $data) use ($p) {
                WorkflowAction::run($action, $p, function () use ($record, $data) {
                    $d = self::valid($data, ['facility_id' => 'required|uuid', 'medical_service_id' => 'required|uuid', 'specialty_code' => 'nullable|string|max:64']);
                    // The facility must belong to this provider (the API addresses it directly by id).
                    abort_unless(DB::table('provider_facilities')->where(['id' => $d['facility_id'], 'provider_profile_id' => WorkflowAction::id($record)])->exists(), 404);

                    return app(ProviderRegistry::class)->addFacilityService($d['facility_id'], $d['medical_service_id'], $d['specialty_code'] ?? null);
                }, self::done('providerAddFacilityService'));
            });
    }

    public static function providerMapCode(): Action
    {
        $p = 'providers.manage';

        return self::make('providerMapCode', $p)->icon('lucide-link')
            ->schema([
                TextInput::make('provider_code')->label(self::f('provider_code'))->required()->maxLength(120),
                Select::make('medical_service_id')->label(self::f('medical_service'))->required()->searchable()->options(fn () => self::medicalServices()),
            ])
            ->action(function (Action $action, $record, array $data) use ($p) {
                WorkflowAction::run($action, $p, function () use ($record, $data) {
                    $d = self::valid($data, ['provider_code' => 'required|string|max:120', 'medical_service_id' => 'required|uuid']);

                    return app(ProviderNetworkService::class)->mapProviderCode(WorkflowAction::id($record), $d['provider_code'], $d['medical_service_id']);
                }, self::done('providerMapCode'));
            });
    }

    public static function providerRelate(): Action
    {
        $p = 'providers.manage';

        return self::make('providerRelate', $p)->icon('lucide-git-branch')
            ->schema([
                Select::make('to_party_id')->label(self::f('to_party'))->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => DB::table('parties')->where('display_name', 'ilike', '%'.$search.'%')->limit(25)->pluck('display_name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => DB::table('parties')->where('id', $value)->value('display_name')),
                Select::make('type')->label(self::f('relationship_type'))->required()->options(array_combine(ProviderRegistry::RELATIONSHIP_TYPES, ProviderRegistry::RELATIONSHIP_TYPES)),
                DatePicker::make('valid_from')->label(self::f('valid_from')),
                DatePicker::make('valid_to')->label(self::f('valid_to')),
            ])
            ->action(function (Action $action, $record, array $data) use ($p) {
                WorkflowAction::run($action, $p, function () use ($record, $data) {
                    $d = self::valid($data, ['to_party_id' => 'required|uuid', 'type' => ['required', Rule::in(ProviderRegistry::RELATIONSHIP_TYPES)],
                        'valid_from' => 'nullable|date', 'valid_to' => 'nullable|date|after:valid_from']);

                    return app(ProviderRegistry::class)->relate(WorkflowAction::id($record), $d['to_party_id'], $d['type'], $d['valid_from'] ?? null, $d['valid_to'] ?? null,
                        self::tenant(), auth()->id());
                }, self::done('providerRelate'));
            });
    }

    public static function medicalServiceAdd(): Action
    {
        $p = 'providers.manage';

        return self::make('medicalServiceAdd', $p)->icon('lucide-list-plus')
            ->schema([
                TextInput::make('code')->label(self::f('code'))->required()->maxLength(64),
                TextInput::make('name')->label(self::f('name'))->required()->maxLength(255),
                TextInput::make('category_code')->label(self::f('category_code'))->required()->maxLength(64),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(ProviderNetworkService::class)->addMedicalService(self::valid($data, [
                'code' => 'required|string|max:64|regex:/^[A-Za-z0-9_.-]+$/', 'name' => 'required|string|max:255', 'category_code' => 'required|string|max:64',
            ])), self::done('medicalServiceAdd')));
    }

    // ----- Health benefits / eligibility ----------------------------------------------------------------------------

    public static function benefitScheduleCreate(): Action
    {
        $p = 'health.benefits.manage';

        return self::make('benefitScheduleCreate', $p)->icon('lucide-table-2')
            ->schema([
                Select::make('insurance_product_id')->label(self::f('product'))->required()->searchable()
                    ->options(fn () => DB::table('insurance_products')->orderBy('code')->limit(500)->pluck('code', 'id')->all()),
                TextInput::make('benefit_code')->label(self::f('benefit_code'))->required()->maxLength(64),
                Select::make('period_basis')->label(self::f('period_basis'))->options(array_combine(BenefitSchedule::PERIODS, BenefitSchedule::PERIODS)),
                Select::make('scope')->label(self::f('scope'))->options(array_combine(BenefitSchedule::SCOPES, BenefitSchedule::SCOPES)),
                TextInput::make('period_limit_minor')->label(self::f('period_limit_minor'))->integer()->minValue(0),
                TextInput::make('per_event_limit_minor')->label(self::f('per_event_limit_minor'))->integer()->minValue(0),
                TextInput::make('copay_bp')->label(self::f('copay_bp'))->integer()->minValue(0)->maxValue(10000),
                TextInput::make('waiting_period_days')->label(self::f('waiting_period_days'))->integer()->minValue(0),
                TextInput::make('currency')->label(self::f('currency'))->length(3)->default('XAF'),
                DatePicker::make('effective_from')->label(self::f('effective_from'))->required(),
                DatePicker::make('effective_until')->label(self::f('effective_until')),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, fn () => app(BenefitSchedule::class)->create(['tenant_id' => self::tenant()] + self::valid($data, [
                'insurance_product_id' => 'required|uuid|exists:insurance_products,id', 'benefit_code' => 'required|string|max:64|regex:/^[A-Z0-9_.-]+$/',
                'period_basis' => ['nullable', Rule::in(BenefitSchedule::PERIODS)], 'scope' => ['nullable', Rule::in(BenefitSchedule::SCOPES)],
                'period_limit_minor' => 'nullable|integer|min:0', 'per_event_limit_minor' => 'nullable|integer|min:0', 'copay_bp' => 'nullable|integer|min:0|max:10000',
                'waiting_period_days' => 'nullable|integer|min:0', 'currency' => 'nullable|string|size:3', 'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after:effective_from',
            ]), auth()->id()), self::done('benefitScheduleCreate')));
    }

    public static function eligibilityCheck(): Action
    {
        $p = 'health.eligibility.check';

        return self::make('eligibilityCheck', $p)->icon('lucide-search-check')
            ->schema([
                TextInput::make('member_ref')->label(self::f('member_ref'))->required()->maxLength(64),
                Select::make('provider_id')->label(self::f('provider'))->searchable()->options(fn () => self::providers()),
                TextInput::make('service_code')->label(self::f('service_code'))->required()->maxLength(64),
                DatePicker::make('service_date')->label(self::f('service_date')),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $r = WorkflowAction::run($action, $p, function () use ($data) {
                    $d = self::valid($data, ['member_ref' => 'required|string|max:64', 'provider_id' => 'nullable|uuid', 'service_code' => 'required|string|max:64', 'service_date' => 'nullable|date']);

                    return app(EligibilityService::class)->check(self::tenant(), $d['member_ref'], $d['provider_id'] ?? null, $d['service_code'], $d['service_date'] ?? null, 'API', auth()->id());
                }, self::done('eligibilityCheck'));
                self::showResult($r);
            });
    }

    public static function eligibilityScan(): Action
    {
        $p = 'health.eligibility.scan';

        return self::make('eligibilityScan', $p)->icon('lucide-scan-line')
            ->schema([
                Textarea::make('qr')->label(self::f('qr'))->required()->maxLength(1024),
                Select::make('provider_id')->label(self::f('provider'))->required()->searchable()->options(fn () => self::providers()),
                TextInput::make('service_code')->label(self::f('service_code'))->required()->maxLength(64),
                DatePicker::make('service_date')->label(self::f('service_date')),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $r = WorkflowAction::run($action, $p, function () use ($data) {
                    $d = self::valid($data, ['qr' => 'required|string|max:1024', 'provider_id' => 'required|uuid', 'service_code' => 'required|string|max:64', 'service_date' => 'nullable|date']);

                    return app(HealthCardService::class)->scan(self::tenant(), $d['qr'], $d['provider_id'], $d['service_code'], $d['service_date'] ?? null, auth()->id());
                }, self::done('eligibilityScan'));
                self::showResult($r);
            });
    }

    /** Eligibility outcome: coverage flags only — never medical history. */
    private static function showResult(mixed $r): void
    {
        if (! is_array($r)) {
            return;
        }
        $keep = array_intersect_key($r, array_flip(['eligible', 'status', 'coverage_status', 'reason', 'reason_code', 'reasons', 'benefit_code', 'preauth_required', 'preauthorization_required', 'in_network', 'verification_reference']));
        $body = collect($keep)->map(fn ($v, $k) => $k.': '.(is_bool($v) ? ($v ? 'yes' : 'no') : (is_scalar($v) ? $v : json_encode($v))))->implode("\n");
        Notification::make()->info()->title(__(self::LANG.'.result'))->body($body)->persistent()->send();
    }

    // ----- Pre-authorization, provider side (health.preauth.request) -------------------------------------------------

    /** @return list<Action> row actions for the insurer pre-authorization queue */
    public static function preauth(): array
    {
        return [self::preauthProvideInfo(), self::preauthAdmit(), self::preauthRequestExtension(), self::preauthDischarge()];
    }

    public static function preauthRequest(): Action
    {
        $p = 'health.preauth.request';

        return self::make('preauthRequest', $p)->icon('lucide-file-plus')->modalWidth('4xl')
            ->schema([
                Select::make('request_type')->label(self::f('request_type'))->required()->live()->options(array_combine(PreauthLifecycle::TYPES, PreauthLifecycle::TYPES)),
                TextInput::make('policy_id')->label(self::f('policy_id'))->required()->uuid(),
                TextInput::make('member_ref')->label(self::f('member_ref'))->maxLength(120),
                Select::make('provider_id')->label(self::f('provider'))->required()->searchable()->options(fn () => self::providers()),
                Textarea::make('clinical_notes')->label(self::f('clinical_notes'))->maxLength(5000),
                KeyValue::make('details')->label(self::f('details'))
                    ->helperText(fn (callable $get) => implode(', ', array_keys(PreauthLifecycle::TYPE_FIELDS[$get('request_type')] ?? []))),
                Repeater::make('lines')->label(self::f('lines'))->required()->minItems(1)->columns(3)->schema([
                    TextInput::make('service_code')->label(self::f('service_code'))->required()->maxLength(64),
                    TextInput::make('quantity')->label(self::f('quantity'))->required()->numeric()->default(1),
                    TextInput::make('unit_price_minor')->label(self::f('unit_price_minor'))->integer()->minValue(0),
                ]),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                WorkflowAction::run($action, $p, function () use ($data) {
                    $type = (string) ($data['request_type'] ?? '');
                    $rules = [
                        'request_type' => ['required', Rule::in(PreauthLifecycle::TYPES)], 'policy_id' => 'required|uuid', 'member_ref' => 'nullable|string|max:120',
                        'provider_id' => 'required|uuid', 'clinical_notes' => 'nullable|string|max:5000',
                        'details' => 'required|array', 'lines' => 'required|array|min:1|max:100',
                        'lines.*.service_code' => 'required|string|max:64', 'lines.*.quantity' => 'required|numeric|gt:0|max:99999', 'lines.*.unit_price_minor' => 'nullable|integer|min:0',
                    ];
                    foreach (PreauthLifecycle::TYPE_FIELDS[$type] ?? [] as $field => [$required, $rule]) {
                        $rules['details.'.$field] = ($required ? 'required|' : 'nullable|').$rule;
                    }
                    $data['details'] = (array) ($data['details'] ?? []);
                    $data['lines'] = array_values((array) ($data['lines'] ?? []));
                    $d = self::valid($data, $rules);
                    $d['details'] = array_intersect_key($d['details'], PreauthLifecycle::TYPE_FIELDS[$type]);

                    return app(PreauthorizationService::class)->request(self::tenant(), $d, auth()->user());
                }, self::done('preauthRequest'));
            });
    }

    public static function preauthProvideInfo(): Action
    {
        $p = 'health.preauth.request';

        return self::make('preauthProvideInfo', $p)->icon('lucide-message-square-reply')
            ->visible(fn ($record) => self::val($record, 'status') === 'INFO_REQUESTED')
            ->schema([Textarea::make('answer')->label(self::f('answer'))->required()->maxLength(5000)])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PreauthorizationService::class)->provideInfo(
                self::tenant(), WorkflowAction::id($record), self::valid($data, ['answer' => 'required|string|max:5000'])['answer'], auth()->user()), self::done('preauthProvideInfo')));
    }

    public static function preauthAdmit(): Action
    {
        $p = 'health.preauth.request';

        return self::make('preauthAdmit', $p)->icon('lucide-bed')
            ->visible(fn ($record) => self::val($record, 'request_type') === 'ADMISSION' && PreauthLifecycle::target((string) self::val($record, 'status'), 'admit') !== null)
            ->schema([DatePicker::make('admitted_on')->label(self::f('admitted_on'))])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PreauthorizationService::class)->admit(
                self::tenant(), WorkflowAction::id($record), self::valid($data, ['admitted_on' => 'nullable|date'])['admitted_on'] ?? null, auth()->user()), self::done('preauthAdmit')));
    }

    public static function preauthDischarge(): Action
    {
        $p = 'health.preauth.request';

        return self::make('preauthDischarge', $p)->icon('lucide-log-out')
            ->visible(fn ($record) => self::val($record, 'status') === 'ADMITTED')
            ->schema([DatePicker::make('discharged_on')->label(self::f('discharged_on'))])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PreauthorizationService::class)->discharge(
                self::tenant(), WorkflowAction::id($record), self::valid($data, ['discharged_on' => 'nullable|date'])['discharged_on'] ?? null, auth()->user()), self::done('preauthDischarge')));
    }

    public static function preauthRequestExtension(): Action
    {
        $p = 'health.preauth.request';

        return self::make('preauthRequestExtension', $p)->icon('lucide-calendar-plus')
            ->visible(fn ($record) => self::val($record, 'status') === 'ADMITTED')
            ->schema([
                DatePicker::make('requested_until')->label(self::f('requested_until'))->required(),
                Textarea::make('reason')->label(self::f('reason'))->required()->maxLength(2000),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(PreauthorizationService::class)->requestExtension(
                self::tenant(), WorkflowAction::id($record), self::valid($data, ['requested_until' => 'required|date', 'reason' => 'required|string|max:2000']), auth()->user()),
                self::done('preauthRequestExtension')));
    }

    // ----- Provider claims, provider side ----------------------------------------------------------------------------

    /** @return list<Action> row actions for the insurer provider-claim queue */
    public static function providerClaim(): array
    {
        return [self::providerClaimSubmit(), self::providerClaimDispute()];
    }

    public static function providerClaimCreate(): Action
    {
        $p = 'health.provider_claims.capture';

        return self::make('providerClaimCreate', $p)->icon('lucide-file-plus')->modalWidth('4xl')
            ->schema([
                Select::make('provider_id')->label(self::f('provider'))->required()->searchable()->live()->options(fn () => self::providers()),
                Select::make('contract_id')->label(self::f('contract'))->required()
                    ->options(fn (callable $get) => $get('provider_id') ? DB::table('provider_contracts')->where('tenant_id', self::tenant())->where('provider_profile_id', $get('provider_id'))->pluck('contract_number', 'id')->all() : []),
                TextInput::make('invoice_reference')->label(self::f('invoice_reference'))->required()->maxLength(120),
                DatePicker::make('service_date')->label(self::f('service_date'))->required(),
                TextInput::make('member_reference')->label(self::f('member_ref'))->maxLength(120),
                TextInput::make('policy_id')->label(self::f('policy_id'))->uuid(),
                TextInput::make('preauth_id')->label(self::f('preauth_id'))->uuid(),
                Repeater::make('lines')->label(self::f('lines'))->required()->minItems(1)->columns(4)->schema([
                    Select::make('medical_service_id')->label(self::f('medical_service'))->searchable()->options(fn () => self::medicalServices()),
                    TextInput::make('provider_code')->label(self::f('provider_code'))->maxLength(64),
                    TextInput::make('quantity')->label(self::f('quantity'))->integer()->minValue(1)->default(1),
                    TextInput::make('unit_price_minor')->label(self::f('unit_price_minor'))->required()->integer()->minValue(0),
                ]),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                WorkflowAction::run($action, $p, function () use ($data) {
                    $data['lines'] = array_values((array) ($data['lines'] ?? []));
                    $d = self::valid($data, [
                        'provider_id' => 'required|uuid', 'contract_id' => 'required|uuid', 'invoice_reference' => 'required|string|max:120', 'service_date' => 'required|date',
                        'member_reference' => 'nullable|string|max:120', 'policy_id' => 'nullable|uuid', 'preauth_id' => 'nullable|uuid',
                        'lines' => 'required|array|min:1|max:200', 'lines.*.medical_service_id' => 'nullable|uuid', 'lines.*.provider_code' => 'nullable|string|max:64',
                        'lines.*.quantity' => 'nullable|integer|min:1', 'lines.*.unit_price_minor' => 'required|integer|min:0',
                    ]);

                    return app(ProviderClaimService::class)->create(self::tenant(), $d, auth()->id());
                }, self::done('providerClaimCreate'));
            });
    }

    public static function providerClaimSubmit(): Action
    {
        $p = 'health.provider_claims.capture';

        return self::make('providerClaimSubmit', $p)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn ($record) => self::val($record, 'status') === 'DRAFT')
            ->action(fn (Action $action, $record) => WorkflowAction::run($action, $p,
                fn () => app(ProviderClaimService::class)->submit(self::tenant(), WorkflowAction::id($record), auth()->id()), self::done('providerClaimSubmit')));
    }

    public static function providerClaimDispute(): Action
    {
        $p = 'health.provider_claims.dispute';

        return self::make('providerClaimDispute', $p)->icon('lucide-message-square-warning')->color('warning')
            ->visible(fn ($record) => in_array(self::val($record, 'status'), ['APPROVED', 'PARTIALLY_APPROVED', 'REJECTED', 'PAYABLE', 'PAID'], true))
            ->schema([Textarea::make('reason')->label(self::f('reason'))->required()->minLength(5)->maxLength(2000)])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ProviderClaimService::class)->dispute(
                self::tenant(), WorkflowAction::id($record), self::valid($data, ['reason' => 'required|string|min:5|max:2000'])['reason'], auth()->user()), self::done('providerClaimDispute')));
    }

    // ----- helpers ---------------------------------------------------------------------------------------------------

    /** @return array<string, string> health provider profiles: id => name */
    private static function providers(): array
    {
        return DB::table('provider_profiles')->join('parties', 'parties.id', '=', 'provider_profiles.party_id')->where('provider_profiles.category', 'HEALTH')
            ->orderBy('parties.display_name')->limit(500)->pluck('parties.display_name', 'provider_profiles.id')->all();
    }

    /** @return array<string, string> */
    private static function medicalServices(): array
    {
        return DB::table('medical_services')->orderBy('code')->limit(1000)->get(['id', 'code', 'name'])->mapWithKeys(fn ($s) => [$s->id => $s->code.' — '.$s->name])->all();
    }

    private static function val(mixed $record, string $key): mixed
    {
        return $record === null ? null : (is_array($record) ? ($record[$key] ?? null) : ($record->{$key} ?? null));
    }

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
