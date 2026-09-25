<?php

declare(strict_types=1);

/**
 * REQ-UI-001 (insurer + broker Filament portals, tenant-scoped, RBAC-aware)
 * REQ-UI-002 (shared list/detail/timeline/document/financial/authority/failure components)
 */

use App\Application\Audit\AuditWriter;
use App\Application\WebExperiences\{AuthorityAssessor, FailureState, PortalAccess, PortalDashboardMetrics, RecordSummaryFactory, TimelineQuery};
use App\Domain\Tenancy\TenantContext;
use App\Models\{ApprovalMatrixRule, Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function webExpTenant(string $type = 'CARRIER'): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'WebExp '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function webExpUser(Tenant $tenant, string $role, array $permissions = ['*']): User
{
    $user = User::factory()->create(['status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => $permissions, 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

test('REQ-UI-001 guests are sent to each portal login', function () {
    $this->get('/insurer')->assertRedirect('/insurer/login');
    $this->get('/broker')->assertRedirect('/broker/login');
    $this->get('/insurer/login')->assertOk();
    $this->get('/broker/login')->assertOk();
});

test('REQ-UI-001 portal entry follows the role set: carrier roles to insurer, broker roles to broker, never crossed', function () {
    $carrier = webExpTenant('CARRIER');
    $broker = webExpTenant('BROKER');
    $insurerUser = webExpUser($carrier, 'CARRIER_ADMIN');
    $brokerUser = webExpUser($broker, 'BROKER_STAFF');
    $customer = webExpUser($broker, 'CUSTOMER', []);

    $this->actingAs($insurerUser)->get('/insurer')->assertOk()->assertSee(__('web_experience.metrics.active_policies'));
    $this->actingAs($insurerUser)->get("/broker")->assertForbidden();
    $this->flushSession();
    $this->actingAs($brokerUser)->get('/broker')->assertOk()->assertSee(__('web_experience.metrics.quotes_30d'));
    $this->flushSession();
    $this->actingAs($brokerUser)->get('/insurer')->assertForbidden();
    $this->actingAs($customer)->get('/broker')->assertForbidden();

    // Admin panel rule is unchanged: CARRIER_* still refused there.
    expect($insurerUser->canAccessPanel(app(\Filament\PanelRegistry::class)->get('admin')))->toBeFalse();
});

test('REQ-UI-001 suspended membership or suspended tenant loses portal access', function () {
    $carrier = webExpTenant('CARRIER');
    $user = webExpUser($carrier, 'UNDERWRITER');
    expect(app(PortalAccess::class)->allows($user, 'insurer'))->toBeTrue();
    $carrier->update(['status' => 'SUSPENDED']);
    expect(app(PortalAccess::class)->allows($user->fresh(), 'insurer'))->toBeFalse();
});

test('REQ-UI-001 portal is tenant-scoped: dashboards and lists show only the entry tenant book', function () {
    $mine = webExpTenant('CARRIER');
    $other = webExpTenant('CARRIER');
    $a = makeMobileFinanceProposalChain($mine);
    $b = makeMobileFinanceProposalChain($other);
    $p1 = makeMobileTestPolicy($a['proposal'], $mine, $a['carrier']->id, $a['party']->id, ['policy_number' => 'POL-MINE-1', 'premium_minor' => 5000000]);
    makeMobileTestPolicy($b['proposal'], $other, $b['carrier']->id, $b['party']->id, ['policy_number' => 'POL-OTHER-1']);

    $metrics = collect(app(PortalDashboardMetrics::class)->for('insurer', $mine->id))->keyBy('key');
    expect($metrics['active_policies']['value'])->toBe(1);

    $user = webExpUser($mine, 'CARRIER_STAFF');
    $this->actingAs($user)->get('/insurer/policies')->assertOk()->assertSee('POL-MINE-1')->assertDontSee('POL-OTHER-1');
    $this->actingAs($user)->get('/insurer/policies/'.$p1->id)->assertOk()
        ->assertSee('POL-MINE-1')                                   // detail header identifier
        ->assertSee(__('web_experience.tabs.timeline'))
        ->assertSee(__('web_experience.tabs.documents'))
        ->assertSee(__('web_experience.tabs.financial'));
});

test('REQ-UI-002 record summary contract carries identifier, status tone and metadata', function () {
    $t = webExpTenant();
    $c = makeMobileFinanceProposalChain($t);
    $p = makeMobileTestPolicy($c['proposal'], $t, $c['carrier']->id, $c['party']->id, ['policy_number' => 'POL-SUM-1', 'premium_minor' => 1234500]);
    $s = app(RecordSummaryFactory::class)->for($p)->toArray();
    expect($s['entity'])->toBe('policy')->and($s['identifier'])->toBe('POL-SUM-1')->and($s['tone'])->toBe('success')
        ->and($s['metadata'][__('web_experience.meta.premium')])->toBe('12 345 XAF')
        ->and($s['tabs'])->toContain('timeline', 'documents', 'financial');
});

test('REQ-UI-002 timeline reads audit_log for the subject with actor role, tenant-scoped', function () {
    $t = webExpTenant();
    $user = webExpUser($t, 'CARRIER_ADMIN');
    $this->actingAs($user);
    app(TenantContext::class)->set($t->id);
    $id = (string) Str::uuid();
    app(AuditWriter::class)->record('policy.issued', 'policy', $id, ['status' => 'ACTIVE'], 'NEW_BUSINESS');
    app(AuditWriter::class)->record('policy.issued', 'policy', (string) Str::uuid());

    $entries = app(TimelineQuery::class)->for('policy', $id, $t->id);
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('policy.issued')
        ->and($entries[0]['result'])->toBe('ACTIVE')
        ->and($entries[0]['reason'])->toBe('NEW_BUSINESS')
        ->and($entries[0]['role'])->toBe('CARRIER_ADMIN')
        ->and($entries[0]['actor'])->toBe($user->full_name);
    expect(app(TimelineQuery::class)->for('policy', $id, webExpTenant()->id))->toBe([]);
});

test('REQ-UI-002 authority widget: within/referral from the approval matrix, rule internals hidden without approvals.matrix.view', function () {
    $t = webExpTenant();
    ApprovalMatrixRule::create(['tenant_id' => $t->id, 'action_code' => 'claim.payment.approve', 'workflow' => 'CLAIM', 'category' => 'FINANCIAL', 'min_amount' => 0, 'max_amount' => 1000000, 'currency' => 'XAF', 'checker_roles' => ['CLAIMS_MANAGER'], 'required_approvals' => 1, 'status' => 'ACTIVE', 'priority' => 1]);
    app(TenantContext::class)->set($t->id);

    $manager = webExpUser($t, 'CLAIMS_MANAGER', ['approvals.matrix.view']);
    $a = app(AuthorityAssessor::class)->assess($manager, 'claim.payment.approve', 50000000, 'XAF');
    expect($a['rule_found'])->toBeTrue()->and($a['within_authority'])->toBeTrue()->and($a['referral_required'])->toBeFalse()
        ->and($a['required_authority']['roles'])->toBe(['CLAIMS_MANAGER']);

    $staff = webExpUser($t, 'CARRIER_STAFF', ['claims.view']);
    $b = app(AuthorityAssessor::class)->assess($staff, 'claim.payment.approve', 50000000, 'XAF');
    expect($b['within_authority'])->toBeFalse()->and($b['referral_required'])->toBeTrue()
        ->and($b['required_authority'])->toBeNull()->and($b['your_authority'])->toBeNull();
});

test('REQ-UI-002 failure states are explicit, bilingual and derived from persisted facts', function () {
    foreach (FailureState::cases() as $state) {
        app()->setLocale('en');
        $en = $state->title();
        app()->setLocale('fr');
        $fr = $state->title();
        expect($en)->not->toStartWith('web_experience.')->and($fr)->not->toStartWith('web_experience.')->and($fr)->not->toBe($en);
    }
    app()->setLocale('en');

    $t = webExpTenant();
    $c = makeMobileFinanceProposalChain($t);
    $pay = makeMobileTestPayment($c['proposal'], $t, ['status' => 'SUCCEEDED']);
    $p = makeMobileTestPolicy($c['proposal'], $t, $c['carrier']->id, $c['party']->id, ['status' => 'PAID_PENDING_ISSUANCE', 'payment_intent_id' => $pay->id]);
    expect(FailureState::detect($p))->toBe([]);
    \Illuminate\Support\Facades\DB::table('policy_issuance_requests')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'proposal_id' => $c['proposal']->id, 'payment_intent_id' => $pay->id, 'carrier_id' => $c['carrier']->id, 'status' => 'REJECTED', 'authority_snapshot' => '{}', 'terms_hash' => str_repeat('a', 64), 'coverage_starts_at' => now(), 'coverage_ends_at' => now()->addYear(), 'requested_by' => webExpUser($t, 'CARRIER_STAFF')->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(FailureState::detect($p->fresh()))->toBe([FailureState::PaymentOkIssuanceFailed]);
});

test('REQ-UI-002 shared list screen: empty vs no-result states, column toggles and CSV export on adopted resources', function () {
    $t = webExpTenant('BROKER');
    $user = webExpUser($t, 'BROKER_ADMIN');
    $this->actingAs($user)->get('/broker/quotes')->assertOk()->assertSee(__('web_experience.list.empty_heading'));

    $table = \Filament\Tables\Table::make(new \App\Filament\Admin\Resources\Policies\Pages\ListPolicies);
    $table = \App\Filament\Admin\Resources\Policies\PolicyResource::table($table);
    foreach ($table->getColumns() as $column) {
        expect($column->isToggleable())->toBeTrue();
    }
    expect(collect($table->getToolbarActions())->map(fn ($a) => $a->getName())->all())->toContain('exportCsv');
});

test('REQ-UI-001 portal record access is RBAC-aware: no policies.read, no policy list; other-tenant record is 403/404', function () {
    $mine = webExpTenant('CARRIER');
    $other = webExpTenant('CARRIER');
    $b = makeMobileFinanceProposalChain($other);
    $foreign = makeMobileTestPolicy($b['proposal'], $other, $b['carrier']->id, $b['party']->id, ['policy_number' => 'POL-FOREIGN']);

    $limited = webExpUser($mine, 'CUSTOMER_SERVICE', ['claims.view']);
    $this->actingAs($limited)->get('/insurer')->assertOk();
    $this->actingAs($limited)->get('/insurer/policies')->assertForbidden();
    $this->actingAs($limited)->get('/insurer/claims')->assertOk();
    $this->flushSession();

    $reader = webExpUser($mine, 'CARRIER_STAFF', ['policies.read']);
    expect($this->actingAs($reader)->get('/insurer/policies/'.$foreign->id)->status())->toBeIn([403, 404]);
});

test('REQ-UI-002 adopted admin resources render the shared record shell (policy + claim with authority widget)', function () {
    $t = webExpTenant('PLATFORM');
    $c = makeMobileFinanceProposalChain($t);
    $p = makeMobileTestPolicy($c['proposal'], $t, $c['carrier']->id, $c['party']->id, ['policy_number' => 'POL-ADM-1']);
    $claim = \App\Models\Claim::create(['tenant_id' => $t->id, 'policy_id' => $p->id, 'claim_number' => 'CLM-ADM-1', 'status' => 'OPEN', 'loss_occurred_at' => now()->subDay(), 'loss_details' => []]);
    $admin = webExpUser($t, 'CLAIMS_MANAGER');
    TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $admin->id, 'role_code' => 'PLATFORM_ADMIN', 'status' => 'ACTIVE'])
        ->roles()->syncWithoutDetaching([Role::where(['tenant_id' => $t->id, 'code' => 'CLAIMS_MANAGER'])->value('id')]);

    $this->actingAs($admin)->get('/admin/policies')->assertOk()->assertSee('POL-ADM-1');
    $this->actingAs($admin)->get('/admin/policies/'.$p->id)->assertOk()->assertSee('data-testid="record-identifier"', false)->assertSee('POL-ADM-1');
    $this->actingAs($admin)->get('/admin/claims/'.$claim->id)->assertOk()->assertSee('CLM-ADM-1')->assertSee(__('web_experience.tabs.authority'));
});
