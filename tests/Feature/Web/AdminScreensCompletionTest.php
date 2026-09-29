<?php

declare(strict_types=1);

use App\Application\Ledger\Periods\AccountingPeriodService;
use App\Application\Operations\SystemHealthService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\AdminScreens;
use App\Filament\Shared\Pages\BranchOverviewPage;
use App\Models\ApprovalRequest;
use App\Models\IntegrationClient;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Q10 2026-09-29 — admin screens completing docs/LAUNCH_SCREEN_GAPS_2026-09-29.md §3-§4: every screen opens for the
 * API's permission and is refused without it (EN + FR, no raw keys), and the write screens go through the same
 * services with maker-checker / tenant scoping kept.
 */
const ASC_SCREENS = [
    '/admin/finance/reconciliation-dashboard' => 'reconciliation.read',
    '/admin/compliance/risk-dashboard' => 'fraud.alert.decide',
    '/admin/compliance/claim-fraud-review' => 'fraud.alert.decide',
    '/admin/approvals/payment-risk' => 'approvals.inbox.view',
    '/admin/approvals/policy-exceptions' => 'approvals.inbox.view',
    '/admin/approvals/underwriting-overrides' => 'approvals.inbox.view',
    '/admin/approvals/commission-exceptions' => 'approvals.inbox.view',
    '/admin/organisation/branding' => 'documents.letterheads.manage',
    '/admin/access/roles' => 'identity.roles.manage',
    '/admin/access/permission-matrix' => 'identity.roles.manage',
    '/admin/organisation/structure' => 'tenant.manage',
    '/admin/cases/workflow-configuration' => 'cases.view',
    '/admin/platform/feature-flags' => 'tenant.manage',
    '/admin/integrations/carrier-api-monitoring' => 'integrations.manage',
    '/admin/integrations/payment-provider-monitoring' => 'finance.accounts.view',
    '/admin/operations/security-dashboard' => 'security.centre.read',
    '/admin/reports/regulatory-dashboard' => 'reporting.reports.view',
    '/admin/reports/premium-production' => 'reporting.reports.view',
    '/admin/reports/claims' => 'reporting.reports.view',
    '/admin/reports/intermediaries' => 'reporting.reports.view',
    '/admin/reports/commissions' => 'reporting.reports.view',
    '/admin/finance/receivables' => 'finance.obligations.view',
    '/admin/finance/period-closing' => 'ledger.read',
    '/admin/compliance/corporate-due-diligence' => 'kyc.view',
    '/admin/compliance/expired-documentation' => 'kyc.view',
];

function ascTenant(string $type = 'CARRIER'): string
{
    return Tenant::create(['type' => $type, 'legal_name' => 'ASC '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []])->id;
}

function ascUser(string $tenantId, array $permissions, string $roleCode = 'FINANCE_MANAGER', ?string $branchId = null): User
{
    $u = User::create(['full_name' => 'ASC '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $roleCode, 'status' => 'ACTIVE', 'branch_id' => $branchId]);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'ASC-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function ascAs(User $u, string $tenantId, string $panel = 'admin'): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel($panel));
}

beforeEach(function () {
    $this->tenant = ascTenant();
});

it('opens every completed admin screen with the API permission, EN and FR, and refuses it without', function () {
    $user = ascUser($this->tenant, array_values(array_unique(array_values(ASC_SCREENS))));
    $none = ascUser($this->tenant, ['claims.view']);
    foreach (['en', 'fr'] as $lang) {
        foreach (ASC_SCREENS as $url => $perm) {
            $this->flushSession();
            auth()->forgetGuards();
            $this->actingAs($user);
            app(TenantContext::class)->set($this->tenant);
            $res = $this->get($url.'?lang='.$lang);
            expect($res->status())->toBe(200, "{$url} {$lang}");
            $html = $res->getContent();
            expect($html)->not->toContain('admin_screens.', "{$url} {$lang} raw key");
            if ($lang === 'fr') {
                $h1 = preg_match('#<h1\b[^>]*>(.*?)</h1>#s', $html, $m) ? html_entity_decode(trim(strip_tags($m[1]))) : '';
                expect($h1)->not->toBe('', $url)->and(str_contains($html, e($h1)) || str_contains($html, $h1))->toBeTrue();
                expect(array_key_exists($h1, array_flip((array) trans('admin_screens.nav', [], 'fr'))) || str_starts_with($h1, 'Mon'))->toBeTrue("{$url} FR heading $h1");
            }
            $this->flushSession();
            auth()->forgetGuards();
            $this->actingAs($none);
            app(TenantContext::class)->set($this->tenant);
            expect($this->get($url)->status())->toBe(403, "{$url} without {$perm}");
        }
    }
});

