<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Capabilities\CapabilityProfileService;
use App\Application\CarrierOperations\Agreements\CarrierBrokerAgreementService;
use App\Application\CarrierOperations\Setup\CarrierSetupService;
use App\Application\CarrierOperations\Setup\Models\CarrierSetup;
use App\Application\Claims\Execution\ClaimCarrierSignatureVerifier;
use App\Application\Identity\Rbac\PlatformAuthority;
use App\Application\Partners\BookScope;
use App\Application\Partners\PartnerBook;
use App\Filament\Shared\Actions\RegulatoryCrmSupport as S;
use App\Models\Carrier;
use App\Models\Partner;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Insurer onboarding and carrier ↔ broker distribution agreements. Same service, validation and permission as the API:
 *   agreementCreate      POST carrier-broker-agreements                        distribution.agreements.manage  CarrierBrokerAgreementService::create
 *   agreementSetProduct  PUT  carrier-broker-agreements/{a}/products           distribution.agreements.manage  CarrierBrokerAgreementService::setProduct
 *   agreementTransition  POST carrier-broker-agreements/{a}/{activate|suspend|terminate} distribution.agreements.approve CarrierBrokerAgreementService::transition
 *   capabilityDraft      POST carriers/{c}/capability-profiles                 capability_profiles.manage      CapabilityProfileService::draft
 *   signingKeyRegister   POST carriers/{c}/claims-signing-keys                 claims.carrier.keys             ClaimCarrierSignatureVerifier::register
 *   signingKeyRevoke     POST carriers/{c}/claims-signing-keys/{k}/revoke      claims.carrier.keys             ClaimCarrierSignatureVerifier::revoke
 *   setupOpen            POST carriers/{c}/setup                               carrier_setup.manage            CarrierSetupService::open
 *   setupAttest          PUT  carriers/{c}/setup/checklist/{item}              carrier_setup.manage            CarrierSetupService::attest
 *   setupTransition      POST carriers/{c}/setup/transitions                   carrier_setup.manage            CarrierSetupService::transition (submit/activate maker-checker in the service)
 */
final class CarrierOnboardingActions
{
    public const AGREEMENT_ACTIONS = ['activate' => 'ACTIVE', 'suspend' => 'SUSPENDED', 'terminate' => 'TERMINATED'];

