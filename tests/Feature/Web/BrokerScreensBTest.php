<?php

declare(strict_types=1);

use App\Application\Identity\RoleCatalogue;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Pages\BrokerScreens\{BrokerScreen, FailedIssuanceQueuePage, FailedPaymentsPage, SuspensionReinstatementQueuePage};
use App\Models\{Claim, CustomerAttribution, Partner, Party, PaymentIntentRecord, Role, TenantMembership, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Launch 2026-10-02 (docs/LAUNCH_SCREEN_GAPS_2026-09-29.md §5, BRK-028..088): the /broker work screens render for every
 * broker role in EN and FR, show only the caller's book (another broker's records of the same tenant never appear), and
 * expose the shared workflow actions gated by the API permission + own record (docs/spec/PORTAL_WRITE_RULES.md).
 */
uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/** A broker (BROKER_STAFF) and a rival broker in the same tenant, one client each, with a policy, a claim, a payment. */
function bsbBook(): array
{
    $broker = makeMobilePartnerFixture('BROKER', '+2376'.random_int(10000000, 99999999));
    $tenant = $broker['tenant'];
    $rival = Partner::create(['tenant_id' => $tenant->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Rival Broker', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => []]);
    $book = [];
    foreach (['mine' => $broker['partner'], 'theirs' => $rival] as $key => $partner) {
        $tag = strtoupper($key);
        $chain = makeMobileFinanceProposalChain($tenant);
        $chain['proposal']->forceFill(['proposal_number' => 'PRP-BSB-'.$tag])->save();
        CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => 'BROKER', 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $broker['user']->id]);
        $policy = makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-BSB-'.$tag, 'premium_minor' => 100000, 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addDays(20)]);
        $claim = Claim::create(['tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'claimant_party_id' => $chain['party']->id, 'claim_number' => 'CLM-BSB-'.$tag, 'status' => 'APPROVED',
            'loss_occurred_at' => now()->subDay(), 'loss_details' => ['description' => 'x'], 'currency' => 'XAF', 'approved_amount_minor' => 50000]);
        $payment = PaymentIntentRecord::create(['tenant_id' => $tenant->id, 'proposal_id' => $chain['proposal']->id, 'provider' => 'MTN_MOMO', 'provider_reference' => 'MOMO-BSB-'.$tag,
            'payer_phone_e164' => '+237670000000', 'amount_minor' => 100000, 'currency' => 'XAF', 'status' => 'FAILED', 'idempotency_key' => (string) Str::uuid()]);
        $book[$key] = compact('chain', 'policy', 'partner', 'claim', 'payment');
    }

    return ['broker' => $broker, 'tenant' => $tenant, 'user' => $broker['user'], 'book' => $book];
}

function bsbAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('broker'));
}

