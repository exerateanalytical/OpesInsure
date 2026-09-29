<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Claims\Pages\ListClaims;
use App\Filament\Admin\Resources\Memberships\Pages\ListMemberships;
use App\Filament\Admin\Resources\Policies\Pages\ViewPolicy;
use App\Filament\Admin\Resources\Renewals\Pages\ViewRenewal;
use App\Models\{Claim, CustomerAttribution, Partner, Party, Policy, RenewalCase, TenantInvitation, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Owner decision 2026-09-29 (portals writable, D4 lifted) — broker portal servicing & operations (P6): broker staff file a
 * servicing request (endorsement / certificate re-issue), run a renewal, declare a claim for a customer and invite a
 * colleague, all inside /broker, on their own book only; another broker's records are refused.
 */
uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/** A broker (BROKER_STAFF, governed defaults) plus a rival broker in the same tenant, one client + ACTIVE policy each. */
function bpsBook(): array
{
    $broker = makeMobilePartnerFixture('BROKER', '+2376'.random_int(10000000, 99999999));
    $tenant = $broker['tenant'];
    $rival = Partner::create(['tenant_id' => $tenant->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Rival Broker', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => []]);
    $book = [];
    foreach (['mine' => $broker['partner'], 'theirs' => $rival] as $key => $partner) {
        $chain = makeMobileFinanceProposalChain($tenant);
        CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => 'BROKER', 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $broker['user']->id]);
        $policy = makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-BPS-'.strtoupper($key), 'premium_minor' => 100000, 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear()]);
        $book[$key] = compact('chain', 'policy', 'partner');
    }

    return ['broker' => $broker, 'tenant' => $tenant, 'user' => $broker['user'], 'book' => $book];
}

function bpsAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('broker'));
}

test('broker staff files a servicing request (endorsement, certificate re-issue) on an own policy; another broker\'s policy is refused', function () {
    $x = bpsBook();
    bpsAs($x['user'], $x['tenant']->id);
    $mine = $x['book']['mine']['policy'];

    Livewire::test(ViewPolicy::class, ['record' => $mine->getRouteKey()])
        ->assertActionVisible('serviceRequest')->assertActionHidden('issueCertificate')
        ->callAction('serviceRequest', ['type' => 'DOCUMENT_REISSUE', 'reason' => 'Customer lost the certificate'])
        ->assertHasNoActionErrors();
    Livewire::test(ViewPolicy::class, ['record' => $mine->getRouteKey()])
        ->callAction('serviceRequest', ['type' => 'ENDORSEMENT', 'reason' => 'Change of usage to commercial']);

    $rows = DB::table('policy_transactions')->where('policy_id', $mine->id)->orderBy('created_at')->get();
    expect($rows->pluck('type')->sort()->values()->all())->toBe(['DOCUMENT_REISSUE', 'ENDORSEMENT'])
        ->and($rows->pluck('channel')->unique()->all())->toBe(['BROKER_WEB'])
        ->and($rows->pluck('requested_by')->unique()->all())->toBe([$x['user']->id]);

    // the rival broker's policy is not reachable in /broker (list and detail)
    $this->get('/broker/policies')->assertOk()->assertSee('POL-BPS-MINE')->assertDontSee('POL-BPS-THEIRS');
    $this->get('/broker/policies/'.$x['book']['theirs']['policy']->id)->assertNotFound();
    expect(DB::table('policy_transactions')->where('policy_id', $x['book']['theirs']['policy']->id)->exists())->toBeFalse();
});

