<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\RenewalActions;
use App\Filament\Shared\Pages\HelpPage;
use App\Models\Policy;
use App\Models\PortalWorkspace;
use App\Models\RenewalCase;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Tests\Support\WorkflowActionHarness;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/** S3 2026-09-29: renewal case reassignment (API + /admin, /broker) and the last API actions given a web UI. */
beforeEach(function () {
    $this->fx = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->fx['tenant'];
    $this->h = tenantHeaderFor($this->tenant);
    $policy = makeMobileTestPolicy($this->fx['proposal'], $this->tenant, $this->fx['carrier']->id, $this->fx['party']->id, [
        'policy_number' => 'POL-S3-'.Str::random(5), 'coverage_starts_at' => now()->subYear(), 'coverage_ends_at' => now()->addDays(20)->setTime(23, 0)]);
    $this->case = RenewalCase::create(['tenant_id' => $this->tenant->id, 'policy_id' => $policy->id, 'status' => 'DUE', 'due_on' => now()->addDays(20)->toDateString()]);
    $this->ops = makeAuthTestUser($this->tenant, ['renewals.manage'], 'S3_OPS');
    $this->mate = makeAuthTestUser($this->tenant, ['renewals.manage'], 'S3_OPS');
});

function s3Web(User $u, string $tenantId, string $panel = 'admin'): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel($panel));
}

it('reassigns and unassigns a renewal case through the API, with trail and audit', function () {
    Passport::actingAs($this->ops);
    $this->postJson('/api/v1/renewals/'.$this->case->id.'/assignment', ['assignee_id' => $this->mate->id, 'reason' => 'Holiday cover'], $this->h)
        ->assertOk()->assertJsonPath('data.assigned_to', $this->mate->id);
    expect(DB::table('renewal_case_events')->where(['renewal_case_id' => $this->case->id, 'action' => 'REASSIGNED'])->exists())->toBeTrue()
        ->and(DB::table('audit_log')->where('action', 'renewal.reassigned')->exists())->toBeTrue();

    $this->postJson('/api/v1/renewals/'.$this->case->id.'/assignment', ['assignee_id' => $this->mate->id], $this->h)->assertStatus(422);
    $this->postJson('/api/v1/renewals/'.$this->case->id.'/assignment', ['assignee_id' => null], $this->h)->assertOk()->assertJsonPath('data.assigned_to', null);
});

it('refuses an assignee without renewals.manage, a caller without the permission, and a closed case', function () {
    $outsider = makeAuthTestUser($this->tenant, ['policies.read'], 'S3_READ');
    Passport::actingAs($this->ops);
    $this->postJson('/api/v1/renewals/'.$this->case->id.'/assignment', ['assignee_id' => $outsider->id], $this->h)->assertStatus(422);
    $this->postJson('/api/v1/renewals/'.$this->case->id.'/assignment', ['assignee_id' => (string) Str::uuid()], $this->h)->assertStatus(422);

    Passport::actingAs($outsider);
    $this->postJson('/api/v1/renewals/'.$this->case->id.'/assignment', ['assignee_id' => $this->mate->id], $this->h)->assertForbidden();

    $this->case->forceFill(['status' => 'RENEWED'])->save();
    Passport::actingAs($this->ops);
    $this->postJson('/api/v1/renewals/'.$this->case->id.'/assignment', ['assignee_id' => $this->mate->id], $this->h)->assertStatus(422);
});

it('scopes reassignment to the caller\'s own book and organisation', function () {
    // BROKER_STAFF = ASSIGNED scope: the policy's party is not in their book → 404 (no existence leak).
    $staff = makeAuthTestUser($this->tenant, ['renewals.manage'], 'BROKER_STAFF');
    Passport::actingAs($staff);
    $this->postJson('/api/v1/renewals/'.$this->case->id.'/assignment', ['assignee_id' => $this->mate->id], $this->h)->assertNotFound();

    // Another tenant's user cannot see the case at all.
    $other = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $foreign = makeAuthTestUser($other['tenant'], ['renewals.manage'], 'S3_OPS');
    Passport::actingAs($foreign);
    $this->postJson('/api/v1/renewals/'.$this->case->id.'/assignment', ['assignee_id' => null], tenantHeaderFor($other['tenant']))->assertNotFound();
    expect($this->case->fresh()->assigned_to)->toBeNull();
});