function bsbRoleUser(string $tenantId, string $role): User
{
    $user = User::factory()->create(['status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $tenantId, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

dataset('bsb roles', ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER']);

it('renders every BRK-028..088 screen the role can open, in EN and FR, without raw translation keys', function (string $role) {
    $x = bsbBook();
    $user = bsbRoleUser($x['tenant']->id, $role);
    $this->actingAs($user);
    $opened = 0;
    foreach (BrokerScreen::PAGES as $page) {
        bsbAs($user, $x['tenant']->id);
        if (! $page::canAccess()) {
            continue;
        }
        foreach (['en', 'fr'] as $lang) {
            $res = $this->get('/broker/'.$page::getSlug().'?lang='.$lang);
            expect($res->getStatusCode())->toBe(200, $page.' '.$lang);
            expect(strip_tags($res->getContent()))->not->toMatch('/broker_screens_b\.[a-z_]+/');
        }
        $opened++;
    }
    // Broker roles read their whole book (broker.portal.read and its equivalents); a branch manager holds policies.read /
    // claims.view / stickers.* but no quote, proposal or broker.portal.read permission, so only those screens open (RBAC-exact).
    expect($opened)->toBeGreaterThanOrEqual($role === 'BRANCH_MANAGER' ? 15 : count(BrokerScreen::PAGES) - 2);
})->with('bsb roles');

test('the screens show only the caller\'s book, never another broker\'s records of the same tenant', function () {
    $x = bsbBook();
    DB::table('policies')->where('id', $x['book']['theirs']['policy']->id)->update(['status' => 'LAPSED']);
    DB::table('policies')->where('id', $x['book']['mine']['policy']->id)->update(['status' => 'ACTIVE']);
    bsbAs($x['user'], $x['tenant']->id);

    $this->get('/broker/policy-dashboard')->assertOk()->assertSee('POL-BSB-MINE')->assertDontSee('POL-BSB-THEIRS');
    $this->get('/broker/failed-payments')->assertOk()->assertSee('MOMO-BSB-MINE')->assertDontSee('MOMO-BSB-THEIRS');
    $this->get('/broker/payment-dashboard')->assertOk()->assertSee('MOMO-BSB-MINE')->assertDontSee('MOMO-BSB-THEIRS');
    $this->get('/broker/claim-settlement-preparation')->assertOk()->assertSee('CLM-BSB-MINE')->assertDontSee('CLM-BSB-THEIRS');
    $this->get('/broker/lapsed-policies')->assertOk()->assertDontSee('POL-BSB-THEIRS');
    $this->get('/broker/document-generation-queue')->assertOk()->assertSee('POL-BSB-MINE')->assertDontSee('POL-BSB-THEIRS');

    $mineQuote = $x['book']['mine']['chain']['proposal']->offer->quote_id;
    $theirsQuote = $x['book']['theirs']['chain']['proposal']->offer->quote_id;
    $this->get('/broker/quote-dashboard')->assertOk()->assertSee(strtoupper(substr($mineQuote, -8)))->assertDontSee(strtoupper(substr($theirsQuote, -8)));
    $this->get('/broker/quote-conversion-analytics')->assertOk()->assertSee(strtoupper(substr($mineQuote, -8)))->assertDontSee(strtoupper(substr($theirsQuote, -8)));

    foreach (['mine', 'theirs'] as $k) {
        $x['book'][$k]['chain']['proposal']->forceFill(['status' => 'PAYMENT_PENDING'])->save();
    }
    $this->get('/broker/pending-payments')->assertOk()->assertSee('PRP-BSB-MINE')->assertDontSee('PRP-BSB-THEIRS');
});

test('failed payments expose retry on an own payment only; suspension queue lists own suspended policies with the reinstatement request', function () {
    $x = bsbBook();
    bsbAs($x['user'], $x['tenant']->id);

    Livewire::test(FailedPaymentsPage::class)
        ->assertCanSeeTableRecords([$x['book']['mine']['payment']])
        ->assertCanNotSeeTableRecords([$x['book']['theirs']['payment']])
        ->assertTableActionVisible('paymentRetry', $x['book']['mine']['payment']);

    foreach (['mine', 'theirs'] as $k) {
        DB::table('policies')->where('id', $x['book'][$k]['policy']->id)->update(['status' => 'SUSPENDED']);
        DB::table('policy_suspensions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $x['tenant']->id, 'policy_id' => $x['book'][$k]['policy']->id, 'status' => 'SUSPENDED',
            'source' => 'MANUAL', 'reason_code' => 'NON_PAYMENT_'.strtoupper($k), 'suspended_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }
    bsbAs($x['user'], $x['tenant']->id);
    Livewire::test(SuspensionReinstatementQueuePage::class)
        ->assertCanSeeTableRecords([$x['book']['mine']['policy']->fresh()])
        ->assertCanNotSeeTableRecords([$x['book']['theirs']['policy']->fresh()])
        ->assertSee('NON_PAYMENT_MINE')->assertDontSee('NON_PAYMENT_THEIRS')
        ->assertTableActionVisible('policyRequestReinstatement', $x['book']['mine']['policy']->fresh());
});

test('failed issuance queue (BRK-058/059) lists own exceptions with details; the rival broker\'s exception is not visible', function () {
    $x = bsbBook();
    $rows = [];
    foreach (['mine', 'theirs'] as $k) {
        $rows[$k] = (string) Str::uuid();
        DB::table('issuance_exceptions')->insert(['id' => $rows[$k], 'tenant_id' => $x['tenant']->id, 'proposal_id' => $x['book'][$k]['chain']['proposal']->id,
            'payment_intent_id' => $x['book'][$k]['payment']->id, 'kind' => 'ISSUANCE_FAILED', 'status' => 'OPEN', 'reason_code' => 'CARRIER_TIMEOUT_'.strtoupper($k),
            'attempts' => 1, 'blockers' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);
    }
    bsbAs($x['user'], $x['tenant']->id);

    $this->get('/broker/failed-issuance-queue')->assertOk()->assertSee('CARRIER_TIMEOUT_MINE')->assertDontSee('CARRIER_TIMEOUT_THEIRS');
    $mine = \App\Application\Policies\IssuanceQueue\IssuanceException::find($rows['mine']);
    bsbAs($x['user'], $x['tenant']->id);
    Livewire::test(FailedIssuanceQueuePage::class)
        ->assertCanSeeTableRecords([$mine])
        ->mountTableAction('exceptionDetails', $mine)->assertSee('CARRIER_TIMEOUT_MINE');
});

test('a broker screen is closed outside the broker panel and to a user without the read permission', function () {
    $x = bsbBook();
    $this->actingAs($x['user']);
    app(TenantContext::class)->set($x['tenant']->id);
    Filament::setCurrentPanel(Filament::getPanel('insurer'));
    foreach (BrokerScreen::PAGES as $page) {
        expect($page::canAccess())->toBeFalse($page);
    }

    $nobody = bsbRoleUser($x['tenant']->id, 'CUSTOMER');
    bsbAs($nobody, $x['tenant']->id);
    foreach (BrokerScreen::PAGES as $page) {
        expect($page::canAccess())->toBeFalse($page);
    }
});
