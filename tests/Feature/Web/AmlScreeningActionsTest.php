<?php

declare(strict_types=1);

/*
 * AML staff desktop (REQ-AML-001/002/003): screening hits queue, screening lists, transaction monitoring and STR
 * screens render for a role holding the API's read permission and are 403 without it; every AmlActions action is
 * hidden without the API permission and changes state through the same service with it. STRs stay invisible to
 * anyone without cases.str.view (tipping-off).
 */

use App\Application\Compliance\Aml\Screening\Models\ScreeningHit;
use App\Application\Compliance\Aml\Screening\Models\ScreeningListVersion;
use App\Application\Kyc\KycService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\Aml\AmlScreeningHits;
use App\Filament\Admin\Pages\Aml\AmlScreeningLists;
use App\Filament\Admin\Pages\Aml\AmlStrReports;
use App\Filament\Admin\Pages\Aml\AmlTransactionMonitoring;
use App\Filament\Shared\Actions\AmlActions;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\WorkflowActionHarness;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function amlUser(string $tenantId, array $permissions): User
{
    $u = User::create(['full_name' => 'AML '.Str::random(5), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => 'COMPLIANCE_ADMIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'AML-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function amlAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function amlHarness(array $actions, ?object $record = null)
{
    WorkflowActionHarness::$actions = $actions;

    return Livewire::test(WorkflowActionHarness::class, $record ? ['model' => $record::class, 'recordId' => $record->getKey()] : []);
}

function amlCustomer(Tenant $tenant, string $name): Party
{
    $p = Party::create(['type' => 'INDIVIDUAL', 'display_name' => $name, 'status' => 'ACTIVE', 'legal_identity' => []]);
    TenantCustomer::create(['tenant_id' => $tenant->id, 'party_id' => $p->id, 'customer_number' => 'C-'.Str::random(8)]);

    return $p;
}

const AML_ALL = ['aml.screening.view', 'aml.screening.run', 'aml.screening.disposition.propose', 'aml.screening.disposition.approve', 'aml.screening.lists.manage',
    'aml.screening.lists.approve', 'aml.risk.view', 'aml.risk.rate', 'aml.monitoring.evaluate', 'cases.str.view'];

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant'];
    app(TenantContext::class)->set($this->tenant->id);
});

it('renders the AML screens for a role holding the read permissions, and 403s without them', function () {
    $pages = ['/admin/aml/screening-hits', '/admin/aml/screening-lists', '/admin/aml/transaction-monitoring', '/admin/aml/str-reports'];
    $this->actingAs(amlUser($this->tenant->id, AML_ALL));
    foreach ($pages as $url) {
        $this->get($url)->assertOk();
    }
    $this->flushSession();
    $this->actingAs(amlUser($this->tenant->id, ['claims.view']));
    foreach ($pages as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('never lists the STR screen for a screening officer without cases.str.view (tipping-off)', function () {
    $this->actingAs(amlUser($this->tenant->id, ['aml.screening.view', 'aml.risk.view']));
    expect($this->get('/admin/aml/screening-hits')->assertOk()->getContent())->not->toContain('/admin/aml/str-reports');
    $this->get('/admin/aml/str-reports')->assertForbidden();
    app(TenantContext::class)->set($this->tenant->id);
    expect(AmlStrReports::canAccess())->toBeFalse()->and(AmlScreeningHits::canAccess())->toBeTrue();
});

it('hides every AML action from a user without the API permission', function () {
    amlAs(amlUser($this->tenant->id, ['claims.view']), $this->tenant->id);
    $party = amlCustomer($this->tenant, 'Hidden Person');
    foreach (['screenParty', 'riskRate'] as $name) {
        amlHarness([fn () => AmlActions::$name()], $party)->assertActionHidden($name);
    }
    foreach (['listCreate', 'listImport', 'monitorEvaluate', 'strDraft'] as $name) {
        amlHarness([fn () => AmlActions::$name()])->assertActionHidden($name);
    }
});

it('imports and approves a list, screens a party, and proposes + decides a hit disposition through the services', function () {
    $maker = amlUser($this->tenant->id, AML_ALL);
    $checker = amlUser($this->tenant->id, AML_ALL);
    amlAs($maker, $this->tenant->id);
    amlHarness([fn () => AmlActions::listCreate()])->callAction('listCreate', ['code' => 'UI_SANCTIONS', 'name' => 'UI sanctions', 'list_type' => 'SANCTIONS'])
        ->assertNotified(__('aml_actions.listCreate.done'));
    $src = DB::table('screening_list_sources')->where('tenant_id', $this->tenant->id)->where('code', 'UI_SANCTIONS')->first();
    $csv = "entry_ref,name,aliases,date_of_birth,country\nT-1,Zorblat Quenvik,,1970-01-02,ZZ\n";
    amlHarness([fn () => AmlActions::listImport()])->callAction('listImport', ['source_id' => $src->id, 'content' => $csv, 'source_reference' => 'ui-test'])
        ->assertNotified(__('aml_actions.listImport.done'));
    $v = ScreeningListVersion::where('source_id', $src->id)->firstOrFail();
    expect($v->status)->toBe('PENDING_APPROVAL');

    // The maker cannot approve their own import (four eyes, refused by the service and shown, not silent).
    amlHarness([fn () => AmlActions::listVersionDecide()], $v)->callAction('listVersionDecide', ['decision' => 'APPROVE']);
    expect($v->refresh()->status)->toBe('PENDING_APPROVAL');
    amlAs($checker, $this->tenant->id);
    Livewire::test(AmlScreeningLists::class)->callAction(TestAction::make('listVersionDecide')->table($v), ['decision' => 'APPROVE', 'note' => 'ok'])
        ->assertNotified(__('aml_actions.listVersionDecide.done'));
    expect($v->refresh()->status)->toBe('ACTIVE');

    amlAs($maker, $this->tenant->id);
    $party = amlCustomer($this->tenant, 'Zorblat Quenvik');
    amlHarness([fn () => AmlActions::screenParty()], $party)->callAction('screenParty')->assertNotified(__('aml_actions.screenParty.done'));
    $hit = ScreeningHit::where('party_id', $party->id)->firstOrFail();
    expect($hit->status)->toBe('OPEN');

    Livewire::test(AmlScreeningHits::class)->assertCanSeeTableRecords([$hit])
        ->callAction(TestAction::make('hitPropose')->table($hit), ['disposition' => 'FALSE_POSITIVE', 'rationale' => 'Different date of birth'])
        ->assertNotified(__('aml_actions.hitPropose.done'));
    expect($hit->refresh()->status)->toBe('PROPOSED');
    amlAs($checker, $this->tenant->id);
    amlHarness([fn () => AmlActions::hitDecide()], $hit)->callAction('hitDecide', ['decision' => 'APPROVE'])->assertNotified(__('aml_actions.hitDecide.done'));
    expect($hit->refresh()->status)->toBe('DISPOSED')->and($hit->disposition)->toBe('FALSE_POSITIVE');
});

it('rates AML risk and evaluates a transaction through the services', function () {
    amlAs(amlUser($this->tenant->id, AML_ALL), $this->tenant->id);
    $party = amlCustomer($this->tenant, 'Rated Customer');
    app(KycService::class)->draftFor($party, $this->tenant->id);   // the rating needs a KYC submission
    amlHarness([fn () => AmlActions::riskRate()], $party)->callAction('riskRate', ['reason' => 'Periodic review', 'customer_type' => 'INDIVIDUAL'])
        ->assertNotified(__('aml_actions.riskRate.done'));
    expect(DB::table('audit_log')->where('action', 'aml.risk.rated')->where('subject_id', $party->id)->exists())->toBeTrue();

    Livewire::test(AmlTransactionMonitoring::class)->callAction(TestAction::make('monitorEvaluate')->table(), ['subject_type' => 'PAYMENT', 'subject_id' => (string) Str::uuid(),
        'party_id' => $party->id, 'facts' => ['amount_minor' => '9000000', 'channel' => 'CASH']])
        ->assertNotified(__('aml_actions.monitorEvaluate.done'));
});

it('drafts and submits an STR (four eyes) and keeps STRs away from users without cases.str.view', function () {
    $drafter = amlUser($this->tenant->id, ['cases.str.view']);
    $submitter = amlUser($this->tenant->id, ['cases.str.view']);
    $party = amlCustomer($this->tenant, 'Suspicious Customer');
    amlAs($drafter, $this->tenant->id);
    Livewire::test(AmlStrReports::class)->callAction(TestAction::make('strDraft')->table(), ['party_id' => $party->id, 'grounds' => 'Cash premium payments split below the reporting threshold.'])
        ->assertNotified(__('aml_actions.strDraft.done'));
    $str = DB::table('aml_str_reports')->where('tenant_id', $this->tenant->id)->first();
    expect($str->status)->toBe('DRAFT');

    // The drafter cannot submit (four eyes).
    Livewire::test(AmlStrReports::class)->callAction(TestAction::make('strSubmit')->table($str->id), ['regulator_reference' => 'ANIF-1']);
    expect(DB::table('aml_str_reports')->where('id', $str->id)->value('status'))->toBe('DRAFT');

    amlAs($submitter, $this->tenant->id);
    Livewire::test(AmlStrReports::class)->assertSee('Suspicious Customer')
        ->callAction(TestAction::make('strSubmit')->table($str->id), ['regulator_reference' => 'ANIF-2026-001'])
        ->assertNotified(__('aml_actions.strSubmit.done'));
    $row = DB::table('aml_str_reports')->where('id', $str->id)->first();
    expect($row->status)->toBe('SUBMITTED')->and($row->regulator_reference)->toBe('ANIF-2026-001');

    // No cases.str.view: no draft action, and the page does not mount.
    amlAs(amlUser($this->tenant->id, array_values(array_diff(AML_ALL, ['cases.str.view']))), $this->tenant->id);
    amlHarness([fn () => AmlActions::strDraft()])->assertActionHidden('strDraft');
    Livewire::test(AmlStrReports::class)->assertForbidden();
});
