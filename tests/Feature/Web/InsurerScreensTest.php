<?php

declare(strict_types=1);

use App\Application\Policies\Suspension\PolicySuspension;
use App\Application\Rules\Models\RuleSet;
use App\Application\WebExperiences\InsurerDashboards;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Pages\Insurer\{CancellationReview, EligibilityRules, RatingRules};
use App\Filament\Shared\Pages\ReportsPage;
use App\Models\{InsuranceProduct, Role, TariffVersion, Tenant, TenantMembership, User};
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Q7 /insurer carrier screens (CAR-002…007 dashboards, 012 intermediaries, 018 eligibility, 022 rating rules,
 * 029 cancellation / suspension review, 034 reports): every figure and row is the caller's own carrier's, each screen
 * opens only for the API read permission, EN and FR.
 */
uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function isTenant(): Tenant
{
    return Tenant::create(['type' => 'CARRIER', 'legal_name' => 'IS '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function isUser(Tenant $t, array $permissions, ?string $carrierId, string $locale = 'en'): User
{
    $u = User::factory()->create(['status' => 'ACTIVE', 'locale' => $locale]);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'CARRIER_STAFF', 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $m->roles()->attach(Role::create(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'code' => 'IS_'.Str::random(6), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

/** Two carriers in one tenant: mine (2 active policies of 100 000 + 1 claim), theirs (1 policy of 9 900 000 + 2 claims). */
function isBook(): array
{
    $tenant = isTenant();
    $mine = makeMobileFinanceProposalChain($tenant);
    $theirs = makeMobileFinanceProposalChain($tenant);
    $p1 = makeMobileTestPolicy($mine['proposal'], $tenant, $mine['carrier']->id, $mine['party']->id, ['policy_number' => 'POL-MINE-1', 'premium_minor' => 10000000, 'currency' => 'XAF']);
    makeMobileTestPolicy($mine['proposal'], $tenant, $mine['carrier']->id, $mine['party']->id, ['policy_number' => 'POL-MINE-2', 'premium_minor' => 10000000, 'currency' => 'XAF']);
    $t1 = makeMobileTestPolicy($theirs['proposal'], $tenant, $theirs['carrier']->id, $theirs['party']->id, ['policy_number' => 'POL-THEIRS-1', 'premium_minor' => 990000000, 'currency' => 'XAF']);
    makeMobileTestClaim($tenant, $p1, $mine['party']);
    makeMobileTestClaim($tenant, $t1, $theirs['party']);
    makeMobileTestClaim($tenant, $t1, $theirs['party']);

    return compact('tenant', 'mine', 'theirs', 'p1', 't1');
}

function isAs(User $u, Tenant $t): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($t->id);
    Filament::setCurrentPanel(Filament::getPanel('insurer'));
}

const IS_DASH = ['carrier.dashboard.read'];

it('computes every dashboard figure on the caller\'s own carrier only', function () {
    $b = isBook();
    isAs(isUser($b['tenant'], IS_DASH, $b['mine']['carrier']->id), $b['tenant']);
    $svc = app(InsurerDashboards::class);

    expect($svc->kpi('policies.active')['value'])->toBe(2)
        ->and($svc->kpi('claims.reported')['value'])->toBe(1)
        ->and($svc->kpi('premium.written', InsurerDashboards::period('ytd'))['by_currency'])->toBe(['XAF' => 20000000])
        ->and($svc->policiesByStatus())->toBe(['ACTIVE' => 2])
        ->and($svc->claimsByStatus())->toBe(['SUBMITTED' => 1])
        ->and($svc->activeByLine())->toBe(['AUTO' => 2]);
    $production = collect($svc->production('ytd'))->keyBy('key');
    expect($production['policies_issued']['value'])->toBe('2')->and($production['written_premium']['value'])->toBe('200 000 XAF');
    $products = $svc->productPerformance('ytd');
    expect($products)->toHaveCount(1)->and($products[0]['policies'])->toBe(2)->and($products[0]['premium_minor'])->toBe(20000000)->and($products[0]['claims'])->toBe(1);
    expect(collect($svc->operations())->firstWhere('key', 'active_policies')['value'])->toBe('2');
    expect(array_sum($svc->productionSeries()['count']))->toBe(2)->and(array_sum($svc->claimsSeries()['reported']))->toBe(1);

    // The other carrier's user sees its own figures, never mine.
    isAs(isUser($b['tenant'], IS_DASH, $b['theirs']['carrier']->id), $b['tenant']);
    expect(app(InsurerDashboards::class)->kpi('policies.active')['value'])->toBe(1)
        ->and(app(InsurerDashboards::class)->claimsByStatus())->toBe(['SUBMITTED' => 2]);
});

it('renders the /insurer home as the operations dashboard and every carrier dashboard with own figures only', function () {
    $b = isBook();
    $u = isUser($b['tenant'], [...IS_DASH, 'carrier.claims.read', 'policies.read'], $b['mine']['carrier']->id);

    $this->actingAs($u)->get('/insurer')->assertOk()
        ->assertSee(__('insurer_screens.dashboards.operations.stats'))->assertSee(__('insurer_screens.tiles.cancellations_pending'))
        ->assertSee(__('insurer_screens.charts.production_count'));
    $this->get('/insurer/production-dashboard')->assertOk()->assertSee(__('insurer_screens.tiles.written_premium'))->assertSee('200 000 XAF')->assertDontSee('9 900 000');
    $this->get('/insurer/portfolio-dashboard')->assertOk()->assertSee(__('insurer_screens.charts.portfolio_status'))->assertSee('200 000 XAF');
    $this->get('/insurer/claims-performance')->assertOk()->assertSee(__('insurer_screens.tiles.loss_ratio'));
    $this->get('/insurer/broker-production')->assertOk()->assertSee(__('insurer_screens.tables.brokers'));
    $this->get('/insurer/product-performance')->assertOk()->assertSee('Test Plan')->assertDontSee('9 900 000');
    $this->get('/insurer/intermediaries')->assertOk()->assertSee(__('insurer_screens.intermediaries.title'));
    $this->get('/insurer/rating-rules')->assertOk()->assertSee(__('insurer_screens.rating.title'));
});

it('opens each screen only for its API read permission', function () {
    $b = isBook();
    $claimsOnly = isUser($b['tenant'], ['carrier.claims.read'], $b['mine']['carrier']->id);
    $this->actingAs($claimsOnly)->get('/insurer/claims-performance')->assertOk();
    foreach (['production-dashboard', 'portfolio-dashboard', 'broker-production', 'product-performance', 'intermediaries', 'eligibility-rules', 'cancellation-review', 'rating-rules'] as $slug) {
        expect($this->get('/insurer/'.$slug)->status())->toBe(403, $slug);
    }
    // Dashboard reader without a claims permission: no claims figure anywhere (home tiles, claims dashboard).
    $this->flushSession();
    $dash = isUser($b['tenant'], IS_DASH, $b['mine']['carrier']->id);
    $this->actingAs($dash)->get('/insurer')->assertOk()->assertSee(__('web_experience.metrics.active_policies'))
        ->assertDontSee(__('web_experience.metrics.open_claims'))->assertDontSee(__('insurer_screens.charts.claims_trend'));
    expect($this->get('/insurer/claims-performance')->status())->toBe(403);
    $this->get('/insurer/production-dashboard')->assertOk();

    $this->flushSession();
    $nothing = isUser($b['tenant'], ['stickers.view'], $b['mine']['carrier']->id);
    expect($this->actingAs($nothing)->get('/insurer/claims-performance')->status())->toBe(403);

    $this->flushSession();
    $rules = isUser($b['tenant'], ['rules.view', 'policies.reinstatement.approve'], $b['mine']['carrier']->id);
    $this->actingAs($rules)->get('/insurer/eligibility-rules')->assertOk();
    $this->get('/insurer/cancellation-review')->assertOk();
});

it('lists only the own carrier\'s tariffs, eligibility rule sets and suspended policies', function () {
    $b = isBook();
    $mineProduct = InsuranceProduct::where('carrier_id', $b['mine']['carrier']->id)->firstOrFail();
    $theirProduct = InsuranceProduct::where('carrier_id', $b['theirs']['carrier']->id)->firstOrFail();
    TariffVersion::where('insurance_product_id', $mineProduct->id)->update(['rules' => ['base_rate_bp' => 350, 'young_driver_loading_bp' => 1500], 'regulatory_reference' => 'REF-MINE']);
    TariffVersion::where('insurance_product_id', $theirProduct->id)->update(['regulatory_reference' => 'REF-THEIRS']);
    $mineSet = RuleSet::create(['code' => 'ELIG.MINE', 'domain' => 'ELIGIBILITY', 'scope_type' => 'PRODUCT_VERSION', 'insurance_product_id' => $mineProduct->id, 'version' => 1, 'status' => 'DRAFT', 'effective_from' => now()->toDateString()]);
    $theirSet = RuleSet::create(['code' => 'ELIG.THEIRS', 'domain' => 'ELIGIBILITY', 'scope_type' => 'PRODUCT_VERSION', 'insurance_product_id' => $theirProduct->id, 'version' => 1, 'status' => 'DRAFT', 'effective_from' => now()->toDateString()]);
    foreach ([$b['p1'], $b['t1']] as $p) {
        $p->update(['status' => 'SUSPENDED']);
        PolicySuspension::create(['tenant_id' => $b['tenant']->id, 'policy_id' => $p->id, 'status' => 'REINSTATEMENT_REQUESTED', 'source' => 'MANUAL', 'reason_code' => 'NON_PAYMENT', 'suspended_at' => now()]);
    }
    $u = isUser($b['tenant'], ['carrier.dashboard.read', 'rules.view', 'policies.reinstatement.approve'], $b['mine']['carrier']->id);
    isAs($u, $b['tenant']);

    $tariffMine = TariffVersion::where('insurance_product_id', $mineProduct->id)->first();
    $tariffTheirs = TariffVersion::where('insurance_product_id', $theirProduct->id)->first();
    Livewire::test(RatingRules::class)->assertCanSeeTableRecords([$tariffMine])->assertCanNotSeeTableRecords([$tariffTheirs])->assertSee('base_rate_bp: 350');
    Livewire::test(EligibilityRules::class)->assertCanSeeTableRecords([$mineSet])->assertCanNotSeeTableRecords([$theirSet]);
    Livewire::test(CancellationReview::class)->assertCanSeeTableRecords([$b['p1']])->assertCanNotSeeTableRecords([$b['t1']])
        ->assertTableActionVisible('policyDecideReinstatement', $b['p1']);
});

it('scopes the reports screen to the own carrier and hides tenant-wide finance reports', function () {
    $b = isBook();
    $u = isUser($b['tenant'], ['reports.insurance.read', 'finance.reports.view'], $b['mine']['carrier']->id);
    isAs($u, $b['tenant']);
    $page = Livewire::test(ReportsPage::class)->set('report', 'INS:portfolio')->set('from', now()->subYear()->toDateString())->set('to', now()->addDay()->toDateString());
    $r = $page->instance()->result();
    expect($r['rows'][0]['policy_count'])->toBe(2)->and($r['rows'][0]['claim_count'])->toBe(1)->and($r['rows'][0]['gross_written_premium_minor'])->toBe(20000000)
        ->and(collect(array_keys($page->instance()->options()))->filter(fn ($k) => str_starts_with($k, 'FR:'))->all())->toBe([]);
});

it('shows the carrier screens in French for a French user', function () {
    $b = isBook();
    $u = isUser($b['tenant'], [...IS_DASH, 'carrier.claims.read'], $b['mine']['carrier']->id, 'fr');
    $this->actingAs($u)->get('/insurer')->assertOk()->assertSee('Opérations du jour')->assertSee('Tableaux de bord')->assertSee('Polices émises par mois');
    $this->get('/insurer/claims-performance')->assertOk()->assertSee('Performance sinistres')->assertSee('Ratio sinistres/primes');
    $this->get('/insurer/intermediaries')->assertOk()->assertSee('Intermédiaires');
    expect(array_keys(trans('insurer_screens', [], 'fr')))->toBe(array_keys(trans('insurer_screens', [], 'en')));
});
