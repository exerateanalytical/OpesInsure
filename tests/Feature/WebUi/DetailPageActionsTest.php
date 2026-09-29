<?php

declare(strict_types=1);

use App\Application\Policies\Suspension\PolicySuspensionService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\PartnerActions;
use App\Filament\Shared\Actions\PartyActions;
use App\Filament\Shared\Actions\PolicyServicingActions;
use App\Filament\Shared\Actions\ProposalActions;
use App\Filament\Shared\Actions\QuoteActions;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Policy;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\UnderwritingCase;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\WorkflowActionHarness;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/** Detail-page header actions (quote, proposal, party/customer, partner, policy servicing): hidden without the API permission, working with it. */
function dpUser(string $tenantId, array $permissions): User
{
    $u = User::create(['full_name' => 'DP '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => 'OPERATIONS_OFFICER', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'DP-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function dpAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function dpHarness(Closure $factory, object $record)
{
    WorkflowActionHarness::$actions = [$factory];

    return Livewire::test(WorkflowActionHarness::class, ['model' => $record::class, 'recordId' => $record->getKey()]);
}

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    app(TenantContext::class)->set($this->tenant);
    $this->f['quote']->update(['lifecycle_state' => 'CALCULATED']);
    DB::table('tenant_customers')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'party_id' => $this->f['party']->id, 'customer_number' => 'C-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $this->policy = Policy::create([
        'tenant_id' => $this->tenant, 'proposal_id' => $this->f['proposal']->id, 'carrier_id' => $this->f['carrier']->id, 'party_id' => $this->f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subMonth(),
    ]);
    $this->partner = Partner::create(['tenant_id' => $this->tenant, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'DP Broker', 'status' => 'ACTIVE'])->id,
        'type' => 'BROKER', 'status' => 'PENDING']);
});

/** [action factory, action name, permission, record key] — every permission-gated action added for the detail pages. */
dataset('gated detail actions', [
    'quote re-rate' => [fn () => QuoteActions::rate(), 'quoteRate', 'quotes.rate', 'quote'],
    'quote send' => [fn () => QuoteActions::send(), 'quoteSend', 'quotes.send', 'quote'],
    'quote carrier request' => [fn () => QuoteActions::requestCarrierQuote(), 'quoteRequestCarrier', 'quotes.carrier_requests.create', 'quote'],
    'quote premium override' => [fn () => QuoteActions::requestOverride(), 'quoteRequestOverride', 'quotes.premium_override.request', 'quote'],
    'proposal request info' => [fn () => ProposalActions::requestInformation(), 'proposalRequestInformation', 'underwriting.decide', 'proposal'],
    'party kyc open' => [fn () => PartyActions::kycOpen(), 'partyKycOpen', 'kyc.manage', 'party'],
    'party merge' => [fn () => PartyActions::requestMerge(), 'partyRequestMerge', 'parties.merge.request', 'party'],
    'party consent' => [fn () => PartyActions::recordConsent(), 'partyRecordConsent', 'privacy.consent.manage', 'party'],
    'party role' => [fn () => PartyActions::assignRole(), 'partyAssignRole', 'parties.roles.manage', 'party'],
    'party relationship' => [fn () => PartyActions::linkRelationship(), 'partyLinkRelationship', 'parties.relationships.manage', 'party'],
    'partner onboarding' => [fn () => PartnerActions::openSetup(), 'partnerOpenSetup', 'partner_setup.manage', 'partner'],
    'partner status' => [fn () => PartnerActions::changeStatus(), 'partnerChangeStatus', 'partners.manage', 'partner'],
    'partner agreements' => [fn () => PartnerActions::agreements(), 'partnerAgreements', 'distribution.agreements.view', 'partner'],
    'policy transfer' => [fn () => PolicyServicingActions::portfolioTransfer(), 'policyPortfolioTransfer', 'policies.portfolio_transfer.request', 'policy'],
    'policy portability' => [fn () => PolicyServicingActions::portabilityExport(), 'policyPortabilityExport', 'policies.portability.export', 'policy'],
]);

function dpRecord(string $key)
{
    return match ($key) {
        'quote' => test()->f['quote'], 'proposal' => test()->f['proposal'], 'party' => test()->f['party'], 'partner' => test()->partner, 'policy' => test()->policy,
    };
}

it('hides the action without the API permission and shows it with it', function (Closure $factory, string $name, string $permission, string $key) {
    dpAs(dpUser($this->tenant, ['claims.view']), $this->tenant);
    dpHarness($factory, dpRecord($key))->assertActionHidden($name);

    dpAs(dpUser($this->tenant, [$permission]), $this->tenant);
    dpHarness($factory, dpRecord($key))->assertActionVisible($name);
})->with('gated detail actions');