test('broker staff runs a renewal of an own policy (links the successor); another broker\'s renewal case is not visible', function () {
    $x = bpsBook();
    $mine = $x['book']['mine']['policy'];
    $theirs = $x['book']['theirs']['policy'];
    $case = RenewalCase::create(['tenant_id' => $x['tenant']->id, 'policy_id' => $mine->id, 'due_on' => now()->addDays(20)->toDateString(), 'status' => 'QUOTED']);
    $foreign = RenewalCase::create(['tenant_id' => $x['tenant']->id, 'policy_id' => $theirs->id, 'due_on' => now()->addDays(20)->toDateString(), 'status' => 'QUOTED']);
    $successor = makeMobileTestPolicy($x['book']['mine']['chain']['proposal'], $x['tenant'], $x['book']['mine']['chain']['carrier']->id, $mine->party_id, ['policy_number' => 'POL-BPS-NEXT', 'status' => 'ACTIVE', 'previous_policy_id' => $mine->id]);
    bpsAs($x['user'], $x['tenant']->id);

    $this->get('/broker/renewals')->assertOk()->assertSee('POL-BPS-MINE')->assertDontSee('POL-BPS-THEIRS');
    $this->get('/broker/renewals/'.$foreign->id)->assertNotFound();

    bpsAs($x['user'], $x['tenant']->id);
    Livewire::test(ViewRenewal::class, ['record' => $case->getRouteKey()])
        ->assertActionVisible('complete')
        ->callAction('complete', ['successor_policy_id' => $successor->id]);

    expect($case->refresh()->successor_policy_id)->toBe($successor->id)
        ->and($foreign->refresh()->successor_policy_id)->toBeNull();
});

test('broker staff declares a claim for a customer of the book in /broker; another broker\'s policy is refused', function () {
    $x = bpsBook();
    bpsAs($x['user'], $x['tenant']->id);
    $mine = $x['book']['mine']['policy'];
    $theirs = $x['book']['theirs']['policy'];

    Livewire::test(ListClaims::class)->assertActionVisible('assistedClaim')
        ->callAction('assistedClaim', ['policy_id' => $mine->id, 'loss_occurred_at' => now()->subDay()->toDateTimeString(), 'description' => 'Rear-ended at a junction', 'loss_location' => 'Douala'])
        ->assertHasNoActionErrors();

    $claim = Claim::where('policy_id', $mine->id)->latest('created_at')->first();
    expect($claim)->not->toBeNull()->and($claim->claimant_party_id)->toBe($mine->party_id);

    // a crafted policy id outside the book: refused, nothing created
    Livewire::test(ListClaims::class)
        ->callAction('assistedClaim', ['policy_id' => $theirs->id, 'loss_occurred_at' => now()->subDay()->toDateTimeString(), 'description' => 'Not my customer']);
    expect(Claim::where('policy_id', $theirs->id)->exists())->toBeFalse();
});

test('broker admin invites a staff member from /broker; no role escalation; staff cannot invite; FR labels', function () {
    $x = bpsBook();
    $admin = makeMobileTenantStaffUser($x['tenant'], '+2376'.random_int(10000000, 99999999), 'BROKER_ADMIN');
    bpsAs($admin, $x['tenant']->id);

    Livewire::test(ListMemberships::class)->assertActionVisible('inviteStaff')
        ->callAction('inviteStaff', ['recipient_email' => 'new.colleague@example.test', 'role_code' => 'BROKER_STAFF'])
        ->assertHasNoActionErrors();
    $inv = TenantInvitation::where('recipient_email', 'new.colleague@example.test')->firstOrFail();
    expect($inv->tenant_id)->toBe($x['tenant']->id)->and($inv->role_code)->toBe('BROKER_STAFF')->and($inv->invited_by)->toBe($admin->id);

    // escalation: BROKER_ADMIN is not an offered role and is refused
    Livewire::test(ListMemberships::class)
        ->callAction('inviteStaff', ['recipient_email' => 'boss@example.test', 'role_code' => 'BROKER_ADMIN'])
        ->assertHasActionErrors(['role_code']);
    expect(TenantInvitation::where('recipient_email', 'boss@example.test')->exists())->toBeFalse();

    // a producer (BROKER_STAFF) cannot invite
    bpsAs($x['user'], $x['tenant']->id);
    Livewire::test(ListMemberships::class)->assertActionHidden('inviteStaff');

    app()->setLocale('fr');
    expect(__('broker_portal_service.inviteStaff.label'))->toBe('Inviter un collaborateur');
});