    public static function agreementCreate(): Action
    {
        $p = 'distribution.agreements.manage';

        return WorkflowAction::make('agreementCreate', $p, S::L)->icon('lucide-plus')
            ->schema([
                Select::make('carrier_id')->label(S::f('carrier_id'))->required()->searchable()->options(fn () => self::carrierOptions()),
                Select::make('partner_id')->label(S::f('partner_id'))->required()->searchable()
                    ->options(fn () => Partner::with('party')->when(! self::isPlatform(), fn ($q) => $q->where('tenant_id', S::tenant()))->limit(500)->get()
                        ->mapWithKeys(fn ($p) => [$p->id => $p->party?->display_name ?? $p->id])->all()),
                TextInput::make('agreement_number')->label(S::f('agreement_number'))->required()->maxLength(80),
                DatePicker::make('effective_from')->label(S::f('effective_from'))->required(),
                DatePicker::make('effective_until')->label(S::f('effective_until')),
                TagsInput::make('territories')->label(S::f('territories')),
                TagsInput::make('channels')->label(S::f('channels')),
                TextInput::make('source_document')->label(S::f('source_document'))->maxLength(255),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $d = S::check($data, ['carrier_id' => 'required|uuid|exists:carriers,id', 'partner_id' => 'required|uuid|exists:partners,id',
                    'agreement_number' => 'required|string|max:80|unique:carrier_broker_agreements,agreement_number', 'effective_from' => 'required|date',
                    'effective_until' => 'nullable|date|after_or_equal:effective_from', 'territories' => 'sometimes|array', 'territories.*' => 'string|max:32',
                    'channels' => 'sometimes|array', 'channels.*' => 'string|max:32', 'settlement_terms' => 'sometimes|nullable|array', 'source_document' => 'nullable|string|max:255']);
                self::authorizePartner($d['partner_id']);

                return app(CarrierBrokerAgreementService::class)->create(S::user(), $d);
            }, S::done('agreementCreate')));
    }

    public static function agreementSetProduct(): Action
    {
        $p = 'distribution.agreements.manage';
        $flags = ['can_quote', 'can_bind', 'can_collect_premium', 'can_issue_documents', 'can_service_policies', 'can_assist_claims', 'requires_carrier_approval'];

        return WorkflowAction::make('agreementSetProduct', $p, S::L)->icon('lucide-package-plus')
            ->visible(fn (mixed $record) => ! in_array(S::field($record, 'status'), ['TERMINATED', 'EXPIRED'], true))
            ->schema([
                TextInput::make('line_code')->label(S::f('line_code'))->required()->maxLength(32),
                TextInput::make('insurance_product_id')->label(S::f('insurance_product_id'))->uuid(),
                ...array_map(fn ($f) => Toggle::make($f)->label(S::f($f)), $flags),
                TextInput::make('commission_basis_points')->label(S::f('commission_basis_points'))->integer()->minValue(0)->maxValue(10000),
                Select::make('status')->label(S::f('line_status'))->options(S::opts(['ACTIVE', 'INACTIVE'])),
                Textarea::make('reason')->label(S::f('reason'))->maxLength(2000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data, $flags) {
                $id = self::authorizeAgreement(WorkflowAction::id($record));
                $in = S::present($data);
                foreach ($flags as $f) {
                    $in[$f] = (bool) ($data[$f] ?? false); // a toggle always sends its state (false is a value, not "absent")
                }
                $d = S::check($in, ['line_code' => 'required|string|max:32', 'insurance_product_id' => 'nullable|uuid', 'can_quote' => 'sometimes|boolean', 'can_bind' => 'sometimes|boolean',
                    'can_collect_premium' => 'sometimes|boolean', 'can_issue_documents' => 'sometimes|boolean', 'can_service_policies' => 'sometimes|boolean', 'can_assist_claims' => 'sometimes|boolean', 'requires_carrier_approval' => 'sometimes|boolean', 'commission_rule_version_id' => 'nullable|uuid',
                    'commission_basis_points' => 'nullable|integer|min:0|max:10000', 'status' => 'sometimes|in:ACTIVE,INACTIVE', 'reason' => 'nullable|string|max:2000']);

                return app(CarrierBrokerAgreementService::class)->setProduct(S::user(), $id, $d);
            }, S::done('agreementSetProduct')));
    }

    public static function agreementTransition(): Action
    {
        $p = 'distribution.agreements.approve';

        return WorkflowAction::make('agreementTransition', $p, S::L)->icon('lucide-arrow-right-left')
            ->visible(fn (mixed $record) => ! in_array(S::field($record, 'status'), ['TERMINATED', 'EXPIRED'], true))
            ->schema([
                Select::make('action')->label(S::f('agreement_action'))->options(S::opts(array_keys(self::AGREEMENT_ACTIONS), 'agreement_action'))->required(),
                Textarea::make('reason')->label(S::f('reason'))->required()->minLength(5)->maxLength(2000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $id = self::authorizeAgreement(WorkflowAction::id($record));
                $d = S::check($data, ['action' => 'required|in:activate,suspend,terminate', 'reason' => 'required|string|min:5|max:2000']);

                return app(CarrierBrokerAgreementService::class)->transition(S::user(), $id, self::AGREEMENT_ACTIONS[$d['action']], $d['reason']);
            }, S::done('agreementTransition')));
    }

    public static function capabilityDraft(): Action
    {
        $p = 'capability_profiles.manage';

        return WorkflowAction::make('capabilityDraft', $p, S::L)->icon('lucide-sliders-horizontal')
            ->schema([S::jsonField('modes'), Textarea::make('notes')->label(S::f('notes'))->maxLength(2000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $in = ['modes' => S::json($data, 'modes')] + S::present(['notes' => $data['notes'] ?? null]);
                $d = Validator::make($in, ['modes' => 'present|array', 'modes.*.capability' => 'required|string|max:40', 'modes.*.mode' => 'required|string|max:40',
                    'modes.*.scope_product_id' => 'nullable|uuid', 'modes.*.scope_class_code' => 'nullable|string|max:64',
                    'modes.*.config' => 'nullable|array', 'modes.*.fallback_mode' => 'nullable|string|max:40', 'notes' => 'nullable|string|max:2000'])->validate();

                return app(CapabilityProfileService::class)->draft(Carrier::findOrFail(WorkflowAction::id($record)), $d['modes'], S::user(), $d['notes'] ?? null);
            }, S::done('capabilityDraft')));
    }

    /** The secret is shown once, as the API returns it once; it is stored encrypted by the verifier. */
    public static function signingKeyRegister(): Action
    {
        $p = 'claims.carrier.keys';

        return WorkflowAction::make('signingKeyRegister', $p, S::L)->icon('lucide-key-round')
            ->schema([TextInput::make('key_id')->label(S::f('key_id'))->minLength(6)->maxLength(64)])
            ->action(function (Action $action, mixed $record, array $data) use ($p) {
                $key = WorkflowAction::run($action, $p, function () use ($record, $data) {
                    $carrier = WorkflowAction::id($record);
                    abort_unless(DB::table('carriers')->where('id', $carrier)->exists(), 404);
                    $d = S::check($data, ['key_id' => 'nullable|string|min:6|max:64|regex:/^[A-Za-z0-9_\-]+$/']);

                    return app(ClaimCarrierSignatureVerifier::class)->register($carrier, S::user(), $d['key_id'] ?? null);
                }, S::done('signingKeyRegister'));
                if (is_array($key)) {
                    Notification::make()->warning()->persistent()->title(__(S::L.'.signingKeyRegister.secret_title', ['key' => $key['key_id']]))->body($key['secret'])->send();
                }
            });
    }

    public static function signingKeyRevoke(): Action
    {
        $p = 'claims.carrier.keys';

        return WorkflowAction::make('signingKeyRevoke', $p, S::L)->icon('lucide-key-square')->color('danger')
            ->visible(fn (mixed $record) => self::activeKeys(WorkflowAction::id($record)) !== [])
            ->schema([Select::make('key_id')->label(S::f('key_id'))->options(fn (mixed $record) => self::activeKeys(WorkflowAction::id($record)))->required()])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['key_id' => 'required|string|max:64']);
                app(ClaimCarrierSignatureVerifier::class)->revoke(WorkflowAction::id($record), $d['key_id']);

                return ['key_id' => $d['key_id'], 'status' => 'REVOKED'];
            }, S::done('signingKeyRevoke')));
    }

    public static function setupOpen(): Action
    {
        $p = 'carrier_setup.manage';

        return WorkflowAction::make('setupOpen', $p, S::L)->icon('lucide-flag')
            ->visible(fn (mixed $record) => S::field($record, 'setup_status') === null)
            ->schema([Textarea::make('notes')->label(S::f('notes'))->maxLength(2000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['notes' => 'nullable|string|max:2000']);

                return app(CarrierSetupService::class)->open(Carrier::findOrFail(WorkflowAction::id($record)), S::user(), $d['notes'] ?? null);
            }, S::done('setupOpen')));
    }

    public static function setupAttest(): Action
    {
        $p = 'carrier_setup.manage';

        return WorkflowAction::make('setupAttest', $p, S::L)->icon('lucide-list-checks')
            ->visible(fn (mixed $record) => S::field($record, 'setup_status') !== null)
            ->schema([
                Select::make('item')->label(S::f('item'))->required()->options(fn (mixed $record) => self::checklistItems(WorkflowAction::id($record))),
                Select::make('status')->label(S::f('attest_status'))->options(S::opts(['COMPLETE', 'NOT_APPLICABLE', 'PENDING'], 'attest_status'))->required(),
                TextInput::make('evidence_reference')->label(S::f('evidence_reference'))->maxLength(255),
                Textarea::make('notes')->label(S::f('notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['item' => 'required|string|max:64', 'status' => 'required|in:COMPLETE,NOT_APPLICABLE,PENDING', 'evidence_reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000']);
                $setup = CarrierSetup::where('carrier_id', WorkflowAction::id($record))->firstOrFail();

                return app(CarrierSetupService::class)->attest($setup, strtoupper($d['item']), $d['status'], $d['evidence_reference'] ?? null, $d['notes'] ?? null, S::user());
            }, S::done('setupAttest')));
    }

    public static function setupTransition(): Action
    {
        $p = 'carrier_setup.manage';

        return WorkflowAction::make('setupTransition', $p, S::L)->icon('lucide-arrow-right-circle')
            ->visible(fn (mixed $record) => S::field($record, 'setup_status') !== null)
            ->schema([
                Select::make('event')->label(S::f('event'))->required()->options(function (mixed $record) {
                    $setup = CarrierSetup::where('carrier_id', WorkflowAction::id($record))->first();

                    return $setup ? S::opts(app(CarrierSetupService::class)->available($setup, S::user()), 'setup_event') : [];
                }),
                Textarea::make('reason')->label(S::f('reason'))->maxLength(2000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['event' => 'required|string|max:48', 'reason' => 'nullable|string|max:2000']);
                $setup = CarrierSetup::where('carrier_id', WorkflowAction::id($record))->firstOrFail();

                return app(CarrierSetupService::class)->transition($setup, $d['event'], S::user(), $d['reason'] ?? null);
            }, S::done('setupTransition')));
    }

    public static function carrierOptions(): array
    {
        return Carrier::with('party')->limit(500)->get()->mapWithKeys(fn ($c) => [$c->id => $c->party?->display_name ?? $c->cima_code])->sort()->all();
    }

    /** @return array<string, string> non-system checklist items (code => label) */
    public static function checklistItems(string $carrierId): array
    {
        $setup = CarrierSetup::where('carrier_id', $carrierId)->first();
        if ($setup === null) {
            return [];
        }

        return collect(app(CarrierSetupService::class)->items($setup))->reject(fn ($i) => ! empty($i['system']))
            ->mapWithKeys(fn ($i) => [$i['code'] => $i['label']])->all();
    }

    /** @return array<string, string> */
    public static function activeKeys(string $carrierId): array
    {
        return DB::table('claim_carrier_signing_keys')->where(['carrier_id' => $carrierId, 'status' => 'ACTIVE'])->orderBy('created_at')->pluck('key_id', 'key_id')->all();
    }

    /** Mirror of CarrierBrokerAgreementController::authorizeAgreement. */
    private static function authorizeAgreement(string $agreement): string
    {
        $partnerId = DB::table('carrier_broker_agreements')->where('id', $agreement)->value('partner_id');
        abort_if($partnerId === null, 404);
        self::authorizePartner($partnerId);

        return $agreement;
    }

    /** Mirror of CarrierBrokerAgreementController::authorizePartner / ownPartner (partner-scoped callers see only their own company). */
    private static function authorizePartner(string $partnerId): void
    {
        $book = app(PartnerBook::class);
        $own = null;
        if ($book->isBookScoped(S::user())) {
            $partner = $book->partner(S::user())?->getKey();
            $own = $partner === null && BookScope::tenantIsCompany() ? null : (string) $partner;
        }
        abort_if($own !== null && $own !== $partnerId, 404);
        if (! self::isPlatform()) {
            abort_unless(DB::table('partners')->where(['id' => $partnerId, 'tenant_id' => S::tenant()])->exists(), 404);
        }
    }

    private static function isPlatform(): bool
    {
        return app(PlatformAuthority::class)->isPlatformTenant(S::tenant());
    }
}