it('sends a quote to the client through QuoteService::send', function () {
    $quote = $this->f['quote'];
    dpAs(dpUser($this->tenant, ['quotes.send']), $this->tenant);
    dpHarness(fn () => QuoteActions::send(), $quote)->callAction('quoteSend', ['channel' => 'LINK'])->assertNotified(__('workflow_actions.quoteSend.done'));
    expect(DB::table('quote_shares')->where('quote_id', $quote->id)->count())->toBe(1);
});

it('requests and a different checker decides a premium override', function () {
    $quote = $this->f['quote'];
    $offer = $quote->offers()->first();
    dpAs(dpUser($this->tenant, ['quotes.premium_override.request']), $this->tenant);
    dpHarness(fn () => QuoteActions::requestOverride(), $quote)->callAction('quoteRequestOverride', [
        'offer_id' => $offer->id, 'premium_minor' => 90000, 'reason_code' => 'COMMERCIAL_DISCOUNT', 'justification' => 'Loyal customer, second vehicle.',
    ])->assertNotified(__('workflow_actions.quoteRequestOverride.done'));

    expect($offer->refresh()->premium_override_id)->not->toBeNull();

    dpAs(dpUser($this->tenant, ['quotes.read']), $this->tenant);
    dpHarness(fn () => QuoteActions::decideOverride(), $quote)->assertActionHidden('quoteDecideOverride');
    dpAs(dpUser($this->tenant, ['quotes.premium_override.approve']), $this->tenant);
    dpHarness(fn () => QuoteActions::decideOverride(), $quote)->assertActionVisible('quoteDecideOverride');
});

it('records an underwriting decision and hides it without underwriting.decide', function () {
    $proposal = $this->f['proposal'];
    $proposal->update(['status' => 'UNDER_REVIEW']);
    UnderwritingCase::create(['tenant_id' => $this->tenant, 'proposal_id' => $proposal->id, 'carrier_id' => $this->f['carrier']->id, 'status' => 'IN_REVIEW', 'priority' => 'NORMAL']);

    dpAs(dpUser($this->tenant, ['proposals.read']), $this->tenant);
    dpHarness(fn () => ProposalActions::decide(), $proposal)->assertActionHidden('proposalDecide');
    dpHarness(fn () => ProposalActions::counterOffer(), $proposal)->assertActionHidden('proposalCounterOffer');

    dpAs(dpUser($this->tenant, ['underwriting.decide']), $this->tenant);
    dpHarness(fn () => ProposalActions::counterOffer(), $proposal)->assertActionVisible('proposalCounterOffer');
    dpHarness(fn () => ProposalActions::decide(), $proposal)->callAction('proposalDecide', [
        'decision' => 'APPROVED', 'reason_code' => 'STANDARD_RISK', 'notes' => 'Clean driving record and standard vehicle.',
    ]);
    expect(DB::table('underwriting_decisions')->count())->toBe(1);
});

it('opens a KYC file, records consent and assigns a role on the customer', function () {
    $party = $this->f['party'];
    dpAs(dpUser($this->tenant, ['kyc.manage', 'privacy.consent.manage', 'parties.roles.manage']), $this->tenant);

    dpHarness(fn () => PartyActions::kycOpen(), $party)->callAction('partyKycOpen')->assertNotified(__('workflow_actions.partyKycOpen.done'));
    expect(DB::table('kyc_submissions')->where(['tenant_id' => $this->tenant, 'party_id' => $party->id, 'status' => 'DRAFT'])->count())->toBe(1);
    dpHarness(fn () => PartyActions::kycSubmit(), $party)->assertActionVisible('partyKycSubmit');

    dpHarness(fn () => PartyActions::recordConsent(), $party)->callAction('partyRecordConsent', [
        'purpose' => 'MARKETING', 'notice_version' => 'privacy-2026-01', 'channel' => 'ASSISTED', 'evidence_reference' => 'branch-form-17', 'affirmed' => true,
    ])->assertNotified(__('workflow_actions.partyRecordConsent.done'));
    expect(DB::table('consents')->where(['party_id' => $party->id, 'purpose' => 'MARKETING', 'status' => 'GRANTED'])->count())->toBe(1);

    dpHarness(fn () => PartyActions::assignRole(), $party)->callAction('partyAssignRole', ['role_code' => 'PAYER'])->assertNotified(__('workflow_actions.partyAssignRole.done'));
    expect(DB::table('party_roles')->where(['party_id' => $party->id, 'role_code' => 'PAYER'])->count())->toBe(1);
    dpHarness(fn () => PartyActions::endRole(), $party)->assertActionVisible('partyEndRole');
});

