<?php

declare(strict_types=1);

use App\Application\Customers\Matching\PartyMatcher;
use App\Application\Customers\Matching\PartyMergeService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Parties\Pages\ListParties;
use App\Filament\Admin\Resources\Parties\Pages\ViewParty;
use App\Filament\Admin\Resources\Renewals\Pages\ListRenewals;
use App\Filament\Shared\Actions\PartyActions;
use App\Filament\Shared\Actions\RenewalActions;
use App\Models\Parties\EntityMatchCandidate;
use App\Models\Parties\OwnershipInterest;
use App\Models\Party;
use App\Models\RenewalCase;
use App\Models\TenantCustomer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\WorkflowActionHarness;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/** Batch 8: party golden-record stewardship, agent client registration and renewal sweep on the staff desktop. */
const PRA_ALL = ['parties.manage', 'parties.relationships.manage', 'parties.match.review', 'parties.merge.request', 'parties.merge.approve', 'agent.clients.manage', 'renewals.manage'];

function praAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function praParty(string $name, string $type = 'PERSON', array $identity = []): Party
{
    $p = Party::create(['type' => $type, 'display_name' => $name, 'legal_identity' => $identity, 'status' => 'ACTIVE']);
    TenantCustomer::create(['tenant_id' => test()->tenant->id, 'party_id' => $p->id, 'customer_number' => 'C-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE']);

    return $p;
}

function praHarness(array $actions, ?object $record = null)
{
    WorkflowActionHarness::$actions = $actions;

    return Livewire::test(WorkflowActionHarness::class, $record ? ['model' => $record::class, 'recordId' => $record->getKey()] : []);
}

beforeEach(function () {
    $this->tenant = makeAuthTestTenant('PRA');
    $this->user = makeAuthTestUser($this->tenant, PRA_ALL, 'BROKER_ADMIN');
    praAs($this->user, $this->tenant->id);
});

it('shows the actions with the permissions and hides them without', function () {
    $org = praParty('Acme SARL', 'ORGANIZATION');
    Livewire::test(ViewParty::class, ['record' => $org->id])->assertActionVisible('matchScan')->assertActionVisible('addOwnership');
    Livewire::test(ListParties::class)->assertActionVisible('registerClient');
    praAs(makeAuthTestUser($this->tenant, PRA_ALL, 'RENEWAL_MANAGER'), $this->tenant->id);
    Livewire::test(ListRenewals::class)->assertActionVisible('renewalSweep');

    praAs(makeAuthTestUser($this->tenant, ['parties.manage'], 'BROKER_ADMIN'), $this->tenant->id);
    Livewire::test(ViewParty::class, ['record' => $org->id])->assertActionHidden('matchScan')->assertActionHidden('addOwnership');
    Livewire::test(ListParties::class)->assertActionHidden('registerClient');
    praAs(makeAuthTestUser($this->tenant, ['parties.read'], 'RENEWAL_MANAGER'), $this->tenant->id);
    Livewire::test(ListRenewals::class)->assertActionHidden('renewalSweep');
});

it('scans for duplicates and dismisses a candidate through PartyMatcher', function () {
    $a = praParty('Marie Ngono', 'PERSON', ['date_of_birth' => '1990-04-01']);
    $b = praParty('NGONO Marie', 'PERSON', ['date_of_birth' => '1990-04-01']);
    praHarness([fn () => PartyActions::matchScan()], $a)->callAction('matchScan')->assertNotified(__('party_actions.matchScan.done'));
    $c = EntityMatchCandidate::where('status', 'OPEN')->whereIn('party_a_id', [$a->id, $b->id])->whereIn('party_b_id', [$a->id, $b->id])->firstOrFail();

    praHarness([fn () => PartyActions::matchDismiss()], $a)->callAction('matchDismiss', ['candidate_id' => $c->id, 'note' => 'Different people'])
        ->assertNotified(__('party_actions.matchDismiss.done'));
    expect($c->fresh()->status)->toBe('DISMISSED');
});

it('records and ends an ownership interest through PartyRelationshipService', function () {
    $org = praParty('Acme SARL', 'ORGANIZATION');
    $owner = praParty('Jean Owono');
    praHarness([fn () => PartyActions::addOwnership()], $org)->callAction('addOwnership', ['owner_party_id' => $owner->id, 'percentage' => 40, 'interest_type' => 'SHAREHOLDING'])
        ->assertNotified(__('party_actions.addOwnership.done'));
    $oi = OwnershipInterest::where('owned_party_id', $org->id)->firstOrFail();
    expect((float) $oi->percentage)->toBe(40.0)->and($oi->status)->toBe('ACTIVE');

    praHarness([fn () => PartyActions::endOwnership()], $org)->callAction('endOwnership', ['interest_id' => $oi->id, 'reason' => 'Shares sold'])
        ->assertNotified(__('party_actions.endOwnership.done'));
    expect($oi->fresh()->status)->toBe('ENDED');
});

