<?php

declare(strict_types=1);

namespace App\Application\Agents;

use App\Application\Audit\AuditWriter;
use App\Application\Commissions\Rules\CommissionRuleResolver;
use App\Application\Demo\DemoPersonas;
use App\Application\Notifications\CustomerNotifier;
use App\Application\Notifications\NotificationCatalog;
use App\Application\Payments\PaymentInitiationService;
use App\Application\Payments\PaymentRequestService;
use App\Application\Quotes\QuoteService;
use App\Application\Underwriting\Proposal\ProposalDeclarations;
use App\Application\Underwriting\ProposalMachine;
use App\Application\Underwriting\ProposalService;
use App\Models\InsuranceLine;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Agent-assisted sale (mobile app/agent/sales/*, web /account/book/payments) on the SAME rails as the customer purchase:
 *
 *   quote   QuoteService::submit (risk facts validated exactly as POST /quotes: RiskFactsProcessor, line risk_schema,
 *           cover choices, QUOTE completeness rules) + QuoteService::rate. Nothing is invented: the agent sends the
 *           client's real risk facts captured with the same risk schema the customer quote wizard renders.
 *   advance (one server-driven "next step" per tap, POST sales/{id}/payment-request):
 *     1. no application yet → QuoteService::accept (the chosen offer) + ProposalService::create for the client, and the
 *        client is notified to open the application, answer the declarations and accept the contract terms themselves.
 *     2. the application waits on the client (questions / attestation / terms) or on the underwriter → nothing is
 *        bypassed; the client is reminded (client steps only).
 *     3. PAYMENT_PENDING and the CLIENT accepted the terms (TERMS_ACCEPTANCE declared by a user of the client's own
 *        party) → PaymentRequestService::create + PaymentInitiationService::initiate: the operator (MTN MoMo / Orange
 *        Money) prompts the client's phone and the client authorises with their own PIN. Settlement → issuance is the
 *        normal pipeline (provider callback → settlement → policy), never this service.
 * Idempotency: one lock per sale, one active payment request per application (reused whoever created it), a
 * deterministic key per attempt ("agent-sale:{proposal}:{n}", n = failed attempts + 1).
 */
final class AssistedSaleService
{
    public const PROVIDERS = ['mtn_momo', 'orange_money', 'fake'];

    private const PAYMENT_TERMINAL = ['FAILED', 'EXPIRED', 'CANCELLED'];

    private const PAYMENT_DONE = ['SUCCEEDED', 'REFUND_PENDING', 'REFUNDED'];

    private const UNDERWRITING = ['SUBMITTED', 'UNDER_REVIEW', 'INFORMATION_REQUIRED', 'RESUBMITTED', 'COUNTEROFFERED', 'APPROVED'];

    public function __construct(
        private QuoteService $quotes,
        private ProposalService $proposals,
        private ProposalDeclarations $declarations,
        private PaymentRequestService $payments,
        private PaymentInitiationService $initiation,
        private CustomerNotifier $notifier,
        private CommissionRuleResolver $commissionRules,
        private AgentPartnerResolver $partners,
        private AuditWriter $audit,
    ) {}

    /** @param array{product:string, payment_phone_e164:string, provider?:?string, risk_facts:array} $data */
    public function create(Tenant $tenant, TenantCustomer $customer, array $data, User $agent): Quote
    {
        $line = strtoupper(trim($data['product']));
        if (! InsuranceLine::where(['code' => $line, 'status' => 'ACTIVE'])->exists()) {
            throw ValidationException::withMessages(['product' => __('validation.exists', ['attribute' => 'product'])]);
        }
        $quote = $this->quotes->submit($tenant, $customer->party_id, ['line_code' => $line, 'channel' => 'AGENT', 'risk_facts' => $data['risk_facts']], $agent);
        $quote = $this->quotes->rate($quote, $agent);
        $quote->update(['comparison_context' => array_merge($quote->comparison_context ?? [], array_filter([
            'assisted_sale' => true, 'agent_user_id' => $agent->id, 'customer_id' => $customer->id,
            'payment_phone_e164' => $data['payment_phone_e164'], 'payment_provider' => $data['provider'] ?? null,
        ], fn ($v) => $v !== null))]);
        $this->audit->record('agent.sale.created', 'quote', $quote->id, ['line_code' => $line, 'offers' => $quote->offers()->count()]);

        return $quote->refresh();
    }

    /**
     * Performs the next step of the sale (see class doc). Safe to call repeatedly: a second tap while a step runs
     * waits for it, and a tap after it finds the step done and returns the current state.
     *
     * @param array{offer_id?:?string, provider?:?string, payment_phone_e164?:?string} $input
     */
    public function advance(Quote $quote, TenantCustomer $customer, array $input, User $agent): void
    {
        Cache::lock("agent-sale:{$quote->id}", 30)->block(10, function () use ($quote, $customer, $input, $agent): void {
            $quote->refresh();
            $ctx = $quote->comparison_context ?? [];
            $phone = $input['payment_phone_e164'] ?? null ?: ($ctx['payment_phone_e164'] ?? null) ?: $this->customerPhone($customer);
            $provider = $input['provider'] ?? null ?: ($ctx['payment_provider'] ?? null) ?: self::providerForPhone($phone);
            $quote->update(['comparison_context' => array_merge($ctx, array_filter(['payment_phone_e164' => $phone, 'payment_provider' => $provider], fn ($v) => $v !== null))]);

            $proposal = $this->proposalFor($quote);
            if ($proposal === null) {
                $this->openApplication($quote, $customer, $input['offer_id'] ?? null, $agent);

                return;
            }
            if ($this->policyId($proposal) !== null || $this->succeededPayment($proposal) !== null) {
                return; // paid / issued: nothing left to request
            }
            if ($proposal->status !== 'PAYMENT_PENDING' || ! $this->clientAcceptedTerms($proposal)) {
                if (in_array($proposal->status, [...ProposalMachine::PRE_SUBMISSION, 'INFORMATION_REQUIRED', 'COUNTEROFFERED', 'PAYMENT_PENDING'], true)) {
                    $this->remindClient($quote, $proposal);
                }

                return;
            }
            $this->requestPayment($quote, $proposal, $phone, $provider, $agent);
        });
    }

    /** Sale as the agent app and the web book render it; statuses are read from the server records, never inferred. */
    public function present(Quote $quote, TenantCustomer $customer, User $agent): array
    {
        $ctx = $quote->comparison_context ?? [];
        $offers = $quote->offers()->with(['carrier.party', 'product'])->orderBy('comparison_rank')->get();
        $proposal = $this->proposalFor($quote);
        $chosen = $proposal ? $offers->firstWhere('id', $proposal->quote_offer_id) : null;
        $offer = $chosen ?? $offers->firstWhere('status', 'ACCEPTED') ?? $offers->firstWhere('status', 'OFFERED') ?? $offers->first();
        $payment = $proposal ? $this->latestPayment($proposal) : null;
        $policyId = $proposal ? $this->policyId($proposal) : null;
        $policy = $policyId ? Policy::find($policyId) : null;
        $paymentStatus = $payment?->status;
        $paid = $policy !== null || in_array($paymentStatus, self::PAYMENT_DONE, true);
        $termsAccepted = $proposal ? $this->clientAcceptedTerms($proposal) : false;
        $inFlight = $payment !== null && ! $paid && ! in_array($paymentStatus, self::PAYMENT_TERMINAL, true);

        [$status, $next] = match (true) {
            $policy !== null => ['ISSUED', 'NONE'],
            $paid => ['PAID', 'AWAIT_ISSUANCE'],
            $proposal === null && $offer === null => [$quote->status === 'REFERRED' || $quote->lifecycle_state === 'REFERRED' ? 'REFERRED' : 'NO_OFFER', 'NONE'],
            $proposal === null => ['QUOTED', 'SEND_TO_CLIENT'],
            in_array($proposal->status, ProposalMachine::TERMINAL, true) => [$proposal->status, 'NONE'],
            $inFlight => ['PAYMENT_PROCESSING', 'AWAIT_PAYMENT'],
            $proposal->status === 'PAYMENT_PENDING' && ! $termsAccepted => ['AWAITING_CLIENT', 'AWAIT_CLIENT'],
            $proposal->status === 'PAYMENT_PENDING' => ['PAYMENT_PENDING', $payment !== null ? 'RETRY_PAYMENT' : 'REQUEST_PAYMENT'],
            in_array($proposal->status, ['INFORMATION_REQUIRED', 'COUNTEROFFERED'], true) => ['AWAITING_CLIENT', 'AWAIT_CLIENT'],
            in_array($proposal->status, self::UNDERWRITING, true) => ['UNDER_REVIEW', 'AWAIT_UNDERWRITING'],
            default => ['AWAITING_CLIENT', 'AWAIT_CLIENT'],
        };

        return [
            'id' => $quote->id, 'quote_number' => $quote->quote_number, 'customer_id' => $customer->id, 'customer_name' => $customer->party?->display_name ?? 'Client',
            'product' => $quote->line_code, 'status' => $status, 'next_action' => $next,
            'premium_minor' => (int) ($offer?->total_minor ?? 0), 'currency' => 'XAF', 'expires_at' => ($offer?->valid_until ?? $quote->expires_at)?->toIso8601String(),
            'selected_offer_id' => $offer?->id, 'carrier_name' => $offer ? $this->carrierName($offer) : null,
            'offers' => $offers->whereIn('status', ['OFFERED', 'ACCEPTED'])->values()->map(fn (QuoteOffer $o) => [
                'id' => $o->id, 'carrier_name' => $this->carrierName($o), 'product_name' => $o->product?->name, 'premium_minor' => (int) $o->premium_minor,
                'total_minor' => (int) $o->total_minor, 'status' => $o->status, 'valid_until' => $o->valid_until?->toIso8601String(),
            ])->all(),
            'proposal_id' => $proposal?->id, 'proposal_number' => $proposal?->proposal_number, 'proposal_status' => $proposal?->status,
            'client_terms_accepted' => $termsAccepted,
            'payment_phone_e164' => $ctx['payment_phone_e164'] ?? '', 'payment_provider' => $payment?->provider ?? ($ctx['payment_provider'] ?? null),
            // NOT_REQUESTED | CUSTOMER_PROMPTED (operator prompt pending on the client's phone) | PAID | FAILED | EXPIRED | CANCELLED
            'payment_status' => $paid ? 'PAID' : ($payment === null ? 'NOT_REQUESTED' : ($inFlight ? 'CUSTOMER_PROMPTED' : $paymentStatus)),
            'payment' => $payment ? [
                'id' => $payment->id, 'status' => $payment->status, 'provider' => $payment->provider, 'amount_minor' => (int) $payment->amount_minor,
                'prompted_at' => $payment->customer_prompted_at?->toIso8601String(), 'expires_at' => $payment->expires_at?->toIso8601String(),
            ] : null,
            'payment_failure_reason' => $payment && in_array($paymentStatus, self::PAYMENT_TERMINAL, true) ? $this->failureReason($payment) : null,
            'payment_verified_at' => $paid ? (($payment?->reconciled_at ?? $payment?->updated_at)?->toIso8601String() ?? $policy?->created_at?->toIso8601String()) : null,
            'issuance_status' => $policy ? 'ISSUED' : ($paid ? 'PENDING' : 'NOT_STARTED'),
            'policy_id' => $policy?->id, 'policy_number' => $policy?->policy_number,
            ...$this->commission($quote, $offer, $policy, $agent),
            'created_at' => $quote->created_at?->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------ steps

    private function openApplication(Quote $quote, TenantCustomer $customer, ?string $offerId, User $agent): void
    {
        $offer = $offerId
            ? $quote->offers()->whereKey($offerId)->first()
            : ($quote->offers()->where('status', 'ACCEPTED')->first() ?? $quote->offers()->where('status', 'OFFERED')->orderBy('comparison_rank')->first());
        if ($offer === null) {
            throw ValidationException::withMessages(['offer_id' => __('wave2.offer_unavailable')]);
        }
        if ($offer->status !== 'ACCEPTED') {
            $this->quotes->accept($quote, $offer, $agent);
        }
        $proposal = $this->proposals->create(Tenant::findOrFail($quote->tenant_id), $offer->refresh(), ['party_id' => $customer->party_id], $agent);
        $this->audit->record('agent.sale.application_opened', 'quote', $quote->id, ['proposal_id' => $proposal->id, 'offer_id' => $offer->id]);
        $this->remindClient($quote, $proposal);
    }

    /** The client finishes the application themselves (declarations, attestation, contract terms). */
    private function remindClient(Quote $quote, Proposal $proposal): void
    {
        // Repeated taps never flood the client: at most one reminder (push + SMS) every 10 minutes.
        $last = ($quote->comparison_context ?? [])['client_notified_at'] ?? null;
        if ($last && now()->subMinutes(10)->lt(\Carbon\Carbon::parse($last))) {
            return;
        }
        $this->notifier->toParty($proposal->party_id, $proposal->tenant_id, 'PAYMENT',
            ...NotificationCatalog::message('agent_payment_requested', ['reference' => $quote->quote_number]), severity: 'WARNING', path: "/proposals/{$proposal->id}", forceSms: true);
        $quote->update(['comparison_context' => array_merge($quote->comparison_context ?? [], ['client_notified_at' => now()->toIso8601String()])]);
        $this->audit->record('agent.sale.client_notified', 'quote', $quote->id, ['proposal_id' => $proposal->id, 'proposal_status' => $proposal->status]);
    }

    private function requestPayment(Quote $quote, Proposal $proposal, ?string $phone, ?string $provider, User $agent): void
    {
        if (! $phone || ! preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            throw ValidationException::withMessages(['payment_phone_e164' => __('validation.regex', ['attribute' => 'payment phone'])]);
        }
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw ValidationException::withMessages(['provider' => __('validation.required', ['attribute' => 'provider'])]);
        }
        DemoPersonas::assertProviderAllowed($provider, $agent);

        // One active request per application, whoever opened it (the client may already be paying in their app).
        $intent = PaymentIntentRecord::where('proposal_id', $proposal->id)->whereNotIn('status', [...self::PAYMENT_TERMINAL, ...self::PAYMENT_DONE])->latest()->first();
        if ($intent === null) {
            $attempt = PaymentIntentRecord::where('proposal_id', $proposal->id)->whereIn('status', self::PAYMENT_TERMINAL)->count() + 1;
            $intent = $this->payments->create(Tenant::findOrFail($proposal->tenant_id), $proposal, [
                'provider' => $provider, 'payer_phone_e164' => $phone, 'idempotency_key' => "agent-sale:{$proposal->id}:{$attempt}",
            ], $agent);
        }
        // Only an intent nothing was sent for yet is initiated; an operator prompt already out is awaited, never repeated.
        if (in_array($intent->status, ['CREATED', 'PENDING_CUSTOMER'], true) && ! $intent->provider_reference && ! $intent->attempts()->exists()) {
            try {
                $intent = $this->initiation->initiate($intent);
            } catch (ValidationException $e) {
                throw $e;
            } catch (Throwable $e) {
                report($e);
                throw ValidationException::withMessages(['provider' => __('wave4.provider_not_active')]);
            }
        }
        $quote->update(['comparison_context' => array_merge($quote->comparison_context ?? [], [
            'payment_status' => 'CUSTOMER_PROMPTED', 'payment_requested_at' => now()->toIso8601String(), 'payment_intent_id' => $intent->id,
        ])]);
        $this->audit->record('agent.sale.payment_prompted', 'quote', $quote->id, ['proposal_id' => $proposal->id, 'payment_intent_id' => $intent->id, 'provider' => $intent->provider]);
    }

    // ------------------------------------------------------------------ reads

    private function proposalFor(Quote $quote): ?Proposal
    {
        return Proposal::whereIn('quote_offer_id', $quote->offers()->pluck('id'))->where('status', '!=', 'WITHDRAWN')->latest()->first()
            ?? Proposal::whereIn('quote_offer_id', $quote->offers()->pluck('id'))->latest()->first();
    }

    private function latestPayment(Proposal $proposal): ?PaymentIntentRecord
    {
        return PaymentIntentRecord::where('proposal_id', $proposal->id)->whereIn('status', self::PAYMENT_DONE)->latest()->first()
            ?? PaymentIntentRecord::where('proposal_id', $proposal->id)->latest()->first();
    }

    private function succeededPayment(Proposal $proposal): ?PaymentIntentRecord
    {
        return PaymentIntentRecord::where('proposal_id', $proposal->id)->whereIn('status', self::PAYMENT_DONE)->first();
    }

    private function policyId(Proposal $proposal): ?string
    {
        return Policy::where('proposal_id', $proposal->id)->value('id');
    }

    /** Terms accepted by the client themselves (a user of the proposal's own party), never by the agent. */
    private function clientAcceptedTerms(Proposal $proposal): bool
    {
        $row = $this->declarations->accepted($proposal, 'TERMS_ACCEPTANCE');

        return $row !== null && $row->accepted_by !== null && User::whereKey($row->accepted_by)->where('party_id', $proposal->party_id)->exists();
    }

    private function customerPhone(TenantCustomer $customer): ?string
    {
        return $customer->party?->contacts?->firstWhere('type', 'PHONE')?->normalized_value;
    }

    /** Cameroon numbering plan: MTN 650-654, 67x, 680-684; Orange 655-659, 69x, 685-689. */
    public static function providerForPhone(?string $phone): ?string
    {
        if (! $phone || ! preg_match('/^\+2376(\d{2})/', $phone, $m)) {
            return null;
        }
        $n = (int) $m[1];

        return match (true) {
            $n >= 70 && $n <= 79, $n >= 50 && $n <= 54, $n >= 80 && $n <= 84 => 'mtn_momo',
            $n >= 90 && $n <= 99, $n >= 55 && $n <= 59, $n >= 85 && $n <= 89 => 'orange_money',
            default => null,
        };
    }

    private function carrierName(QuoteOffer $offer): ?string
    {
        $c = $offer->carrier;

        return $c ? ($c->brand_short_name ?: $c->trade_name ?: $c->short_name ?: $c->legal_name ?: $c->party?->display_name) : null;
    }

    private function failureReason(PaymentIntentRecord $payment): ?string
    {
        $code = $payment->attempts()->whereNotNull('failure_code')->latest('completed_at')->value('failure_code');

        return $code ? (string) $code : $payment->status;
    }

    /**
     * Commission from the agent's configured rule (CommissionRuleResolver, the same resolver the accrual path uses):
     * ACCRUED once a commission accrual exists for the issued policy, RULE_ESTIMATE before issuance, NOT_CONFIGURED
     * (amount null) when no approved rule covers this carrier/product for the agent.
     */
    private function commission(Quote $quote, ?QuoteOffer $offer, ?Policy $policy, User $agent): array
    {
        $partner = $this->partners->resolve($agent);
        if ($policy) {
            $accrual = DB::table('commission_accruals')->where('policy_id', $policy->id)->where('partner_id', $partner->id)->orderByDesc('created_at')->first();
            if ($accrual) {
                return ['commission_minor' => (int) $accrual->amount_minor, 'commission_basis' => 'ACCRUED', 'commission_status' => $accrual->status, 'commission_rate_bp' => null];
            }
        }
        $resolution = null;
        if ($offer?->carrier_id) {
            try {
                $resolution = $this->commissionRules->resolve($offer->carrier_id, null, $offer->product_id, null, now(), (int) $offer->premium_minor, $quote->tenant_id, $partner->id);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $resolution
            ? ['commission_minor' => $resolution->amountMinor, 'commission_basis' => 'RULE_ESTIMATE', 'commission_status' => null, 'commission_rate_bp' => $resolution->basisPoints]
            : ['commission_minor' => null, 'commission_basis' => 'NOT_CONFIGURED', 'commission_status' => null, 'commission_rate_bp' => null];
    }
}
