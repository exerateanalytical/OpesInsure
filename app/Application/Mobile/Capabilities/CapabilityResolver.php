<?php

declare(strict_types=1);

namespace App\Application\Mobile\Capabilities;

use App\Application\Claims\ClaimLifecycleService;
use App\Application\Claims\MobileClaimService;
use App\Application\Identity\CarrierScopeResolver;
use App\Application\Identity\OwnershipScope;
use App\Application\Identity\PartyResolver;
use App\Application\Identity\Rbac\DataScope;
use App\Application\Identity\Rbac\DataScopeResolver;
use App\Application\Identity\Rbac\PermissionEvaluator;
use App\Application\Partners\PartnerBook;
use App\Application\Payments\FinancialCaseService;
use App\Application\Payments\MobilePaymentService;
use App\Application\Policies\MobileWalletService;
use App\Application\Quotes\QuoteService;
use App\Application\Underwriting\ProposalMachine;
use App\Application\Underwriting\ProposalService;
use App\Domain\Claims\ClaimMachine;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Party;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * ARCH-004/005 (mobile audit A1): the ONE place that tells the app what the caller may see and do.
 *
 *  - modules(): GET /mobile/capabilities — per module {view, actions[]} from the caller's effective permissions
 *    (PermissionEvaluator, the same check as the `permission:` route middleware) and data scope (DataScopeResolver /
 *    CarrierScopeResolver: an insurer role not linked to a carrier in a shared tenant sees no carrier module).
 *    OWN = the caller has a customer identity (PartyResolver) — the gate of every customer endpoint.
 *  - for*(): `allowed_actions` on detail resources. Each action is the module action gate AND the same state rule the
 *    action endpoint enforces (ClaimMachine, QuoteService, ProposalMachine + ProposalService::availableEvents,
 *    MobilePaymentService, FinancialCaseService, ClaimLifecycleService, MobileWalletService). No rule is restated here.
 */
final class CapabilityResolver
{
    public const OWN = 'own';

    /**
     * module => view gates (any) + action => gates (any). Gate strings are the permissions on the matching routes.
     *
     * @var array<string, array{view: list<string>, actions: array<string, list<string>>}>
     */
    public const MODULES = [
        'policies' => ['view' => [self::OWN, 'policies.read', 'agent.clients.read', 'broker.portal.read', 'carrier.dashboard.read'],
            'actions' => ['file_claim' => [self::OWN], 'request_service' => [self::OWN], 'renew' => [self::OWN], 'request_cancellation' => ['policies.cancellation.request']]],
        'claims' => ['view' => [self::OWN, 'claims.view', 'carrier.claims.read', 'agent.clients.read', 'broker.portal.read'],
            'actions' => ['create' => [self::OWN], 'withdraw' => [self::OWN], 'add_evidence' => [self::OWN], 'appeal' => [self::OWN], 'decide_settlement' => [self::OWN],
                'file_for_client' => ['broker.claims.file']]],
        'quotes' => ['view' => [self::OWN, 'quotes.manage', 'agent.clients.read', 'broker.portal.read', 'carrier.quote_requests.view'],
            'actions' => ['create' => [self::OWN, 'quotes.manage'], 'resume' => [self::OWN, 'quotes.manage'], 'cancel' => [self::OWN, 'quotes.manage'], 'send' => ['quotes.send'],
                'respond' => ['carrier.quote_requests.respond']]],
        'proposals' => ['view' => [self::OWN, 'agent.clients.read', 'broker.portal.read', 'carrier.dashboard.read', 'underwriting.decide'],
            'actions' => ['answer_disclosures' => [self::OWN, 'quotes.manage'], 'attach_document' => [self::OWN, 'quotes.manage'], 'submit' => [self::OWN, 'quotes.manage'],
                'resubmit' => [self::OWN, 'quotes.manage'], 'withdraw' => [self::OWN, 'quotes.manage'], 'accept_counteroffer' => [self::OWN, 'quotes.manage'],
                'decline_counteroffer' => [self::OWN, 'quotes.manage'], 'decide' => ['underwriting.decide', 'carrier.referrals.decide']]],
        'payments' => ['view' => [self::OWN, 'carrier.finance.read'], 'actions' => ['retry' => [self::OWN], 'refund' => [self::OWN], 'receipt' => [self::OWN]]],
        'profile' => ['view' => [self::OWN], 'actions' => ['update_profile' => [self::OWN], 'manage_consents' => [self::OWN], 'request_privacy' => [self::OWN]]],
        'clients' => ['view' => ['agent.clients.read', 'broker.portal.read'], 'actions' => ['create' => ['agent.clients.manage', 'broker.portal.read'], 'update' => ['agent.clients.manage']]],
        'leads' => ['view' => ['crm.leads.read'], 'actions' => ['create' => ['crm.leads.manage'], 'assign' => ['crm.leads.assign']]],
        'commissions' => ['view' => ['agent.commissions.read', 'broker.finance.read', 'carrier.finance.read'], 'actions' => ['dispute' => ['commission.statements.dispute']]],
        'withdrawals' => ['view' => ['agent.withdrawals.read'], 'actions' => ['request' => ['agent.withdrawals.request']]],
        'renewals' => ['view' => ['agent.clients.read', 'broker.renewals.manage', 'renewals.manage'], 'actions' => ['manage' => ['broker.renewals.manage', 'renewals.manage']]],
        'referrals' => ['view' => ['carrier.referrals.read'], 'actions' => ['decide' => ['carrier.referrals.decide']]],
        'issuance' => ['view' => ['carrier.issuance.read', 'policies.issuance_queue.view'], 'actions' => ['manage' => ['policies.issuance_queue.manage']]],
        'settlements' => ['view' => ['carrier.finance.read', 'broker.finance.read'], 'actions' => ['reconcile' => ['settlement.reconcile']]],
        'bordereaux' => ['view' => ['carrier.finance.read', 'broker.finance.read'], 'actions' => ['decide' => ['carrier.bordereaux.decide'], 'submit' => ['broker.bordereaux.submit']]],
        'health' => ['view' => ['health.preauth.view', 'health.provider_claims.view'], 'actions' => ['review' => ['health.preauth.review'], 'approve' => ['health.preauth.approve'],
            'adjudicate' => ['health.provider_claims.adjudicate']]],
        'staff' => ['view' => ['broker.portal.read', 'staff.security.read'], 'actions' => ['view_security' => ['staff.security.read'], 'suspend_access' => ['staff.security.manage'],
            'force_reauth' => ['staff.security.manage']]],
        'security_centre' => ['view' => ['security.centre.read'], 'actions' => []],
    ];