it('shows the service refusal when the ownership would exceed 100 percent', function () {
    $org = praParty('Acme SARL', 'ORGANIZATION');
    $owner = praParty('Jean Owono');
    praHarness([fn () => PartyActions::addOwnership()], $org)->callAction('addOwnership', ['owner_party_id' => $owner->id, 'percentage' => 60])->assertNotified();
    praHarness([fn () => PartyActions::addOwnership()], $org)->callAction('addOwnership', ['owner_party_id' => praParty('Paul Essomba')->id, 'percentage' => 50])
        ->assertNotified(__('workflow_actions.failed'));
    expect(OwnershipInterest::where('owned_party_id', $org->id)->count())->toBe(1);
});

it('approves then reverses a merge through PartyMergeService', function () {
    $survivor = praParty('Paul Mbarga', 'PERSON', ['date_of_birth' => '1985-02-02']);
    $dupe = praParty('MBARGA Paul', 'PERSON', ['date_of_birth' => '1985-02-02']);
    $cand = collect(app(PartyMatcher::class)->scan($survivor))->first(fn ($c) => in_array($dupe->id, [$c->party_a_id, $c->party_b_id], true));
    $m = app(PartyMergeService::class)->request($survivor, $dupe, makeAuthTestUser($this->tenant, PRA_ALL), [], 'same person', $cand);

    praHarness([fn () => PartyActions::mergeDecision()], $survivor)->callAction('mergeDecision', ['merge_id' => $m->id, 'decision' => 'APPROVED'])
        ->assertNotified(__('party_actions.mergeDecision.done'));
    expect($m->fresh()->status)->toBe('MERGED')->and($dupe->fresh()->merged_into_id)->toBe($survivor->id);

    praHarness([fn () => PartyActions::mergeUnmerge()], $survivor)->callAction('mergeUnmerge', ['merge_id' => $m->id, 'reason' => 'Wrong person after all'])
        ->assertNotified(__('party_actions.mergeUnmerge.done'));
    expect($m->fresh()->status)->toBe('UNMERGED')->and($dupe->fresh()->merged_into_id)->toBeNull();
});

it('refuses a merge approval by its own requester', function () {
    $survivor = praParty('Paul Mbarga', 'PERSON', ['date_of_birth' => '1985-02-02']);
    $dupe = praParty('MBARGA Paul', 'PERSON', ['date_of_birth' => '1985-02-02']);
    $m = app(PartyMergeService::class)->request($survivor, $dupe, $this->user, [], 'same person');

    praHarness([fn () => PartyActions::mergeDecision()], $survivor)->callAction('mergeDecision', ['merge_id' => $m->id, 'decision' => 'APPROVED'])
        ->assertNotified(__('workflow_actions.failed'));
    expect($m->fresh()->status)->toBe('PENDING');
});

it('registers a client through AgentLeadService, and refuses a user who is not an agent', function () {
    $client = ['full_name' => 'Awa Bello', 'phone_e164' => '+237677001122', 'city' => 'Garoua', 'consent_confirmed' => true];
    praHarness([fn () => PartyActions::registerClient()])->callAction('registerClient', $client)->assertNotified(__('workflow_actions.failed'));
    expect(Party::where('display_name', 'Awa Bello')->exists())->toBeFalse();

    $a = makeMobileAgentFixture('+237680001099');
    praAs($a['user'], $a['tenant']->id);
    praHarness([fn () => PartyActions::registerClient()])->callAction('registerClient', $client)->assertNotified(__('party_actions.registerClient.done'));
    $p = Party::where('display_name', 'Awa Bello')->firstOrFail();
    expect(TenantCustomer::where(['tenant_id' => $a['tenant']->id, 'party_id' => $p->id])->exists())->toBeTrue();
});

it('generates renewal cases through RenewalService', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, [
        'policy_number' => 'POL-PRA-'.Str::random(5), 'coverage_starts_at' => now()->subYear(), 'coverage_ends_at' => now()->addDays(20)->setTime(23, 0)]);
    praAs(makeAuthTestUser($f['tenant'], ['renewals.manage'], 'RENEWAL_MANAGER'), $f['tenant']->id);

    praHarness([fn () => RenewalActions::sweep()])->callAction('renewalSweep', ['days_ahead' => 60])
        ->assertNotified(__('party_actions.renewalSweep.done'));
    expect(RenewalCase::where('policy_id', $policy->id)->where('tenant_id', $f['tenant']->id)->exists())->toBeTrue();
});