it('shows the interface-string report only to the platform localisation roles', function () {
    $admin = ascUser($this->tenant, [], 'PLATFORM_ADMIN');
    $this->actingAs($admin);
    app(TenantContext::class)->set($this->tenant);
    $this->get('/admin/platform/ui-strings?lang=fr')->assertOk()->assertSee('Textes de l’interface', false);
    expect(AdminScreens\UiStrings::report())->toHaveKey('admin_screens')
        ->and(AdminScreens\UiStrings::report()['admin_screens']['missing'])->toBe([]);

    $this->flushSession();
    auth()->forgetGuards();
    $this->actingAs(ascUser($this->tenant, ['tenant.manage']));
    app(TenantContext::class)->set($this->tenant);
    expect($this->get('/admin/platform/ui-strings')->status())->toBe(403);
});

it('closes accounting periods over HTTP and on the screen, tenant-scoped, with maker-checker reopening', function () {
    $svc = app(AccountingPeriodService::class);
    $period = $svc->ensurePeriod($this->tenant, now()->subMonths(2)->startOfMonth());
    $foreign = $svc->ensurePeriod(ascTenant(), now()->subMonths(2)->startOfMonth());
    $closer = ascUser($this->tenant, ['ledger.read', 'ledger.periods.close', 'ledger.periods.reopen']);
    $checker = ascUser($this->tenant, ['ledger.read', 'ledger.periods.reopen']);
    $reader = ascUser($this->tenant, ['ledger.read']);

    // HTTP: list is tenant-scoped; a reader cannot close; another tenant's period is not found.
    \Laravel\Passport\Passport::actingAs($reader, [], 'api');
    $ids = collect($this->getJson('/api/v1/ledger/periods', ['X-Tenant-Id' => $this->tenant])->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($period->id)->not->toContain($foreign->id);
    $this->postJson("/api/v1/ledger/periods/{$period->id}/start-close", [], ['X-Tenant-Id' => $this->tenant])->assertForbidden();
    \Laravel\Passport\Passport::actingAs($closer, [], 'api');
    $this->postJson("/api/v1/ledger/periods/{$foreign->id}/start-close", [], ['X-Tenant-Id' => $this->tenant])->assertNotFound();

    // Screen: start close → close, then maker-checker reopening.
    ascAs($closer, $this->tenant);
    $page = Livewire::test(AdminScreens\PeriodClosing::class);
    $page->callTableAction('periodStartClose', $period->id);
    expect(DB::table('accounting_periods')->where('id', $period->id)->value('status'))->toBe('CLOSING');
    $check = $svc->checklist($period->id);
    expect($check['blocking'])->toBe([]);
    if ($check['blocking'] === []) {
        Livewire::test(AdminScreens\PeriodClosing::class)->callTableAction('periodClose', $period->id);
        expect(DB::table('accounting_periods')->where('id', $period->id)->value('status'))->toBe('CLOSED');
        Livewire::test(AdminScreens\PeriodClosing::class)->callTableAction('periodReopenRequest', $period->id, ['reason' => 'Late supplier invoice to post']);
        expect(DB::table('accounting_periods')->where('id', $period->id)->value('reopen_status'))->toBe('PENDING');
        // The requester does not see the approval; the service refuses it anyway.
        Livewire::test(AdminScreens\PeriodClosing::class)->assertTableActionHidden('periodReopenApprove', $period->id);
        expect(fn () => $svc->approveReopen($period->id, $closer))->toThrow(\Illuminate\Validation\ValidationException::class);
        ascAs($checker, $this->tenant);
        Livewire::test(AdminScreens\PeriodClosing::class)->callTableAction('periodReopenApprove', $period->id);
        expect(DB::table('accounting_periods')->where('id', $period->id)->value('status'))->toBe('REOPENED');
    }
    ascAs($reader, $this->tenant);
    Livewire::test(AdminScreens\PeriodClosing::class)->assertTableActionHidden('periodStartClose', $period->id);
});

it('creates an API client and shows its secret once, then subscribes an active client to a webhook', function () {
    // Integration clients are platform-wide: only the PLATFORM tenant manages them.
    $platform = ascTenant('PLATFORM');
    ascAs(ascUser($this->tenant, ['integrations.manage'], 'SYSTEM_ADMIN'), $this->tenant);
    expect(AdminScreens\ApiClients::canAccess())->toBeFalse()->and(AdminScreens\WebhookConfiguration::canAccess())->toBeFalse();
    $dev = ascUser($platform, ['integrations.manage'], 'SYSTEM_ADMIN');
    ascAs($dev, $platform);
    foreach (['/admin/integrations/api-clients', '/admin/integrations/webhooks'] as $url) {
        $this->get($url.'?lang=fr')->assertOk()->assertDontSee('admin_screens.');
        app(TenantContext::class)->set($platform);
    }
    $scope = \App\Application\Integrations\Developer\OAuthScopeCatalogue::scopes()[0];
    $page = Livewire::test(AdminScreens\ApiClients::class)
        ->callAction('apiClientCreate', ['name' => 'Partner ERP', 'environment' => 'sandbox', 'rate_limit_per_minute' => 60, 'scopes' => [$scope], 'allowed_ips' => []])
        ->assertHasNoActionErrors();
    $client = IntegrationClient::where('name', 'Partner ERP')->firstOrFail();
    expect($client->status)->toBe('DRAFT')->and($page->get('issued.client_id'))->toBe($client->client_id)->and($page->get('issued.secret'))->not->toBeEmpty();
    expect(DB::table('audit_log')->where('action', 'integration.client.created')->exists() || DB::table('audit_events')->where('action', 'integration.client.created')->exists())->toBeTrue();
    $page->call('dismissSecret')->assertSet('issued', null);

    $event = DB::table('canonical_event_schemas')->where('status', 'ACTIVE')->value('event_name');
    if ($event === null) {
        $event = 'policy.issued';
        DB::table('canonical_event_schemas')->insert(['id' => (string) Str::uuid(), 'event_name' => $event, 'version' => 1, 'status' => 'ACTIVE', 'json_schema' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }
    // DRAFT client: refused (409 in the API).
    expect(fn () => app(\App\Application\Integrations\WebhookSubscriptionService::class)->subscribe($client, $event, 'https://erp.example.test/hook'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $client->forceFill(['status' => 'ACTIVE'])->save();
    $hooks = Livewire::test(AdminScreens\WebhookConfiguration::class)
        ->callAction('webhookCreate', ['integration_client_id' => $client->id, 'event_name' => $event, 'endpoint' => 'https://erp.example.test/hook'])
        ->assertHasNoActionErrors();
    expect(DB::table('integration_webhook_subscriptions')->where('integration_client_id', $client->id)->count())->toBe(1)
        ->and($hooks->get('issued.secret'))->toHaveLength(64);

    ascAs(ascUser($platform, ['integrations.health.view']), $platform);
    expect(AdminScreens\ApiClients::canAccess())->toBeFalse();
});

it('keeps feature flags to the caller organisation unless it is the platform', function () {
    $admin = ascUser($this->tenant, ['tenant.manage']);
    ascAs($admin, $this->tenant);
    Livewire::test(AdminScreens\FeatureFlagsScreen::class)->callAction('flagSet', ['key' => 'motor.quick_quote', 'enabled' => true])->assertHasNoActionErrors();
    expect(DB::table('feature_flags')->where('key', 'motor.quick_quote')->value('tenant_id'))->toBe($this->tenant);

    $page = Livewire::test(AdminScreens\FeatureFlagsScreen::class)->instance();
    expect(fn () => $page->setFlag(['key' => 'motor.quick_quote', 'enabled' => false, 'tenant_id' => ascTenant()]))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('narrows each exception review queue to its action codes and tenant, with the shared approval actions', function () {
    $requester = ascUser($this->tenant, ['approvals.inbox.view']);
    $mk = fn (string $code, ?string $tenant = null) => ApprovalRequest::create(['tenant_id' => $tenant ?? $this->tenant, 'action_code' => $code, 'subject_type' => 'policy',
        'subject_id' => (string) Str::uuid(), 'status' => 'PENDING', 'required_approvals' => 1, 'approvals_count' => 0, 'requested_by' => $requester->id, 'reason' => 'Test '.$code]);
    $refund = $mk('refund.approve');
    $override = $mk('underwriting.override');
    $foreign = $mk('refund.approve', ascTenant());
    ascAs(ascUser($this->tenant, ['approvals.inbox.view', 'approvals.decide']), $this->tenant);
    Livewire::test(AdminScreens\PaymentRiskReview::class)->assertCanSeeTableRecords([$refund])->assertCanNotSeeTableRecords([$override, $foreign])
        ->assertTableActionVisible('approvalApprove', $refund);
    Livewire::test(AdminScreens\UnderwritingOverrideReview::class)->assertCanSeeTableRecords([$override])->assertCanNotSeeTableRecords([$refund]);
    expect(array_intersect(AdminScreens\PaymentRiskReview::actionCodes(), AdminScreens\PolicyExceptionReview::actionCodes(),
        AdminScreens\UnderwritingOverrideReview::actionCodes(), AdminScreens\CommissionExceptionReview::actionCodes()))->toBe([]);
});

it('reports cache and API health in the system health checks', function () {
    $checks = app(SystemHealthService::class)->summary()['checks'];
    expect($checks)->toHaveKeys(['cache', 'api'])
        ->and($checks['cache']['status'])->toBe(SystemHealthService::OK)
        ->and($checks['api']['routes'])->toBeGreaterThan(100)
        ->and(__('launch_screens.checks.api'))->toBe('API');
});

it('shows receivables of the caller organisation only, with their ageing', function () {
    $other = ascTenant();
    $mk = fn (string $tenant, int $days, int $minor) => DB::table('financial_obligations')->insert([
        'id' => (string) Str::uuid(), 'source_type' => 'policy', 'source_id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'kind' => 'RECEIVABLE', 'type' => 'PREMIUM', 'debtor_type' => 'PARTY', 'debtor_id' => (string) Str::uuid(),
        'currency' => 'XAF', 'amount_minor' => $minor, 'outstanding_minor' => $minor, 'due_at' => now()->subDays($days), 'status' => 'OPEN',
        'source_reference' => 'REC-'.$days, 'created_at' => now(), 'updated_at' => now(), 'metadata' => '{}']);
    $mk($this->tenant, 45, 150000);
    $mk($other, 10, 99000);
    ascAs(ascUser($this->tenant, ['finance.obligations.view']), $this->tenant);
    Livewire::test(AdminScreens\Receivables::class)->assertSee('REC-45')->assertDontSee('REC-10');
    expect(collect(Livewire::test(AdminScreens\Receivables::class)->instance()->kpis())->pluck('value')->implode(' '))->toContain('1');
});

it('opens the branch overview only for a branch manager with a branch, scoped to that branch', function () {
    $tenant = ascTenant('BROKER');
    $branch = (string) Str::uuid();
    $otherBranch = (string) Str::uuid();
    foreach ([$branch => 'Douala', $otherBranch => 'Yaoundé'] as $id => $name) {
        DB::table('tenant_branches')->insert(['id' => $id, 'tenant_id' => $tenant, 'code' => 'BR-'.Str::random(4), 'name' => $name, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }
    $manager = ascUser($tenant, ['customers.read'], 'BRANCH_MANAGER', $branch);
    $colleague = ascUser($tenant, ['customers.read'], 'BROKER_STAFF', $branch);
    $elsewhere = ascUser($tenant, ['customers.read'], 'BROKER_STAFF', $otherBranch);
    ascAs($manager, $tenant, 'broker');
    Livewire::test(BranchOverviewPage::class)->assertSet('branchId', $branch)->assertSee($colleague->full_name)->assertDontSee($elsewhere->full_name);

    ascAs(ascUser($tenant, ['customers.read'], 'BRANCH_MANAGER'), $tenant, 'broker');
    expect(BranchOverviewPage::canAccess())->toBeFalse();
});

it('shows the role catalogue, role details and the permission matrix from RoleCatalogue and config/permissions.php', function () {
    ascAs(ascUser($this->tenant, ['identity.roles.manage']), $this->tenant);
    $roles = Livewire::test(AdminScreens\RoleCatalogueViewer::class)->instance()->rows();
    expect($roles)->toHaveKey('CLAIMS_OFFICER')->and(AdminScreens\RoleCatalogueViewer::details('CLAIMS_OFFICER'))->not->toBe([]);
    $matrix = AdminScreens\PermissionMatrix::rows();
    expect($matrix)->toHaveKey('ledger.periods.close')->and($matrix['ledger.periods.close']['granted'])->toContain('FINANCE_OFFICER');
    Livewire::test(AdminScreens\PermissionMatrix::class)->call('loadTable')->set('tableSearch', 'ledger.periods.close')->assertSee('ledger.periods.close');
});
