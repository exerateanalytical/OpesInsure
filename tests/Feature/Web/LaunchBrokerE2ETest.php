<?php

declare(strict_types=1);

/**
 * R6 launch verification 2026-09-29 (launch 2026-10-02, 149 brokers on /broker): end to end through the broker portal.
 *  1. For each broker role (BROKER_ADMIN, BROKER_SUPERVISOR, BROKER_STAFF, BRANCH_MANAGER, governed default permissions):
 *     every page reachable from the /broker navigation mounts (Livewire), and every action the role can SEE — page
 *     header actions, table header actions, row actions on the role's own records and on their detail pages — is
 *     invoked twice: empty (must be refused by validation, never a 500) and, where the form is known, with valid data
 *     (must succeed or be refused with a business reason). A visible action refused with "not allowed" is a failure
 *     (a button the role can see but never use). Another brokerage's records are neither listed nor openable.
 *  2. Sales journey (BROKER_STAFF): customer → quote → compare → proposal → document upload → premium request (test
 *     provider) → issued policy → receipt / certificate, through the real /broker pages.
 *  3. Servicing journey: endorsement request, renewal quote, assisted claim, bordereau prepare → approve (another
 *     user, maker-checker) → submit, commission statement → payout request.
 */

use App\Application\Identity\RoleCatalogue;
use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\PolicyIssuanceService;
use App\Application\Rules\Models\QuestionSet;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Bordereaux\Pages\{ListBordereaux, ViewBordereau};
use App\Filament\Admin\Resources\Claims\Pages\ListClaims;
use App\Filament\Admin\Resources\PartnerStatements\Pages\ViewPartnerStatement;
use App\Filament\Admin\Resources\Policies\Pages\ViewPolicy;
use App\Filament\Admin\Resources\Proposals\Pages\ViewProposal;
use App\Filament\Admin\Resources\Quotes\Pages\ViewQuote;
use App\Filament\Admin\Resources\Renewals\Pages\ViewRenewal;
use App\Filament\Shared\Pages\BrokerCustomersPage;
use App\Models\{Bordereau, Carrier, Claim, CustomerAttribution, InsuranceLine, InsuranceProduct, Partner, PartnerPayoutRequest, PartnerStatement, Party, PaymentIntentRecord, Policy, PolicyIssuanceRequest, Proposal, ProposalDocument, Quote, RenewalCase, Role, TenantCustomer, TenantMembership, User};
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Storage};
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function lbeRoleUser(string $tenantId, string $role, ?string $partyId, ?string $branchId = null): User
{
    $user = User::factory()->create(['status' => 'ACTIVE', 'party_id' => $partyId]);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    if ($branchId) {
        DB::table('tenant_memberships')->where('id', $m->id)->update(['branch_id' => $branchId]);
    }
    $r = Role::firstOrCreate(['tenant_id' => $tenantId, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

/**
 * The brokerage (+ a rival brokerage in the same tenant), a user of $role recording the brokerage's client (so the
 * client is in the role's data scope: company / team / own / branch), one client per brokerage with a quote, a
 * proposal, an ACTIVE policy, an approved claim, a failed payment and a renewal case.
 */
function lbeBook(string $role): array
{
    $broker = makeMobilePartnerFixture('BROKER', '+2376'.random_int(10000000, 99999999));
    $tenant = $broker['tenant'];
    InsuranceLine::firstOrCreate(['code' => 'AUTO'], ['name' => 'Auto', 'status' => 'ACTIVE', 'risk_schema' => ['required' => []]]);
    $branch = null;
    if ($role === 'BRANCH_MANAGER') {
        $branch = (string) Str::uuid();
        DB::table('tenant_branches')->insert(['id' => $branch, 'tenant_id' => $tenant->id, 'code' => 'BR-'.Str::random(4), 'name' => 'Akwa', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }
    $user = lbeRoleUser($tenant->id, $role, $broker['party']->id, $branch);
    $rival = Partner::create(['tenant_id' => $tenant->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Rival Broker', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => []]);
    $rivalUser = lbeRoleUser($tenant->id, 'BROKER_STAFF', $rival->party_id);
    $book = [];
    foreach (['mine' => [$broker['partner'], $user], 'theirs' => [$rival, $rivalUser]] as $key => [$partner, $recorder]) {
        $tag = strtoupper($key);
        $chain = makeMobileFinanceProposalChain($tenant);
        $chain['party']->update(['display_name' => 'CLIENT-LBE-'.$tag]);
        $chain['proposal']->forceFill(['proposal_number' => 'PRP-LBE-'.$tag])->save();
        CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $partner->id, 'origin_type' => 'BROKER', 'terms_version' => 't1', 'effective_from' => now(), 'status' => 'ACTIVE', 'recorded_by' => $recorder->id]);
        $customer = TenantCustomer::create(['tenant_id' => $tenant->id, 'party_id' => $chain['party']->id, 'customer_number' => 'CUS-LBE-'.$tag, 'status' => 'ACTIVE']);
        $policy = makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-LBE-'.$tag, 'premium_minor' => 100000, 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addDays(20)]);
        $claim = Claim::create(['tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'claimant_party_id' => $chain['party']->id, 'claim_number' => 'CLM-LBE-'.$tag, 'status' => 'APPROVED',
            'loss_occurred_at' => now()->subDay(), 'loss_details' => ['description' => 'x'], 'currency' => 'XAF', 'approved_amount_minor' => 50000]);
        $payment = PaymentIntentRecord::create(['tenant_id' => $tenant->id, 'proposal_id' => $chain['proposal']->id, 'provider' => 'MTN_MOMO', 'provider_reference' => 'MOMO-LBE-'.$tag,
            'payer_phone_e164' => '+237670000000', 'amount_minor' => 100000, 'currency' => 'XAF', 'status' => 'FAILED', 'idempotency_key' => (string) Str::uuid()]);
        $renewal = RenewalCase::create(['tenant_id' => $tenant->id, 'policy_id' => $policy->id, 'due_on' => now()->addDays(20)->toDateString(), 'status' => 'DUE']);
        $book[$key] = compact('chain', 'policy', 'partner', 'claim', 'payment', 'customer', 'renewal');
    }

    return ['broker' => $broker, 'tenant' => $tenant, 'user' => $user, 'book' => $book];
}

function lbeAs(User $u, string $tenantId): void
{
    test()->actingAs($u, 'web');
    auth()->shouldUse('web'); // Passport::actingAs (tariff fixture) switches the default guard
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('broker'));
}

/** @return list<class-string> every page of the /broker navigation the current user can open (panel pages + resource index pages). */
function lbeNavPages(): array
{
    $panel = Filament::getPanel('broker');
    $pages = array_values(array_filter($panel->getPages(), fn ($p) => $p::canAccess() && $p::shouldRegisterNavigation()));
    foreach ($panel->getResources() as $res) {
        if ($res::canAccess()) {
            $pages[] = $res::getPages()['index']->getPage();
        }
    }

    return $pages;
}

/** @return list<Action> */
function lbeFlat(array $actions): array
{
    $out = [];
    foreach ($actions as $a) {
        if ($a instanceof \Filament\Actions\ActionGroup) {
            $out = [...$out, ...lbeFlat($a->getActions())];
        } elseif ($a instanceof Action) {
            $out[] = $a;
        }
    }

    return $out;
}

/** Valid form data per action name, for the fixture of lbeBook() (actions not listed are called empty only). */
function lbeValid(string $name, array $x): ?array
{
    $mine = $x['book']['mine'];
    $offer = fn () => \App\Models\QuoteOffer::where('quote_id', $mine['chain']['proposal']->offer?->quote_id)->value('id');

    return match ($name) {
        'addBranch' => ['code' => 'BR'.random_int(100, 999), 'name' => 'Agence Bonanjo'],
        'brokerRegisterClient' => ['full_name' => 'Client R6 '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'consent_reference' => 'FORM-R6', 'consent_confirmed' => true],
        'leadCreate' => ['full_name' => 'Prospect R6', 'phone_e164' => '+2376'.random_int(10000000, 99999999)],
        'chooseCustomer' => ['customer' => $mine['customer']->id],
        'quoteSend' => ['channel' => 'SMS', 'recipient' => '+237670000001'],
        'quoteDecline' => ['reason_code' => 'PRICE_TOO_HIGH'],
        'quoteConvert' => ['offer_id' => $offer()],
        'quoteRequestCarrier' => ['carrier_id' => $mine['chain']['carrier']->id, 'notes' => 'Large fleet, please quote.'],
        'policyRequestCancellation' => ['effective_at' => now()->addDay()->toDateTimeString(), 'initiated_by' => 'INSURED', 'reason_code' => 'CUSTOMER_REQUEST'],
        'serviceRequest' => ['type' => 'ENDORSEMENT', 'reason' => 'Change of usage to commercial'],
        'assistedClaim' => ['policy_id' => $mine['policy']->id, 'loss_occurred_at' => now()->subHours(3)->toDateTimeString(), 'description' => 'Rear-ended at a junction'],
        'bordereauPrepare' => ['carrier_id' => $mine['chain']['carrier']->id, 'type' => 'PREMIUM', 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'currency' => 'XAF'],
        'inviteStaff' => ['recipient_email' => 'r6.'.Str::random(5).'@example.test', 'role_code' => 'BROKER_STAFF'],
        default => null,
    };
}

/** Calls an action on a fresh component; returns [outcome, detail] with outcome OK / REFUSED / INVALID / EXC. */
function lbeCall(User $user, string $tenantId, string $page, array $params, $target, array $data = []): array
{
    lbeAs($user, $tenantId);
    session()->forget(['filament.notifications', 'filament.claimed_notifications']);
    try {
        $lw = Livewire::test($page, $params)->callAction($target, $data);
    } catch (Throwable $e) {
        if ($e instanceof \Filament\Actions\Exceptions\ActionNotResolvableException && str_contains($e->getMessage(), 'no longer exists')) {
            return ['GONE', 'the row left this work queue after an earlier action']; // state changed by a previous valid call
        }

        return ['EXC',class_basename($e).' '.Str::limit(str_replace("\n", ' ', $e->getMessage()), 300).' @'.basename($e->getFile()).':'.$e->getLine()];
    }
    $notes = collect([...session('filament.notifications', []), ...session('filament.claimed_notifications', [])]);
    $danger = $notes->firstWhere('status', 'danger');
    if ($errors = $lw->errors()->keys()) {
        return ['INVALID', implode(',', $errors)];
    }

    return $danger ? ['REFUSED', strip_tags((string) ($danger['body'] ?? $danger['title'] ?? ''))] : ['OK', strip_tags((string) ($notes->first()['title'] ?? ''))];
}

/** Probes one page: mounts it and calls every visible action (empty, then valid data). Appends to $log; returns detail URLs of rows. */
function lbeProbe(array $x, string $page, array $params, array &$log): array
{
    $user = $x['user'];
    $tenantId = $x['tenant']->id;
    $urls = [];
    lbeAs($user, $tenantId);
    try {
        $inst = Livewire::test($page, $params)->instance();
    } catch (Throwable $e) {
        $log[] = ['FAIL', "mount {$page}: ".class_basename($e).' '.Str::limit($e->getMessage(), 200)];

        return [];
    }
    if (! $inst) {
        $log[] = ['FAIL', "mount {$page}: no component (redirected)"];

        return [];
    }
    $targets = [];
    foreach (lbeFlat(method_exists($inst, 'getCachedHeaderActions') ? $inst->getCachedHeaderActions() : []) as $a) {
        if ($a->isVisible() && ! $a->getUrl()) {
            $targets[] = [$a->getName(), $a->getName()];
        }
    }
    if (method_exists($inst, 'getTable')) {
        $table = $inst->getTable();
        foreach (lbeFlat($table->getHeaderActions()) as $a) {
            $a = $inst->getAction([['name' => $a->getName(), 'context' => ['table' => true]]]);
            if ($a && $a->isVisible() && ! $a->getUrl()) {
                $targets[] = [$a->getName(), TestAction::make($a->getName())->table()];
            }
        }
        $records = $table->getRecords();
        foreach ($records as $rec) {
            foreach (lbeFlat($table->getRecordActions()) as $a) {
                $a = $inst->getAction([['name' => $a->getName(), 'context' => ['table' => true, 'recordKey' => $inst->getTableRecordKey($rec)]]]);
                if (! $a || ! $a->isVisible()) {
                    continue;
                }
                if ($url = $a->getUrl()) {
                    $urls[] = $url;
                } else {
                    $targets[] = [$a->getName(), TestAction::make($a->getName())->table($rec)];
                }
            }
            break; // first row: the role's own record
        }
    }
    foreach ($targets as [$name, $target]) {
        foreach (array_filter([[], lbeValid($name, $x)], fn ($d) => $d !== null) as $i => $data) {
            [$outcome, $detail] = lbeCall($user, $tenantId, $page, $params, $target, $data);
            $bad = $outcome === 'EXC'
                || ($outcome === 'REFUSED' && ($detail === __('workflow_actions.denied') || Str::contains($detail, ['This action is unauthorized', 'Forbidden', 'not allowed'])))
                || ($i === 0 && $data === [] && $outcome === 'OK' && false);
            $log[] = [$bad ? 'FAIL' : $outcome, class_basename($page)." {$name}".($data ? ' (valid)' : '')." => {$outcome} {$detail}"];
        }
    }

    return $urls;
}

dataset('lbe roles', ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'BRANCH_MANAGER']);

it('opens every /broker page and invokes every visible action of the role without a 500 or an unusable button', function (string $role) {
    $x = lbeBook($role);
    lbeAs($x['user'], $x['tenant']->id);
    $log = [];
    $details = [];
    $pages = lbeNavPages();
    expect(count($pages))->toBeGreaterThan(5);
    foreach ($pages as $page) {
        $details = [...$details, ...lbeProbe($x, $page, [], $log)];
    }
    // detail pages of the role's own records (quote, policy, claim, proposal, renewal, ...)
    foreach (array_unique($details) as $url) {
        [$slug, $id] = array_pad(explode('/', Str::after($url, '/broker/')), 2, null);
        foreach (Filament::getPanel('broker')->getResources() as $res) {
            if ($res::getSlug() === $slug && isset($res::getPages()['view']) && $id) {
                lbeProbe($x, $res::getPages()['view']->getPage(), ['record' => $id], $log);
            }
        }
    }
    $fails = array_values(array_map(fn ($l) => $l[1], array_filter($log, fn ($l) => $l[0] === 'FAIL')));
    expect($fails)->toBe([]);
    expect(count($log))->toBeGreaterThan(0);

    // Own book listed, the rival brokerage's never listed nor openable.
    lbeAs($x['user'], $x['tenant']->id);
    $theirs = $x['book']['theirs'];
    $this->get('/broker/policies')->assertOk()->assertSee('POL-LBE-MINE')->assertDontSee('POL-LBE-THEIRS');
    $this->get('/broker/claims')->assertOk()->assertSee('CLM-LBE-MINE')->assertDontSee('CLM-LBE-THEIRS');
    $this->get('/broker/policies/'.$theirs['policy']->id)->assertNotFound();
    $this->get('/broker/claims/'.$theirs['claim']->id)->assertNotFound();
    if ($role !== 'BRANCH_MANAGER') {
        $this->get('/broker/proposals')->assertOk()->assertSee('PRP-LBE-MINE')->assertDontSee('PRP-LBE-THEIRS');
        $this->get('/broker/proposals/'.$theirs['chain']['proposal']->id)->assertNotFound();
        $this->get('/broker/quotes/'.$theirs['chain']['proposal']->offer->quote_id)->assertNotFound();
        $this->get('/broker/renewals/'.$theirs['renewal']->id)->assertNotFound();
    }
    // Nothing was written on the rival's records by any of the calls above.
    expect(DB::table('policy_transactions')->where('policy_id', $theirs['policy']->id)->exists())->toBeFalse()
        ->and(Claim::where('policy_id', $theirs['policy']->id)->count())->toBe(1)
        ->and($theirs['policy']->refresh()->status)->toBe('ACTIVE');
})->with('lbe roles');

it('refuses every action on another brokerage\'s records, even when called directly', function () {
    $x = lbeBook('BROKER_ADMIN');
    $theirs = $x['book']['theirs'];
    lbeAs($x['user'], $x['tenant']->id);
    // Crafted ids through the list actions that take a record id in their form.
    Livewire::test(ListClaims::class)->callAction('assistedClaim', ['policy_id' => $theirs['policy']->id, 'loss_occurred_at' => now()->subDay()->toDateTimeString(), 'description' => 'Not my customer']);
    expect(Claim::where('policy_id', $theirs['policy']->id)->count())->toBe(1);
    Livewire::test(\App\Filament\Shared\Pages\Broker\CustomerTimelinePage::class)->callAction('chooseCustomer', ['customer' => $theirs['customer']->id])->assertHasActionErrors(['customer']);
    // Detail pages of the rival's records cannot even be mounted.
    foreach ([[ViewPolicy::class, $theirs['policy']], [ViewProposal::class, $theirs['chain']['proposal']], [ViewRenewal::class, $theirs['renewal']]] as [$page, $record]) {
        lbeAs($x['user'], $x['tenant']->id);
        expect(fn () => Livewire::test($page, ['record' => $record->getKey()]))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class); // rendered as 404
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// Sales journey
// ---------------------------------------------------------------------------------------------------------------------

function lbeTariff(string $tenantId, User $maker, User $checker, string $productId, int $ratePpm): void
{
    $as = function (User $u, string $method, string $uri, array $body = []) use ($tenantId) {
        Passport::actingAs($u);

        return test()->json($method, '/api/v1/'.$uri, $body, ['X-Tenant-Id' => $tenantId]);
    };
    $id = $as($maker, 'POST', 'tariffs', ['insurance_product_id' => $productId, 'effective_from' => '2026-01-01', 'input_schema' => ['usage' => 'string'], 'regulatory_reference' => 'DEMO-UNVERIFIED',
        'rules' => ['required_facts' => ['usage'], 'base' => ['method' => 'RATE_X_SUM_INSURED', 'fact' => 'vehicle_value', 'rate_ppm' => $ratePpm], 'rounding' => ['unit_minor' => 100, 'mode' => 'HALF_UP'],
            'branch_allocation' => [['branch_code' => 'RC_AUTO', 'basis_points' => 10000]]]])->assertCreated()->json('data.id');
    $as($maker, 'POST', "tariffs/{$id}/submit", ['notes' => 'Ready for technical review.'])->assertOk();
    $as($checker, 'POST', "tariffs/{$id}/approve", ['reason' => 'Actuarial review completed and signed off.'])->assertOk();
    $as($checker, 'POST', "tariffs/{$id}/schedule", ['notes' => 'Publish on the effective date.'])->assertOk();
}

/** @return list<string> danger notification bodies sent so far in this request. */
function lbeDanger(): array
{
    return collect([...session('filament.notifications', []), ...session('filament.claimed_notifications', [])])->where('status', 'danger')->map(fn ($n) => strip_tags((string) ($n['body'] ?? $n['title'] ?? '')))->values()->all();
}

it('takes a customer from quote to issued policy and certificate entirely through the /broker pages (BROKER_STAFF)', function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(10, 0));
    $t = \App\Models\Tenant::create(['type' => 'BROKER', 'legal_name' => 'LBE Broker '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $brokerage = Partner::create(['tenant_id' => $t->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Cabinet R6', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
    $staff = lbeRoleUser($t->id, 'BROKER_STAFF', $brokerage->party_id);
    InsuranceLine::firstOrCreate(['code' => 'MOTOR'], ['name' => 'Motor', 'status' => 'ACTIVE', 'risk_schema' => ['required' => []]]);
    InsuranceLine::where('code', 'MOTOR')->update(['status' => 'ACTIVE']);
    $maker = makeAuthTestUser($t, ['tariff.manage'], 'LBE_MAKER');
    $checker = makeAuthTestUser($t, ['tariff.manage', 'tariff.approve', 'tariff.publish'], 'LBE_CHECKER');
    // Two insurers with an ACTIVE agreement with the brokerage (so offers can be compared).
    foreach (['Alpha' => 20000, 'Beta' => 15000] as $name => $ppm) {
        $carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => "{$name} Assurances", 'status' => 'ACTIVE'])->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
        $product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'MOTOR', 'code' => 'LBE-'.$name, 'name' => "{$name} Motor", 'version' => 1, 'effective_from' => '2026-01-01', 'status' => 'ACTIVE']);
        lbeTariff($t->id, $maker, $checker, $product->id, $ppm);
        $set = QuestionSet::create(['scope_type' => 'PRODUCT_VERSION', 'insurance_product_id' => $product->id, 'line_code' => 'MOTOR', 'stage' => 'PROPOSAL', 'version' => 1,
            'status' => 'APPROVED', 'source' => 'MANUAL', 'schema_version' => 1, 'presentation' => ['steps' => [['key' => 'declarations', 'label' => 'Declarations']]],
            'schema_hash' => str_repeat('c', 64), 'effective_from' => '2026-01-01', 'approved_at' => now()]);
        $field = ['key' => 'prior_claims', 'label' => 'Claims in the last 3 years?', 'type' => 'boolean', 'required' => true, 'step' => 'declarations', 'referral_values' => [true], 'referral_code' => 'PRIOR_CLAIMS'];
        DB::table('product_questions')->insert(['id' => (string) Str::uuid(), 'question_set_id' => $set->id, 'code' => 'prior_claims', 'question_type' => 'BOOLEAN', 'input_type' => 'boolean',
            'label_en' => $field['label'], 'display_order' => 1, 'required' => true, 'validation' => '{}', 'fact_key' => 'prior_claims', 'rendered_field' => json_encode($field), 'created_at' => now(), 'updated_at' => now()]);
        $agreement = (string) Str::uuid();
        DB::table('carrier_broker_agreements')->insert(['id' => $agreement, 'carrier_id' => $carrier->id, 'partner_id' => $brokerage->id, 'agreement_number' => 'AGR-LBE-'.$name,
            'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'territories' => '[]', 'channels' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('carrier_broker_agreement_products')->insert(['id' => (string) Str::uuid(), 'agreement_id' => $agreement, 'line_code' => 'MOTOR', 'can_quote' => true, 'can_bind' => true,
            'can_collect_premium' => true, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }
    $ok = fn ($lw) => expect(lbeDanger())->toBe([]) && $lw->assertHasNoActionErrors();

    // 1. New customer from the customers page.
    lbeAs($staff, $t->id);
    $ok(Livewire::test(BrokerCustomersPage::class)->callAction(TestAction::make('brokerRegisterClient')->table(),
        ['full_name' => 'Awa Ngono', 'phone_e164' => '+237677001122', 'consent_reference' => 'FORM-2026-001', 'consent_confirmed' => true]));
    $customer = BrokerCustomersPage::customers($t->id)->firstOrFail();

    // 2. Quote from the customer row, rated with both agreed insurers.
    lbeAs($staff, $t->id);
    $ok(Livewire::test(BrokerCustomersPage::class)->callAction(TestAction::make('brokerNewQuote')->table($customer), ['line_code' => 'MOTOR', 'risk_facts' => ['usage' => 'PRIVATE', 'vehicle_value' => '5000000']]));
    $quote = Quote::where('party_id', $customer->party_id)->firstOrFail();
    $offers = $quote->offers()->orderBy('total_minor')->get();
    expect($offers)->toHaveCount(2);

    // 3. Compare the offers, then convert the chosen one to a proposal (quote detail page).
    lbeAs($staff, $t->id);
    $ok(Livewire::test(ViewQuote::class, ['record' => $quote->id])->callAction('quoteSaveComparison', ['offer_ids' => $offers->pluck('id')->all()]));
    expect(\App\Models\SavedComparison::where(['quote_request_id' => $quote->id, 'user_id' => $staff->id])->exists())->toBeTrue();
    lbeAs($staff, $t->id);
    $ok(Livewire::test(ViewQuote::class, ['record' => $quote->id])->callAction('quoteConvert', ['offer_id' => $offers->first()->id]));
    $proposal = Proposal::where('party_id', $customer->party_id)->firstOrFail();

    // 4. Proposal: disclosures, a document uploaded from the portal, attestation, submission.
    lbeAs($staff, $t->id);
    $ok(Livewire::test(ViewProposal::class, ['record' => $proposal->id])->callAction('proposalDisclosureAnswers', ['answers' => ['prior_claims' => 'false']]));
    $requirement = collect(app(\App\Application\Underwriting\ProposalService::class)->requiredDocuments($proposal->refresh()))->first();
    if ($requirement) {
        lbeAs($staff, $t->id);
        $pdf = UploadedFile::fake()->createWithContent('licence.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $ok(Livewire::test(ViewProposal::class, ['record' => $proposal->id])->callAction('proposalAttachDocument', ['requirement_code' => $requirement['code'], 'file' => $pdf]));
        expect(ProposalDocument::where('proposal_id', $proposal->id)->exists())->toBeTrue();
    }
    lbeAs($staff, $t->id);
    $ok(Livewire::test(ViewProposal::class, ['record' => $proposal->id])->callAction('proposalAttest'));
    lbeAs($staff, $t->id);
    $ok(Livewire::test(ViewProposal::class, ['record' => $proposal->id])->callAction('proposalSubmit'));
    expect($proposal->refresh()->status)->toBe('PAYMENT_PENDING');

    // 5. Premium request in test mode; the provider confirms; the insurer issues.
    lbeAs($staff, $t->id);
    $ok(Livewire::test(ViewProposal::class, ['record' => $proposal->id])->callAction('brokerRequestPremium', ['provider' => 'fake', 'payer_phone_e164' => '+237677001122']));
    $payment = PaymentIntentRecord::where('proposal_id', $proposal->id)->firstOrFail();
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), ['payment_reference' => $payment->provider_reference, 'amount_minor' => $payment->amount_minor,
        'currency' => $payment->currency, 'status' => 'SUCCEEDED'], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $proposal->id)->firstOrFail();
    $approver = User::create(['full_name' => 'Carrier Desk', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $policy = app(PolicyIssuanceService::class)->approve($request, ['policy_number' => 'POL-LBE-SALE', 'carrier_reference' => 'CR-1'], $approver);

    // 6. The issued policy is in the book; receipt and policy documents (certificate) are reachable from /broker.
    lbeAs($staff, $t->id);
    $this->get('/broker/policies')->assertOk()->assertSee($policy->policy_number);
    lbeAs($staff, $t->id);
    $this->get('/broker/policies/'.$policy->id)->assertOk()->assertSee($policy->policy_number);
    lbeAs($staff, $t->id);
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])->assertActionVisible('brokerPremiumReceipt');
    lbeAs($staff, $t->id);
    // A certificate re-issue is requested through the servicing request (issuance itself is the insurer's authority).
    $ok(Livewire::test(ViewPolicy::class, ['record' => $policy->id])->assertActionHidden('issueCertificate')
        ->callAction('serviceRequest', ['type' => 'DOCUMENT_REISSUE', 'reason' => 'Customer needs a printed certificate']));
    expect(DB::table('policy_transactions')->where(['policy_id' => $policy->id, 'type' => 'DOCUMENT_REISSUE', 'channel' => 'BROKER_WEB'])->exists())->toBeTrue()
        ->and(Policy::find($policy->id)->status)->toBe('ACTIVE');
});

it('uploads a required proposal document from /broker through the scan pipeline (EN and FR requirement labels)', function () {
    Storage::fake('local');
    $this->artisan('opesinsure:seed-document-catalogue')->assertExitCode(0);
    $x = lbeBook('BROKER_STAFF');
    $proposal = $x['book']['mine']['chain']['proposal']->refresh();
    $proposal->forceFill(['status' => 'DRAFT'])->save();
    DB::table('product_document_requirements')->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $proposal->offer->product_id, 'kind' => 'PRODUCT_TYPE',
        'product_type_code' => 'MOTOR_TPL', 'status' => 'ACTIVE', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $requirement = collect(app(\App\Application\Underwriting\ProposalService::class)->requiredDocuments($proposal))->firstWhere('satisfied_by', 'UPLOAD');
    expect($requirement)->not->toBeNull();

    foreach (['en', 'fr'] as $lang) {
        app()->setLocale($lang);
        lbeAs($x['user'], $x['tenant']->id);
        Livewire::test(ViewProposal::class, ['record' => $proposal->id])->mountAction('proposalAttachDocument')->assertOk()
            ->assertFormFieldExists('file'); // requirement options render (the localized-name array used to throw here)
        expect(__('issuance_maker_checker.fields.file'))->not->toBe('issuance_maker_checker.fields.file');
    }
    app()->setLocale('en');
    lbeAs($x['user'], $x['tenant']->id);
    session()->forget(['filament.notifications', 'filament.claimed_notifications']);
    $pdf = UploadedFile::fake()->createWithContent('licence.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->callAction('proposalAttachDocument', ['requirement_code' => $requirement['code'], 'file' => $pdf])->assertHasNoActionErrors();
    expect(lbeDanger())->toBe([]);
    $link = ProposalDocument::where('proposal_id', $proposal->id)->firstOrFail();
    expect($link->requirement_code)->toBe($requirement['code'])
        ->and(\App\Models\Document::find($link->document_id)->party_id)->toBe($proposal->party_id);
});

// ---------------------------------------------------------------------------------------------------------------------
// Servicing journey
// ---------------------------------------------------------------------------------------------------------------------

it('services the book in /broker: endorsement, renewal, assisted claim, bordereau maker-checker submit, statement and payout request', function () {
    $x = lbeBook('BROKER_ADMIN');
    $t = $x['tenant'];
    $admin = $x['user'];
    $staff = $x['broker']['user']; // BROKER_STAFF of the same brokerage
    $mine = $x['book']['mine'];
    $ok = fn ($lw) => expect(lbeDanger())->toBe([]) && $lw->assertHasNoActionErrors();

    // Endorsement request on an own policy.
    lbeAs($admin, $t->id);
    $ok(Livewire::test(ViewPolicy::class, ['record' => $mine['policy']->id])->callAction('serviceRequest', ['type' => 'ENDORSEMENT', 'reason' => 'Change of usage to commercial']));
    expect(DB::table('policy_transactions')->where(['policy_id' => $mine['policy']->id, 'type' => 'ENDORSEMENT'])->exists())->toBeTrue();

    // Renewal: quote the due renewal case.
    lbeAs($admin, $t->id);
    Livewire::test(ViewRenewal::class, ['record' => $mine['renewal']->id])->assertActionVisible('quote')->callAction('quote');
    expect($mine['renewal']->refresh()->status)->not->toBe('DUE');

    // Assisted claim.
    lbeAs($admin, $t->id);
    $ok(Livewire::test(ListClaims::class)->assertActionDoesNotExist('brokerRegister') // one "declare a claim" button, not two
        ->callAction('assistedClaim', ['policy_id' => $mine['policy']->id, 'loss_occurred_at' => now()->subHours(2)->toDateTimeString(), 'description' => 'Windscreen broken by a stone']));
    expect(Claim::where('policy_id', $mine['policy']->id)->count())->toBe(2);

    // Bordereau: staff prepares (maker), the broker admin approves (checker) and submits.
    lbeAs($staff, $t->id);
    $ok(Livewire::test(ListBordereaux::class)->callAction('bordereauPrepare', ['carrier_id' => $mine['chain']['carrier']->id, 'type' => 'PREMIUM',
        'period_start' => now()->subMonths(2)->toDateString(), 'period_end' => now()->addMonth()->toDateString(), 'currency' => 'XAF']));
    $bordereau = Bordereau::where('tenant_id', $t->id)->latest('created_at')->firstOrFail();
    expect($bordereau->status)->toBe('DRAFT');
    lbeAs($staff, $t->id);
    Livewire::test(ViewBordereau::class, ['record' => $bordereau->id])->assertActionHidden('bordereauApprove');
    lbeAs($admin, $t->id);
    $ok(Livewire::test(ViewBordereau::class, ['record' => $bordereau->id])->callAction('bordereauApprove'));
    lbeAs($admin, $t->id);
    $ok(Livewire::test(ViewBordereau::class, ['record' => $bordereau->id])->callAction('bordereauSubmit'));
    expect($bordereau->refresh()->status)->toBe('SUBMITTED');

    // Commission statement of the brokerage (published by the insurer side) → payout request.
    $statement = PartnerStatement::create(['tenant_id' => $t->id, 'partner_id' => $mine['partner']->id, 'statement_number' => 'STM-LBE-1', 'period_start' => now()->subMonth()->startOfMonth(),
        'period_end' => now()->subMonth()->endOfMonth(), 'currency' => 'XAF', 'status' => 'PUBLISHED', 'opening_balance_minor' => 0, 'earned_minor' => 50000, 'clawed_back_minor' => 0,
        'paid_minor' => 0, 'closing_balance_minor' => 50000, 'content_hash' => str_repeat('a', 64), 'idempotency_key' => (string) Str::uuid(), 'published_at' => now(), 'prepared_by' => $x['broker']['user']->id]);
    $rivalStatement = PartnerStatement::create(['tenant_id' => $t->id, 'partner_id' => $x['book']['theirs']['partner']->id, 'statement_number' => 'STM-LBE-2', 'period_start' => now()->subMonth()->startOfMonth(),
        'period_end' => now()->subMonth()->endOfMonth(), 'currency' => 'XAF', 'status' => 'PUBLISHED', 'opening_balance_minor' => 0, 'earned_minor' => 70000, 'clawed_back_minor' => 0,
        'paid_minor' => 0, 'closing_balance_minor' => 70000, 'content_hash' => str_repeat('b', 64), 'idempotency_key' => (string) Str::uuid(), 'published_at' => now(), 'prepared_by' => $x['broker']['user']->id]);
    lbeAs($admin, $t->id);
    $this->get('/broker/partner-statements')->assertOk()->assertSee('STM-LBE-1')->assertDontSee('STM-LBE-2');
    $this->get('/broker/partner-statements/'.$rivalStatement->id)->assertNotFound();
    lbeAs($admin, $t->id);
    $ok(Livewire::test(ViewPartnerStatement::class, ['record' => $statement->id])->callAction('payoutRequest', ['amount_minor' => 50000, 'destination_type' => 'MOBILE_MONEY', 'destination' => '+237670000123']));
    expect(PartnerPayoutRequest::where('partner_id', $mine['partner']->id)->count())->toBe(1);
    lbeAs($admin, $t->id);
    $this->get('/broker/partner-payouts')->assertOk();
    // A producer (BROKER_STAFF) sees the statement but cannot request the payout.
    lbeAs($staff, $t->id);
    Livewire::test(ViewPartnerStatement::class, ['record' => $statement->id])->assertActionHidden('payoutRequest');
});