    /** Carrier-side permissions: only meaningful when the caller's carrier scope resolves (linked, or an insurer tenant). */
    private const CARRIER_SCOPED = ['carrier.dashboard.read', 'carrier.claims.read', 'carrier.finance.read', 'carrier.referrals.read', 'carrier.referrals.decide',
        'carrier.issuance.read', 'carrier.quote_requests.view', 'carrier.quote_requests.respond', 'carrier.bordereaux.decide'];

    /** @var array<string, bool> per-request memo */
    private array $memo = [];

    public function __construct(
        private readonly PermissionEvaluator $permissions,
        private readonly PartyResolver $parties,
        private readonly OwnershipScope $ownership,
        private readonly PartnerBook $book,
        private readonly DataScopeResolver $scopes,
        private readonly CarrierScopeResolver $carriers,
    ) {}

    /** @return array{data_scope: ?string, modules: array<string, array{view: bool, actions: list<string>}>} */
    public function modules(User $user): array
    {
        $modules = [];
        foreach (self::MODULES as $module => $def) {
            $view = $this->anyGate($user, $def['view']);
            $actions = $view ? array_keys(array_filter($def['actions'], fn (array $gates) => $this->anyGate($user, $gates))) : [];
            $modules[$module] = ['view' => $view, 'actions' => array_values($actions)];
        }

        return ['data_scope' => $this->scopes->effectiveScope($user)?->value ?? ($this->hasOwnParty($user) ? DataScope::OWN->value : null), 'modules' => $modules];
    }

    /** @return list<string> */
    public function forPolicy(Policy $policy, User $user): array
    {
        $own = $this->ownsParty($user, $policy->party_id);

        return $this->filter($user, 'policies', [
            'file_claim' => $own && in_array($policy->status, ClaimLifecycleService::FNOL_POLICY_STATUSES, true),
            'request_service' => $own,
            'renew' => $own && MobileWalletService::renewalSource($policy) !== null,
        ]);
    }

    /** @return list<string> */
    public function forClaim(Claim $claim, User $user): array
    {
        $own = $this->ownsParty($user, $claim->claimant_party_id) || $this->ownsParty($user, $claim->policy?->party_id);

        return $this->filter($user, 'claims', [
            'withdraw' => $own && MobileClaimService::canWithdraw($claim),
            'add_evidence' => $own,
            'appeal' => $own && in_array($claim->status, ClaimMachine::APPEALABLE, true),
            'decide_settlement' => $own && \App\Application\Claims\Settlement\MobileClaimSettlementView::hasOpenOffer($claim),
        ]);
    }

