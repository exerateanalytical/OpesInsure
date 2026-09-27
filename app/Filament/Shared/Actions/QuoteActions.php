<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\CarrierOperations\QuoteRequests\Models\CarrierQuoteRequest;
use App\Application\CarrierOperations\QuoteRequests\QuoteRequestService;
use App\Application\Distribution\Execution\ExecutionContext;
use App\Application\Identity\CarrierScopeResolver;
use App\Application\Quotes\QuoteComparisonService;
use App\Domain\Tenancy\TenantContext;
use App\Application\Partners\PartnerBook;
use App\Application\Quotes\Adapters\ManualQuoteProvider;
use App\Application\Quotes\Adapters\QuoteProviderRegistry;
use App\Application\Quotes\QuotePremiumOverrideService;
use App\Application\Quotes\QuoteService;
use App\Application\Underwriting\ProposalService;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/**
 * Quote detail-page actions. Same services / permissions as the API (the API also lets an owner-scoped customer act on
 * their own quote; the web back office is staff-only, so the permission is always required here):
 *   rate                  POST quotes/{q}/rate                                              quotes.rate                                QuoteService::rate
 *   send                  POST quotes/{q}/send                                              quotes.send                                QuoteService::send
 *   requestCarrierQuote   POST quotes/{q}/carrier-requests                                  quotes.carrier_requests.create             ManualQuoteProvider → QuoteRequestService
 *   recordCarrierOffer    POST quotes/{q}/carrier-requests/{r}/offer-on-behalf              quotes.carrier_requests.record_on_behalf   QuoteRequestService::recordOffer
 *   requestOverride       POST quotes/{q}/offers/{o}/premium-overrides                      quotes.premium_override.request            QuotePremiumOverrideService::request
 *   decideOverride        POST quotes/{q}/offers/{o}/premium-overrides/{x}/decision         quotes.premium_override.approve            QuotePremiumOverrideService::decide
 *   convertToProposal     POST quotes/{q}/offers/{o}/accept + POST proposals                (routes have no permission gate)           QuoteService::accept + ProposalService::create
 *   generate              POST quotes/{q}/generate                                          quotes.manage                              QuoteService::generate
 *   decline               POST quotes/{q}/decline                                           quotes.manage                              QuoteService::decline
 *   cancel                POST quotes/{q}/cancel                                            quotes.manage                              QuoteService::cancel
 *   applyOverride         POST quotes/{q}/offers/{o}/premium-overrides/{x}/apply            quotes.premium_override.approve            QuotePremiumOverrideService::applyEffective
 *   saveComparison        POST quote-comparisons                                            quotes.read                                QuoteComparisonService::save
 *   cancelCarrierRequest  POST quotes/{q}/carrier-requests/{r}/cancel                       quotes.carrier_requests.create             QuoteRequestService::cancel
 *   declineCarrierOnBehalf POST quotes/{q}/carrier-requests/{r}/decline-on-behalf           quotes.carrier_requests.record_on_behalf   QuoteRequestService::decline (BROKER_ON_BEHALF)
 *   carrierStart          POST carrier/quote-requests/{r}/start      (insurer register)     carrier.quote_requests.respond             QuoteRequestService::start
 *   carrierDecline        POST carrier/quote-requests/{r}/decline    (insurer register)     carrier.quote_requests.respond             QuoteRequestService::decline (INSURER_PORTAL)
 * The carrier-side actions resolve the request inside the tenant and, for insurer users, their own carrier (CarrierScopeResolver),
 * exactly as QuoteRequestController::carrierScoped.
 * Partner users only act on quotes of clients in their own book (PartnerBook::assertInBook), as the API.
 */