it('reassigns from the web through RenewalService, hidden without renewals.manage', function () {
    s3Web($this->ops, $this->tenant->id);
    WorkflowActionHarness::$actions = [fn () => RenewalActions::reassign()];
    Livewire::test(WorkflowActionHarness::class, ['model' => RenewalCase::class, 'recordId' => $this->case->id])
        ->callAction('renewalReassign', ['assignee_id' => $this->mate->id, 'reason' => 'Load balancing'])
        ->assertNotified(__('leftover_actions.renewalReassign.done'));
    expect($this->case->fresh()->assigned_to)->toBe($this->mate->id);

    s3Web(makeAuthTestUser($this->tenant, ['policies.read'], 'S3_READ'), $this->tenant->id);
    Livewire::test(WorkflowActionHarness::class, ['model' => RenewalCase::class, 'recordId' => $this->case->id])->assertActionHidden('renewalReassign');
});

it('saves the portal workspace from the help page through PortalWorkspaceService::touch', function () {
    s3Web($this->ops, $this->tenant->id);
    Livewire::test(HelpPage::class)->callAction('workspaceSave', ['start_page' => 'reports'])
        ->assertNotified(__('leftover_actions.workspaceSave.done'));
    $w = PortalWorkspace::query()->where(['user_id' => $this->ops->id, 'portal' => 'ADMIN'])->firstOrFail();
    expect($w->preferences['start_page'])->toBe('reports');
});

it('wires the customer vehicle documents, agent profile and agent lead pages to the API endpoints', function () {
    $vehicles = $this->get('/account/vehicles')->assertOk()->getContent();
    expect($vehicles)->toContain("'/mobile/assets/' + encodeURIComponent(a.id) + '/documents'")
        ->toContain("'/scan/' + encodeURIComponent(d.id) + '/confirm'")->toContain(__('leftover_actions.assets.docs_t'));
    $profile = $this->get('/account/profile')->assertOk()->getContent();
    expect($profile)->toContain("'/mobile/agent/profile'")->toContain('PAYOUT_DESTINATION_CHANGE');
    $leads = $this->get('/account/leads')->assertOk()->getContent();
    expect($leads)->toContain("'/mobile/partner/agent/leads/' + encodeURIComponent(l.id) + '/convert'");
});

it('has the same leftover_actions keys in English and French', function () {
    $flat = fn (array $a) => array_keys(\Illuminate\Support\Arr::dot($a));
    expect($flat(require lang_path('fr/leftover_actions.php')))->toEqualCanonicalizing($flat(require lang_path('en/leftover_actions.php')));
});

it('reports the legacy claim routes as DEPRECATED and the S3 actions as covered', function () {
    $path = 'storage/framework/testing-ui-coverage-s3.json';
    Artisan::call('ui:coverage', ['--json' => $path]);
    $rows = collect(json_decode((string) file_get_contents(base_path($path)), true)['actions']);
    @unlink(base_path($path));
    $row = fn (string $verb, string $p) => $rows->first(fn ($r) => $r['verb'] === $verb && $r['path'] === $p);

    foreach (['/claims/{id}/decisions', '/claims/{id}/recoveries', '/claims/{id}/recoveries/{recovery}/receipts'] as $p) {
        expect($row('POST', $p)['classification'])->toBe('DEPRECATED');
    }
    foreach ([['POST', '/renewals/{renewal}/assignment'], ['POST', '/mobile/assets/{asset}/documents'], ['POST', '/mobile/assets/{asset}/scan'],
        ['POST', '/mobile/assets/{asset}/scan/{document}/confirm'], ['PATCH', '/mobile/agent/profile'], ['PUT', '/web-experiences/{portal}/workspace'],
        ['PATCH', '/mobile/partner/agent/leads/{lead}'], ['POST', '/mobile/partner/agent/leads/{lead}/convert'], ['POST', '/public/accounts'],
        ['POST', '/public/verify'], ['POST', '/public/insurance/verify']] as [$v, $p]) {
        expect($row($v, $p)['covered'])->toBeTrue("{$v} {$p} should have a web entry point");
    }
});
