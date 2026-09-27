<?php

declare(strict_types=1);

/**
 * Home dashboard widgets (admin / insurer / broker) and the shared Reports screen: they render from the existing
 * tenant-scoped producers (KPI registry, MyWorkProjection, audit_log, FinanceReportRegistry) and never show another
 * tenant's records. REQ-UI-002, REQ-RPT-004, REQ-RPT-005.
 */

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Pages\ReportsPage;
use App\Filament\Shared\Widgets\{ExpiringPoliciesWidget, MyWorkWidget, OpenClaimsWidget, PremiumCollectedChartWidget, RecentActivityWidget};
use App\Models\{Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function dashTenant(string $type = 'CARRIER'): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'Dash '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function dashUser(Tenant $tenant, string $role, array $permissions = ['*']): User
{
    $user = User::factory()->create(['status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => $permissions, 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

beforeEach(function () {
    $this->mine = dashTenant();
    $this->other = dashTenant();
    $a = makeMobileFinanceProposalChain($this->mine);
    $b = makeMobileFinanceProposalChain($this->other);
    makeMobileTestPolicy($a['proposal'], $this->mine, $a['carrier']->id, $a['party']->id, ['policy_number' => 'POL-DASH-MINE', 'coverage_ends_at' => now()->addDays(10), 'premium_minor' => 50000, 'currency' => 'XAF']);
    makeMobileTestPolicy($b['proposal'], $this->other, $b['carrier']->id, $b['party']->id, ['policy_number' => 'POL-DASH-OTHER', 'coverage_ends_at' => now()->addDays(10), 'premium_minor' => 50000, 'currency' => 'XAF']);
    $this->user = dashUser($this->mine, 'CARRIER_ADMIN');
});

it('insurer home dashboard renders the widgets with the entry tenant book only', function () {
    $this->actingAs($this->user)->get('/insurer')->assertOk()
        ->assertSee(__('dashboards.widgets.expiring_policies'))->assertSee(__('dashboards.widgets.my_work'))->assertSee(__('dashboards.widgets.open_claims'))
        ->assertSee(__('dashboards.widgets.premium_collected'))->assertSee('POL-DASH-MINE')->assertDontSee('POL-DASH-OTHER');
    $this->get('/insurer/reports')->assertOk()->assertSee(__('dashboards.reports.title'));
});

it('broker home dashboard and reports render', function () {
    $broker = dashTenant('BROKER');
    $u = dashUser($broker, 'BROKER_STAFF');
    $this->actingAs($u)->get('/broker')->assertOk()->assertSee(__('dashboards.widgets.my_work'))->assertDontSee('POL-DASH-MINE');
    $this->get('/broker/reports')->assertOk();
});

it('widgets are tenant-scoped and hidden without their permission or tenant', function () {
    $this->actingAs($this->user);
    app(TenantContext::class)->set($this->mine->id);
    Livewire::test(ExpiringPoliciesWidget::class)->assertSee('POL-DASH-MINE')->assertDontSee('POL-DASH-OTHER');
    Livewire::test(OpenClaimsWidget::class)->assertSee(__('dashboards.states.empty'));
    Livewire::test(MyWorkWidget::class)->assertSee(__('dashboards.widgets.my_work'));
    Livewire::test(RecentActivityWidget::class)->assertOk();
    expect(app(PremiumCollectedChartWidget::class)->series()['labels'])->toHaveCount(6);

    $this->actingAs(dashUser($this->mine, 'LIMITED', ['claims.view']));
    expect(ExpiringPoliciesWidget::canView())->toBeFalse()->and(OpenClaimsWidget::canView())->toBeTrue();
    app(TenantContext::class)->clear();
    expect(OpenClaimsWidget::canView())->toBeFalse();
});

it('reports screen runs the catalogue producers with filters, exports CSV, and stays in the tenant', function () {
    $this->actingAs($this->user);
    app(TenantContext::class)->set($this->mine->id);
    $page = Livewire::test(ReportsPage::class);
    expect(array_keys($page->instance()->options()))->toContain('INS:portfolio', 'FR:FR-06', 'KPI:policies.expiring_30d');

    $page->set('report', 'KPI:policies.expiring_30d')->assertSee('POL-DASH-MINE')->assertDontSee('POL-DASH-OTHER')
        ->call('exportCsv')->assertFileDownloaded();
    $page->set('report', 'INS:portfolio')->set('from', now()->subYear()->toDateString())->set('to', now()->addDay()->toDateString())->assertSee('policy count');
    $page->set('from', 'not-a-date')->assertSee('data-state="INVALID"', false);

    // No reporting permission → no access; tampering with the report key cannot reach another family.
    $this->actingAs(dashUser($this->mine, 'NOREPORTS', ['claims.view']));
    expect(ReportsPage::canAccess())->toBeFalse();
});

it('admin home dashboard shows the governed KPI tiles, work queue and reports for the admin tenant', function () {
    $admin = dashUser($this->mine, 'SYSTEM_ADMIN');
    $this->actingAs($admin)->get('/admin')->assertOk()
        ->assertSee(__('web_experience.metrics.active_policies'))->assertSee(__('dashboards.metrics.premium_collected_30d'))
        ->assertSee(__('dashboards.widgets.my_work'))->assertSee(__('dashboards.widgets.open_claims'))->assertDontSee('POL-DASH-OTHER');
    $this->get('/admin/reports')->assertOk();
});