final class QuoteActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::rate(), self::send(), self::requestCarrierQuote(), self::recordCarrierOffer(), self::requestOverride(), self::decideOverride(), self::applyOverride(), self::convertToProposal(),
            self::saveComparison(), self::generate(), self::cancelCarrierRequest(), self::declineCarrierOnBehalf(), self::decline(), self::cancel()])
            ->label(__('workflow_actions.quote_group'))->icon('lucide-zap')->button();
    }

    public static function rate(): Action
    {
        $p = 'quotes.rate';

        return WorkflowAction::make('quoteRate', $p)->icon('lucide-calculator')->requiresConfirmation()
            ->action(fn (Action $action, Quote $record) => WorkflowAction::run($action, $p, fn () => app(QuoteService::class)->rate(self::book($record), auth()->user())));
    }

    public static function send(): Action
    {
        $p = 'quotes.send';

        return WorkflowAction::make('quoteSend', $p)->icon('lucide-send')
            ->schema([
                Select::make('channel')->label(__('workflow_actions.fields.channel'))->options(WorkflowAction::options(QuoteService::SHARE_CHANNELS))->required(),
                TextInput::make('recipient')->label(__('workflow_actions.fields.recipient'))->maxLength(191),
            ])
            ->action(fn (Action $action, Quote $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(QuoteService::class)->send(self::book($record), $data['channel'], filled($data['recipient'] ?? null) ? $data['recipient'] : null, auth()->user())));
    }

    public static function requestCarrierQuote(): Action
    {
        $p = 'quotes.carrier_requests.create';

        return WorkflowAction::make('quoteRequestCarrier', $p)->icon('lucide-building-2')
            ->schema([
                Select::make('carrier_id')->label(__('workflow_actions.fields.carrier'))->required()->searchable()->live()
                    ->options(fn () => Carrier::with('party')->where('status', 'ACTIVE')->get()->mapWithKeys(fn ($c) => [$c->id => $c->party?->display_name ?? $c->cima_code])),
                Select::make('product_id')->label(__('workflow_actions.fields.product'))->searchable()
                    ->options(fn (callable $get) => $get('carrier_id') ? InsuranceProduct::where('carrier_id', $get('carrier_id'))->pluck('name', 'id') : []),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, Quote $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $product = filled($data['product_id'] ?? null) ? $data['product_id'] : null;
                $adapter = app(QuoteProviderRegistry::class)->for($data['carrier_id'], $product);
                if (! $adapter instanceof ManualQuoteProvider) {
                    throw new ApiProblemException('CARRIER_NOT_MANUAL', 409, 'This insurer does not quote manually; its offers come from rating.', [], ['execution_mode' => $adapter->executionMode()]);
                }

                return $adapter->execute(new ExecutionContext('quote_request', null, $data['carrier_id'], $product,
                    ['quote_id' => $record->id, 'actor' => auth()->user(), 'notes' => $data['notes'] ?? null]));
            }));
    }

    public static function recordCarrierOffer(): Action
    {
        $p = 'quotes.carrier_requests.record_on_behalf';
        $open = fn (Quote $q) => CarrierQuoteRequest::where('quote_id', $q->id)->whereIn('status', CarrierQuoteRequest::OPEN_STATES);

        return WorkflowAction::make('quoteRecordCarrierOffer', $p)->icon('lucide-file-check')
            ->visible(fn (Quote $record) => $open($record)->exists())
            ->schema([
                Select::make('request_id')->label(__('workflow_actions.fields.carrier_request'))->required()
                    ->options(fn (Quote $record) => $open($record)->pluck('request_number', 'id')),
                TextInput::make('premium_minor')->label(__('workflow_actions.fields.premium_minor'))->integer()->minValue(0)->required(),
                TextInput::make('tax_minor')->label(__('workflow_actions.fields.tax_minor'))->integer()->minValue(0),
                TextInput::make('fee_minor')->label(__('workflow_actions.fields.fee_minor'))->integer()->minValue(0),
                DatePicker::make('valid_until')->label(__('workflow_actions.fields.valid_until'))->required(),
                TextInput::make('carrier_reference')->label(__('workflow_actions.fields.carrier_reference'))->maxLength(120),
                Select::make('evidence_document_id')->label(__('workflow_actions.fields.evidence_document'))->required()->searchable()
                    ->options(fn (Quote $record) => DB::table('documents')->where('tenant_id', $record->tenant_id)->latest()->limit(200)->pluck('id', 'id')),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->requiresConfirmation()
            ->action(function (Action $action, Quote $record, array $data) use ($p, $open) {
                $req = $open($record)->findOrFail($data['request_id']);
                $d = array_filter(collect($data)->except('request_id')->all(), fn ($v) => filled($v));

                return WorkflowAction::run($action, $p, fn () => app(QuoteRequestService::class)->recordOffer($req, $d, auth()->user(), 'BROKER_ON_BEHALF'));
            });
    }

    public static function requestOverride(): Action
    {
        $p = 'quotes.premium_override.request';

        return WorkflowAction::make('quoteRequestOverride', $p)->icon('lucide-sliders-horizontal')
            ->visible(fn (Quote $record) => $record->offers()->exists())
            ->schema([
                Select::make('offer_id')->label(__('workflow_actions.fields.offer'))->required()->options(fn (Quote $record) => self::offerOptions($record->offers()->get())),
                TextInput::make('premium_minor')->label(__('workflow_actions.fields.premium_minor'))->integer()->minValue(1)->required(),
                Select::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->options(WorkflowAction::options(QuotePremiumOverrideService::REASONS))->required(),
                Textarea::make('justification')->label(__('workflow_actions.fields.justification'))->required()->minLength(10)->maxLength(2000),
            ])
            ->action(fn (Action $action, Quote $record, array $data) => WorkflowAction::run($action, $p, fn () => app(QuotePremiumOverrideService::class)->request(
                $q = self::book($record), $q->offers()->findOrFail($data['offer_id']), (int) $data['premium_minor'], $data['reason_code'], $data['justification'], auth()->user())));
    }

    public static function decideOverride(): Action
    {
        $p = 'quotes.premium_override.approve';
        $pending = fn (Quote $q) => $q->offers()->whereIn('premium_override_id', DB::table('engine_overrides')->where('status', 'REQUESTED')->select('id'));

        return WorkflowAction::make('quoteDecideOverride', $p)->icon('lucide-badge-check')->requiresConfirmation()
            ->visible(fn (Quote $record) => $pending($record)->exists())
            ->schema([
                Select::make('offer_id')->label(__('workflow_actions.fields.offer'))->required()->options(fn (Quote $record) => self::offerOptions($pending($record)->get())),
                Select::make('decision')->label(__('workflow_actions.fields.outcome'))->options(['APPROVED' => __('workflow_actions.accept'), 'REJECTED' => __('workflow_actions.reject')])->required()->live(),
                Textarea::make('note')->label(__('workflow_actions.fields.note'))->maxLength(1000)->required(fn (callable $get) => $get('decision') === 'REJECTED'),
            ])
            ->action(function (Action $action, Quote $record, array $data) use ($p) {
                $q = self::book($record);
                $offer = $q->offers()->findOrFail($data['offer_id']);

                return WorkflowAction::run($action, $p, fn () => app(QuotePremiumOverrideService::class)->decide($q, $offer, (string) $offer->premium_override_id, $data['decision'] === 'APPROVED', $data['note'] ?? null, auth()->user()));
            });
    }

    public static function convertToProposal(): Action
    {
        $offers = fn (Quote $q) => $q->offers()->whereIn('status', ['OFFERED', 'ACCEPTED']);

        return WorkflowAction::make('quoteConvert', null)->icon('lucide-circle-arrow-right')->requiresConfirmation()
            ->visible(fn (Quote $record) => $offers($record)->exists())
            ->schema([Select::make('offer_id')->label(__('workflow_actions.fields.offer'))->required()->options(fn (Quote $record) => self::offerOptions($offers($record)->get()))])
            ->action(fn (Action $action, Quote $record, array $data) => WorkflowAction::run($action, null, function () use ($record, $data, $offers) {
                $q = self::book($record);
                $offer = $offers($q)->findOrFail($data['offer_id']);
                if ($offer->status !== 'ACCEPTED') {
                    app(QuoteService::class)->accept($q, $offer, auth()->user());
                }

                return app(ProposalService::class)->create(Tenant::findOrFail($q->tenant_id), $offer->refresh(), ['quote_offer_id' => $offer->id, 'party_id' => $q->party_id], auth()->user());
            }));
    }

    public static function generate(): Action
    {
        $p = 'quotes.manage';

        return WorkflowAction::make('quoteGenerate', $p)->icon('lucide-file-text')->requiresConfirmation()
            ->action(fn (Action $action, Quote $record) => WorkflowAction::run($action, $p, fn () => app(QuoteService::class)->generate(self::book($record), auth()->user())));
    }

    public static function decline(): Action
    {
        $p = 'quotes.manage';

        return WorkflowAction::make('quoteDecline', $p)->icon('lucide-thumbs-down')->color('danger')->requiresConfirmation()
            ->schema([
                Select::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->options(WorkflowAction::options(QuoteService::DECLINE_REASONS, 'quote_decline'))->required(),
                Textarea::make('note')->label(__('workflow_actions.fields.note'))->maxLength(1000),
            ])
            ->action(fn (Action $action, Quote $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(QuoteService::class)->decline(self::book($record), $data['reason_code'], filled($data['note'] ?? null) ? $data['note'] : null, auth()->user())));
    }

    public static function cancel(): Action
    {
        $p = 'quotes.manage';

        return WorkflowAction::make('quoteCancel', $p)->icon('lucide-circle-x')->color('danger')->requiresConfirmation()
            ->action(fn (Action $action, Quote $record) => WorkflowAction::run($action, $p, fn () => app(QuoteService::class)->cancel(self::book($record), auth()->user())));
    }

    public static function applyOverride(): Action
    {
        $p = 'quotes.premium_override.approve';
        $withOverride = fn (Quote $q) => $q->offers()->whereNotNull('premium_override_id');

        return WorkflowAction::make('quoteApplyOverride', $p)->icon('lucide-check-check')->requiresConfirmation()
            ->visible(fn (Quote $record) => $withOverride($record)->exists())
            ->schema([Select::make('offer_id')->label(__('workflow_actions.fields.offer'))->required()->options(fn (Quote $record) => self::offerOptions($withOverride($record)->get()))])
            ->action(function (Action $action, Quote $record, array $data) use ($p, $withOverride) {
                $q = self::book($record);
                $offer = $withOverride($q)->findOrFail($data['offer_id']);

                return WorkflowAction::run($action, $p, fn () => app(QuotePremiumOverrideService::class)->applyEffective($q, $offer, (string) $offer->premium_override_id));
            });
    }

    public static function saveComparison(): Action
    {
        $p = 'quotes.read';

        return WorkflowAction::make('quoteSaveComparison', $p)->icon('lucide-columns-3')
            ->visible(fn (Quote $record) => $record->offers()->exists())
            ->schema([CheckboxList::make('offer_ids')->label(__('workflow_actions.fields.offers'))->options(fn (Quote $record) => self::offerOptions($record->offers()->get()))
                ->maxItems(QuoteComparisonService::MAX)])
            ->action(fn (Action $action, Quote $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(QuoteComparisonService::class)->save(self::book($record), array_values($data['offer_ids'] ?? []), auth()->user())));
    }

    public static function cancelCarrierRequest(): Action
    {
        $p = 'quotes.carrier_requests.create';
        $open = fn (Quote $q) => CarrierQuoteRequest::where('quote_id', $q->id)->whereIn('status', CarrierQuoteRequest::OPEN_STATES);

        return WorkflowAction::make('quoteCancelCarrierRequest', $p)->icon('lucide-ban')->color('danger')->requiresConfirmation()
            ->visible(fn (Quote $record) => $open($record)->exists())
            ->schema([
                Select::make('request_id')->label(__('workflow_actions.fields.carrier_request'))->required()->options(fn (Quote $record) => $open($record)->pluck('request_number', 'id')),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->minLength(3)->maxLength(500),
            ])
            ->action(function (Action $action, Quote $record, array $data) use ($p) {
                $req = CarrierQuoteRequest::where('quote_id', self::book($record)->id)->findOrFail($data['request_id']);

                return WorkflowAction::run($action, $p, fn () => app(QuoteRequestService::class)->cancel($req, auth()->user(), $data['reason']));
            });
    }

    public static function declineCarrierOnBehalf(): Action
    {
        $p = 'quotes.carrier_requests.record_on_behalf';
        $open = fn (Quote $q) => CarrierQuoteRequest::where('quote_id', $q->id)->whereIn('status', CarrierQuoteRequest::OPEN_STATES);

        return WorkflowAction::make('quoteDeclineCarrierOnBehalf', $p)->icon('lucide-file-x')->requiresConfirmation()
            ->visible(fn (Quote $record) => $open($record)->exists())
            ->schema([
                Select::make('request_id')->label(__('workflow_actions.fields.carrier_request'))->required()->options(fn (Quote $record) => $open($record)->pluck('request_number', 'id')),
                TextInput::make('decline_reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                Select::make('evidence_document_id')->label(__('workflow_actions.fields.evidence_document'))->required()->searchable()
                    ->options(fn (Quote $record) => DB::table('documents')->where('tenant_id', $record->tenant_id)->latest()->limit(200)->pluck('id', 'id')),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(function (Action $action, Quote $record, array $data) use ($p) {
                $req = CarrierQuoteRequest::where('quote_id', self::book($record)->id)->findOrFail($data['request_id']);
                $d = array_filter(collect($data)->except('request_id')->all(), fn ($v) => filled($v));

                return WorkflowAction::run($action, $p, fn () => app(QuoteRequestService::class)->decline($req, $d, auth()->user(), 'BROKER_ON_BEHALF'));
            });
    }

    /** Insurer work queue (QuoteRequestsRegister row action): take the request into work. */
    public static function carrierStart(): Action
    {
        $p = 'carrier.quote_requests.respond';

        return WorkflowAction::make('carrierQuoteStart', $p)->icon('lucide-play')->requiresConfirmation()
            ->visible(fn ($record) => ($record->status ?? null) === 'REQUESTED')
            ->action(fn (Action $action, $record) => WorkflowAction::run($action, $p,
                fn () => app(QuoteRequestService::class)->start(self::carrierRequest($record), auth()->user())));
    }

    /** Insurer work queue: decline to quote (source INSURER_PORTAL). */
    public static function carrierDecline(): Action
    {
        $p = 'carrier.quote_requests.respond';

        return WorkflowAction::make('carrierQuoteDecline', $p)->icon('lucide-thumbs-down')->color('danger')->requiresConfirmation()
            ->visible(fn ($record) => in_array($record->status ?? null, CarrierQuoteRequest::OPEN_STATES, true))
            ->schema([
                TextInput::make('decline_reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(QuoteRequestService::class)->decline(
                self::carrierRequest($record), array_filter($data, fn ($v) => filled($v)), auth()->user(), 'INSURER_PORTAL')));
    }

    /** Same scoping as QuoteRequestController::carrierScoped: the tenant, and the insurer user's own carrier. */
    private static function carrierRequest(mixed $record): CarrierQuoteRequest
    {
        $tenantId = app(TenantContext::class)->id();
        $carrierId = app(CarrierScopeResolver::class)->carrierIdFor(auth()->user(), $tenantId);

        return CarrierQuoteRequest::where('tenant_id', $tenantId)->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))->findOrFail(WorkflowAction::id($record));
    }

    private static function book(Quote $quote): Quote
    {
        app(PartnerBook::class)->assertInBook(auth()->user(), $quote->party_id);

        return $quote;
    }

    private static function offerOptions($offers): array
    {
        return collect($offers)->mapWithKeys(fn (QuoteOffer $o) => [$o->id => $o->status.' · '.number_format((int) $o->premium_minor).' '.$o->currency])->all();
    }
}