    /** @return list<string> */
    public function forQuote(Quote $quote, User $user): array
    {
        $quotes = app(QuoteService::class);
        $acts = $this->actsFor($user, $quote->party_id);

        return $this->filter($user, 'quotes', [
            'resume' => $acts && $quotes->isResumable($quote),
            'cancel' => $acts && $quotes->isCancellable($quote),
        ]);
    }

    /** @return list<string> */
    public function forProposal(Proposal $proposal, User $user): array
    {
        $acts = $this->actsFor($user, $proposal->party_id);
        $events = app(ProposalService::class)->availableEvents($proposal, $user);
        $candidates = [
            'answer_disclosures' => $acts && in_array($proposal->status, ProposalMachine::ANSWERABLE, true),
            'attach_document' => $acts && ! in_array($proposal->status, ProposalMachine::DOCUMENTS_LOCKED, true),
            // Underwriter decisions: any decision event the machine allows now.
            'decide' => array_intersect($events, ['approve', 'decline', 'counteroffer', 'request_information']) !== [],
        ];
        foreach (ProposalMachine::PROPOSER_EVENTS as $event) {
            $candidates[$event] = $acts && in_array($event, $events, true);
        }

        return $this->filter($user, 'proposals', $candidates);
    }

    /** @return list<string> */
    public function forPayment(PaymentIntentRecord $payment, User $user): array
    {
        $own = $this->ownsParty($user, $payment->proposal?->party_id);

        return $this->filter($user, 'payments', [
            'retry' => $own && MobilePaymentService::isRetryable($payment),
            'refund' => $own && FinancialCaseService::refundableMinor($payment) > 0,
            'receipt' => $own && $payment->status === 'SUCCEEDED',
        ]);
    }

    /** Party detail: the caller's own customer profile, or a client in a partner's book. @return list<string> */
    public function forParty(Party $party, User $user): array
    {
        if ($this->ownsParty($user, $party->id)) {
            return $this->filter($user, 'profile', ['update_profile' => true, 'manage_consents' => true, 'request_privacy' => true]);
        }
        $inBook = $this->book->isBookScoped($user) && $this->book->contains($user, $party->id);

        return array_values(array_unique([
            ...$this->filter($user, 'clients', ['update' => $inBook]),
            ...array_map(fn ($a) => $a === 'create' ? 'create_quote' : $a, $this->filter($user, 'quotes', ['create' => $inBook])),
            ...$this->filter($user, 'claims', ['file_for_client' => $inBook]),
        ]));
    }

    // ------------------------------------------------------------------ internals

    /** @param array<string, bool> $candidates state-rule results @return list<string> */
    private function filter(User $user, string $module, array $candidates): array
    {
        $gates = self::MODULES[$module]['actions'];

        return array_values(array_keys(array_filter($candidates, fn (bool $ok, string $action) => $ok && $this->anyGate($user, $gates[$action] ?? []), ARRAY_FILTER_USE_BOTH)));
    }

    /** @param list<string> $gates */
    private function anyGate(User $user, array $gates): bool
    {
        foreach ($gates as $gate) {
            if ($this->gate($user, $gate)) {
                return true;
            }
        }

        return false;
    }

    private function gate(User $user, string $gate): bool
    {
        $key = $user->getKey().'|'.$gate;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        $ok = match (true) {
            $gate === self::OWN => $this->hasOwnParty($user),
            in_array($gate, self::CARRIER_SCOPED, true) => $this->permissions->allows($user, $gate) && $this->carrierScopeResolves($user),
            default => $this->permissions->allows($user, $gate),
        };

        return $this->memo[$key] = $ok;
    }

    private function carrierScopeResolves(User $user): bool
    {
        try {
            $this->carriers->carrierIdFor($user, app(TenantContext::class)->id());

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** Customer endpoints act for the caller's own customer identity. */
    private function hasOwnParty(User $user): bool
    {
        return $this->parties->forUser($user) !== null;
    }

    private function ownsParty(User $user, ?string $partyId): bool
    {
        return $partyId !== null && $this->parties->forUser($user)?->id === $partyId;
    }

    /** Mutations: an owner-scoped caller acts for their own party; a partner for parties in their book (PartnerBook). */
    private function actsFor(User $user, ?string $partyId): bool
    {
        return $this->ownership->isOwnerScoped($user) ? $this->ownsParty($user, $partyId) : $this->book->contains($user, $partyId);
    }
}