it('activates a partner through PartnerStatusService and hides it without partners.manage', function () {
    dpAs(dpUser($this->tenant, ['partners.read']), $this->tenant);
    dpHarness(fn () => PartnerActions::changeStatus(), $this->partner)->assertActionHidden('partnerChangeStatus');

    dpAs(dpUser($this->tenant, ['partners.manage']), $this->tenant);
    dpHarness(fn () => PartnerActions::changeStatus(), $this->partner)->callAction('partnerChangeStatus', ['status' => 'SUSPENDED', 'notes' => 'Licence renewal overdue']);
    expect($this->partner->refresh()->status)->toBe('SUSPENDED');
});

it('opens broker onboarding and attests a checklist item', function () {
    dpAs(dpUser($this->tenant, ['partner_setup.manage']), $this->tenant);
    dpHarness(fn () => PartnerActions::openSetup(), $this->partner)->callAction('partnerOpenSetup')->assertNotified(__('workflow_actions.partnerOpenSetup.done'));
    dpHarness(fn () => PartnerActions::attestItem(), $this->partner)->callAction('partnerAttestItem', ['item' => 'QUOTE_CONTROLS', 'status' => 'COMPLETE', 'evidence_reference' => 'SOP-12'])
        ->assertNotified(__('workflow_actions.partnerAttestItem.done'));
});

it('requests and decides a reinstatement of a suspended policy (maker-checker)', function () {
    app(PolicySuspensionService::class)->suspend($this->policy, 'NON_PAYMENT', dpUser($this->tenant, []));
    $policy = $this->policy->refresh();

    dpAs(dpUser($this->tenant, ['policies.read']), $this->tenant);
    dpHarness(fn () => PolicyServicingActions::requestReinstatement(), $policy)->assertActionHidden('policyRequestReinstatement');
    dpHarness(fn () => PolicyServicingActions::recoveryRequest(), $policy)->assertActionHidden('policyRecoveryRequest');

    dpAs(dpUser($this->tenant, ['policies.reinstatement.request']), $this->tenant);
    dpHarness(fn () => PolicyServicingActions::requestReinstatement(), $policy)->callAction('policyRequestReinstatement', ['reason_code' => 'PREMIUM_PAID'])
        ->assertNotified(__('workflow_actions.policyRequestReinstatement.done'));

    dpAs(dpUser($this->tenant, ['policies.reinstatement.approve']), $this->tenant);
    dpHarness(fn () => PolicyServicingActions::decideReinstatement(), $policy)->callAction('policyDecideReinstatement', ['outcome' => 'APPROVE', 'reason_code' => 'PREMIUM_PAID']);
    expect($policy->refresh()->status)->toBe('ACTIVE');
});

it('opens a premium recovery case on a suspended policy', function () {
    app(PolicySuspensionService::class)->suspend($this->policy, 'NON_PAYMENT', dpUser($this->tenant, []));
    dpAs(dpUser($this->tenant, ['policy.recovery.request']), $this->tenant);
    dpHarness(fn () => PolicyServicingActions::recoveryRequest(), $this->policy->refresh())->callAction('policyRecoveryRequest', ['reason_code' => 'LATE_PAYMENT'])
        ->assertNotified(__('workflow_actions.policyRecoveryRequest.done'));
    expect(DB::table('policy_recovery_cases')->where('policy_id', $this->policy->id)->count())->toBe(1);
});

it('calls the portability export service and surfaces its refusal (no chronology yet)', function () {
    dpAs(dpUser($this->tenant, ['policies.portability.export']), $this->tenant);
    dpHarness(fn () => PolicyServicingActions::portabilityExport(), $this->policy)->callAction('policyPortabilityExport', ['purpose' => 'CUSTOMER_REQUEST', 'consent_reference' => 'CONSENT-001'])
        ->assertNotified(__('workflow_actions.failed'));
});

it('R1 2026-09-29: gates the endorsement servicing action like POST policies/{p}/transactions (policies.service.approve)', function () {
    dpAs(dpUser($this->tenant, ['claims.view']), $this->tenant);
    dpHarness(fn () => \App\Filament\Shared\Actions\PolicyActions::endorse(), $this->policy)->assertActionHidden('policyEndorse');
});
