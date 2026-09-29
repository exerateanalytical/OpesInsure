<?php

declare(strict_types=1);

/**
 * R7 launch verification (2026-09-29) of the insurer portal (/insurer). Every insurer role, carrier-linked, with its
 * RoleCatalogue default permissions: every nav page renders, every visible header / table / first-row action mounts
 * and runs with empty data to a success or a meaningful refusal (validation or notification), never an exception,
 * and no page ever shows another carrier's records. The business journeys themselves are covered by
 * InsurerPortalClaimsTest / InsurerPortalUnderwritingTest / InsurerPortalRiskDocsTest / ClaimsWorkbenchTest and the
 * journeys at the bottom of this file.
 */

use App\Application\Identity\RoleCatalogue;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Claims\Pages\{ListClaims, ViewClaim};
use App\Filament\Admin\Resources\Policies\Pages\ViewPolicy;
use App\Filament\Shared\Pages\ClaimsWorkbench\AssignmentWorkbench;
use App\Filament\Shared\Pages\Insurer\CancellationReview;
use App\Models\{Claim, ClaimDecision, ClaimPayment, Role, Tenant, TenantMembership, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function r7User(Tenant $tenant, string $role, string $carrierId, string $locale = 'en'): User
{
    $u = User::factory()->create(['status' => 'ACTIVE', 'locale' => $locale]);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $u;
}

/** One tenant, two carriers: mine and theirs, each with a policy, claim, bordereau and settlement batch. */
function r7Book(): array
{
    $tenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'R7 '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $mine = makeMobileFinanceProposalChain($tenant);
    $theirs = makeMobileFinanceProposalChain($tenant);
    $prep = User::factory()->create(['status' => 'ACTIVE']);
    $p = makeMobileTestPolicy($mine['proposal'], $tenant, $mine['carrier']->id, $mine['party']->id, ['policy_number' => 'POL-R7-MINE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addDays(20), 'premium_minor' => 50000, 'currency' => 'XAF']);
    $t = makeMobileTestPolicy($theirs['proposal'], $tenant, $theirs['carrier']->id, $theirs['party']->id, ['policy_number' => 'POL-R7-THEIRS', 'coverage_ends_at' => now()->addDays(20), 'premium_minor' => 50000, 'currency' => 'XAF']);
    makeMobileTestClaim($tenant, $p, $mine['party'], ['claim_number' => 'CLM-R7-MINE']);
    makeMobileTestClaim($tenant, $t, $theirs['party'], ['claim_number' => 'CLM-R7-THEIRS']);
    makeMobileTestBordereau($tenant, $mine['carrier']->id, $prep, ['bordereau_number' => 'BOR-R7-MINE', 'status' => 'SUBMITTED']);
    makeMobileTestBordereau($tenant, $theirs['carrier']->id, $prep, ['bordereau_number' => 'BOR-R7-THEIRS', 'status' => 'SUBMITTED']);
    makeMobileTestSettlementBatch($tenant, $mine['carrier']->id, $prep, ['settlement_number' => 'SET-R7-MINE']);
    makeMobileTestSettlementBatch($tenant, $theirs['carrier']->id, $prep, ['settlement_number' => 'SET-R7-THEIRS']);

    return compact('tenant', 'mine', 'theirs', 'p', 't');
}

/** @return list<string> sidebar hrefs of /insurer */
function r7Nav(string $html): array
{
    preg_match_all('#<a\b[^>]*fi-sidebar-item-btn[^>]*href="(?:https?://[^/"]+)?(/insurer(?:/[^"?\#]*)?)"|<a\b[^>]*href="(?:https?://[^/"]+)?(/insurer(?:/[^"?\#]*)?)"[^>]*fi-sidebar-item-btn#', $html, $m, PREG_SET_ORDER);

    return array_values(array_unique(array_map(fn ($x) => $x[1] !== '' ? $x[1] : $x[2], $m)));
}

/** @return list<Action> flattened visible actions */
function r7Flatten(array $actions): array
{
    $out = [];
    foreach ($actions as $a) {
        if ($a instanceof ActionGroup) {
            $out = [...$out, ...r7Flatten($a->getActions())];
        } elseif ($a instanceof Action) {
            $out[] = $a;
        }
    }

    return $out;
}

const R7_THEIRS = ['POL-R7-THEIRS', 'CLM-R7-THEIRS', 'BOR-R7-THEIRS', 'SET-R7-THEIRS'];

dataset('r7_roles', ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER', 'SENIOR_UNDERWRITER', 'CLAIMS_OFFICER', 'CLAIMS_MANAGER',
    'ADJUSTER', 'REINSURANCE_OFFICER', 'FINANCE_OFFICER', 'FINANCE_MANAGER', 'CUSTOMER_SERVICE']);

it('opens every nav page and runs every visible action for the role, never a 500 and never another carrier\'s data', function (string $role) {
    if (($only = getenv('R7_ONLY')) && ! in_array($role, explode(',', $only), true)) {
        $this->markTestSkipped('R7_ONLY');
    }
    $b = r7Book();
    $user = r7User($b['tenant'], $role, $b['mine']['carrier']->id);
    $home = $this->actingAs($user)->get('/insurer');
    if ($role === 'FINANCE_MANAGER') {
        // Not an insurer-portal role (PortalAccess): a carrier-linked finance manager works in /admin; clean 403 here.
        expect($home->status())->toBe(403);

        return;
    }
    expect($home->status())->toBe(200);
    $nav = r7Nav($home->getContent());
    expect($nav)->not->toBeEmpty();

    $failures = [];
    $rows = [];
    $leaks = fn (string $html, string $where) => array_map(fn ($x) => "{$where} shows {$x}", array_values(array_filter(R7_THEIRS, fn ($x) => str_contains($html, $x))));
    foreach ($nav as $href) {
        $res = $this->get($href);
        if ($res->status() !== 200) {
            $failures[] = "{$href} → {$res->status()}";
            continue;
        }
        $failures = [...$failures, ...$leaks($res->getContent(), $href)];
        if (preg_match('#href="(?:https?://[^/"]+)?('.preg_quote($href, '#').'/[0-9a-f-]{36}(?:/[a-z-]+)?)"#', $res->getContent(), $row)) {
            $r = $this->get($row[1]);
            if ($r->status() >= 500 || $r->status() === 404) {
                $failures[] = "{$row[1]} → {$r->status()}";
            } else {
                $failures = [...$failures, ...$leaks($r->getContent(), $row[1])];
                if ($r->status() === 200) {
                    $rows[] = $row[1];
                }
            }
        }
    }

    // Actions: every page reached from the nav, mounted and run with empty data (validation / refusal expected).
    $this->actingAs($user);
    app(TenantContext::class)->set($b['tenant']->id);
    Filament::setCurrentPanel(Filament::getPanel('insurer'));
    // Record pages (first row of each list) too: their header actions (claim / policy / bordereau actions ...).
    foreach ([...$nav, ...array_unique($rows)] as $href) {
        $route = rescue(fn () => Route::getRoutes()->match(Request::create($href)), null, false);
        $class = $route?->getControllerClass();
        $params = $route ? array_intersect_key($route->parameters(), array_flip(['record'])) : [];
        if ($class === null || ! is_subclass_of($class, \Livewire\Component::class) || count($route->parameterNames()) !== count($params)) {
            continue;
        }
        try {
            $page = Livewire::test($class, $params);
        } catch (\Throwable $e) {
            $failures[] = "{$href} mount: ".Str::limit($e->getMessage(), 200);
            continue;
        }
        $instance = $page->instance();
        $targets = [];
        if (method_exists($instance, 'getCachedHeaderActions')) {
            foreach (r7Flatten($instance->getCachedHeaderActions()) as $a) {
                if (rescue(fn () => $a->isVisible(), true, false) && ! $a->getUrl()) {
                    $targets[] = [$a->getName(), fn () => $a->getName()];
                }
            }
        }
        if (method_exists($instance, 'getTable')) {
            $table = $instance->getTable();
            foreach (r7Flatten([...$table->getHeaderActions(), ...$table->getToolbarActions()]) as $a) {
                if (rescue(fn () => $a->isVisible(), true, false) && ! $a->getUrl() && ! $a->isBulk()) {
                    $n = $a->getName();
                    $targets[] = [$n, fn () => TestAction::make($n)->table()];
                }
            }
            $record = rescue(fn () => $instance->getTableRecords()->first(), null, false);
            if ($record !== null) {
                $key = $instance->getTableRecordKey($record);
                foreach (r7Flatten($table->getRecordActions()) as $a) {
                    if (! rescue(fn () => $a->getUrl(), null, false)) { // visibility is decided by Filament when the action is mounted on the row
                        $n = $a->getName();
                        $targets[] = [$n, fn () => TestAction::make($n)->table($key)];
                    }
                }
            }
        }
        foreach ($targets as [$name, $make]) {
            try {
                \Illuminate\Support\Facades\DB::beginTransaction();
                Livewire::test($class, $params)->mountAction($make())->callMountedAction();
            } catch (\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
                // meaningful refusal
            } catch (\Throwable $e) {
                $failures[] = "{$href} action {$name}: ".get_class($e).' '.Str::limit($e->getMessage(), 220);
            } finally {
                \Illuminate\Support\Facades\DB::rollBack();
            }
        }
    }

    if ($dump = getenv('R7_DUMP')) {
        @mkdir($dump, 0777, true);
        file_put_contents("{$dump}/{$role}.json", json_encode(['nav' => $nav, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    expect($failures)->toBe([], "{$role}: ".implode("\n", $failures));
})->with('r7_roles');

/** Asserts the action notified $key; otherwise fails with the notifications actually sent and the form errors. */
function r7Notified(mixed $lw, string $key): void
{
    $c = new \Filament\Notifications\Livewire\Notifications;
    $c->mount();
    $sent = collect($c->notifications)->map(fn ($n) => $n->getTitle().' | '.strip_tags((string) $n->getBody()));
    expect($sent->contains(fn ($t) => str_starts_with($t, __($key).' |')))->toBeTrue("expected '".__($key)."', got: ".$sent->implode(' // ').' errors: '.json_encode($lw->errors()->toArray()));
}

function r7As(User $u, Tenant $t): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($t->id);
    Filament::setCurrentPanel(Filament::getPanel('insurer'));
}

it('shows every insurer sidebar label and group in French for a French user', function () {
    $b = r7Book();
    foreach (['CARRIER_SUPER_ADMIN', 'CLAIMS_MANAGER'] as $role) {
        $labels = [];
        foreach (['en', 'fr'] as $locale) {
            $this->flushSession();
            app()->forgetInstance(\Filament\Navigation\NavigationManager::class);
            $html = $this->actingAs(r7User($b['tenant'], $role, $b['mine']['carrier']->id, $locale))->get('/insurer')->assertOk()->getContent();
            preg_match_all('#data-group-label="([^"]+)"#', $html, $g);
            preg_match_all('#<span[^>]*fi-sidebar-item-label[^>]*>\s*(.*?)\s*</span>#s', $html, $l);
            $labels[$locale] = array_values(array_unique(array_map(fn ($x) => html_entity_decode(trim(strip_tags($x))), [...$g[1], ...$l[1]])));
        }
        // Words spelled the same in both languages.
        $same = ['Documents', 'Distribution', 'Administration', 'Production', 'Organisation', 'KYC', 'Bordereaux', 'Coassurance', 'Journal', 'Réassurance', 'Assurance'];
        $untranslated = array_values(array_diff(array_intersect($labels['en'], $labels['fr']), $same));
        expect($untranslated)->toBe([], "{$role}: untranslated in FR: ".implode(', ', $untranslated));
    }
});

it('runs the claim journey in /insurer with the catalogue roles: FNOL, assignment, expert, inspection, report, recommendation, decision, reserve, settlement, payment with four eyes', function () {
    $b = r7Book();
    [$tenant, $carrier] = [$b['tenant'], $b['mine']['carrier']->id];
    app()->bind(\App\Application\Documents\Adapters\MalwareScanAdapter::class, fn () => new class implements \App\Application\Documents\Adapters\MalwareScanAdapter
    {
        public function scan(string $absolutePath, string $declaredMimeType): \App\Application\Documents\Adapters\ScanResult
        {
            return \App\Application\Documents\Adapters\ScanResult::clean();
        }
    });
    $officer = r7User($tenant, 'CLAIMS_OFFICER', $carrier);
    $manager = r7User($tenant, 'CLAIMS_MANAGER', $carrier);
    $manager2 = r7User($tenant, 'CLAIMS_MANAGER', $carrier);
    $foreignStaff = r7User($tenant, 'CLAIMS_OFFICER', $b['theirs']['carrier']->id);
    foreach ([$officer, $manager, $manager2] as $u) {
        DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $carrier, 'holder_type' => 'USER', 'holder_id' => $u->id,
            'authority_type' => 'CLAIM_SETTLE', 'max_amount_minor' => 1000000, 'currency' => 'XAF', 'effective_from' => now()->subMonth()->toDateString(), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }
    // Expert panel: an ACTIVE motor expert in a network, with a contract and an approved fee tariff.
    $reg = app(\App\Application\Providers\ProviderRegistry::class);
    $expert = $reg->register(['category' => 'ADJUSTER', 'name' => 'Cabinet R7 Expertise', 'provider_type_code' => 'MOTOR_EXPERT'], null);
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        $reg->transition($expert->id, $to, null, null, null);
    }
    $net = app(\App\Application\Providers\ProviderNetworkService::class);
    $network = $net->createNetwork($tenant->id, ['code' => 'R7_ADJ', 'name' => 'R7 adjusters', 'network_type_code' => 'PANEL', 'category' => 'ADJUSTER'], null);
    $net->addMember($tenant->id, $network->id, ['provider_id' => $expert->id, 'effective_from' => now()->subMonth()->toDateString()], null);
    $service = $net->addMedicalService(['code' => 'EXP_R7_INSPECTION', 'name' => 'Motor inspection', 'category_code' => 'EXPERTISE']);
    $contract = $net->createContract($tenant->id, $network->id, ['provider_id' => $expert->id, 'contract_number' => 'R7-ADJ-1', 'effective_from' => now()->subMonth()->toDateString()], null);
    $tariff = $net->draftTariff($tenant->id, $contract->id, now()->subMonth()->toDateString(), 'XAF',
        [['medical_service_id' => $service->id, 'price_minor' => 60000, 'contracted_price_minor' => 50000, 'insurer_share_percent' => 100]], $officer->id);
    $net->approveTariff($tenant->id, $tariff->id, $manager->id);
    $adjuster = r7User($tenant, 'ADJUSTER', $carrier);
    $adjuster->update(['party_id' => $reg->find($expert->id)->party_id]);

    // FNOL (claims officer): own carrier's policy only.
    r7As($officer, $tenant);
    Livewire::test(ListClaims::class)->callAction('claimRegister', [
        'policy_id' => $b['t']->id, 'claimant_party_id' => $b['t']->party_id, 'loss_occurred_at' => now()->subDay()->toDateTimeString(),
        'description' => 'Foreign policy', 'priority' => 'NORMAL', 'channel' => 'BACK_OFFICE',
    ]);
    expect(Claim::where('policy_id', $b['t']->id)->count())->toBe(1); // only the seeded one
    r7Notified(Livewire::test(ListClaims::class)->callAction('claimRegister', [
        'policy_id' => $b['p']->id, 'claimant_party_id' => $b['p']->party_id, 'loss_occurred_at' => now()->subDay()->toDateTimeString(),
        'description' => 'Rear-end collision at Akwa junction', 'estimated_loss_minor' => 300000, 'priority' => 'NORMAL', 'channel' => 'BACK_OFFICE',
    ]), 'workflow_actions.claimRegister.done');
    $claim = Claim::where('policy_id', $b['p']->id)->where('claim_number', '!=', 'CLM-R7-MINE')->sole();
    $view = fn () => Livewire::test(ViewClaim::class, ['record' => $claim->id]);

    // Handler assignment: own carrier's staff only (another insurer's claims officer is not assignable).
    $view()->callAction('claimAssign', ['assignee_id' => $foreignStaff->id, 'reason_code' => 'WORKLOAD']);
    expect(DB::table('claim_assignments')->where('claim_id', $claim->id)->where('assignee_id', $foreignStaff->id)->exists())->toBeFalse();
    $view()->callAction('claimAssign', ['assignee_id' => $officer->id, 'reason_code' => 'WORKLOAD'])->assertHasNoActionErrors();

    // Expert appointment, then the adjuster works the file in the workbench.
    $claim->refresh()->update(['status' => 'ASSESSMENT']);
    $view()->callAction('expertAssign', ['provider_id' => $expert->id, 'network_id' => $network->id, 'fee_service_id' => $service->id, 'instructions' => 'Inspect at the garage.'])
        ->assertHasNoActionErrors();
    $assignment = DB::table('claim_assignments')->where(['claim_id' => $claim->id, 'assignment_type' => 'EXPERT'])->sole();
    expect($assignment->status)->toBe('ASSIGNMENT_PENDING')->and((int) $assignment->fee_amount_minor)->toBe(50000);

    r7As($adjuster, $tenant);
    $wb = fn () => Livewire::test(AssignmentWorkbench::class, ['assignment' => $assignment->id]);
    r7Notified($wb()->callAction('wbAccept'), 'claims_workbench.actions.wbAccept.done');
    r7Notified($wb()->callAction('wbSchedule', ['scheduled_for' => now()->addDay()->toDateTimeString(), 'location' => 'Garage Bonapriso']), 'claims_workbench.actions.wbSchedule.done');
    $png = UploadedFile::fake()->createWithContent('front.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    r7Notified($wb()->callAction('wbFieldCapture', ['inspected_at' => now()->subMinute()->toDateTimeString(), 'condition' => 'REPAIRABLE', 'attendees' => 'Claimant',
        'observations' => 'Rear bumper crushed.', 'photos' => [$png]]), 'claims_workbench.actions.wbFieldCapture.done');
    $wb()->callAction('wbReport', ['circumstances' => 'Hit from behind at a red light.', 'cause' => 'Third-party rear impact',
        'damage_items' => [['item' => 'Rear bumper', 'severity' => 'SEVERE', 'treatment' => 'REPLACE', 'estimate_minor' => 250000]],
        'salvage_minor' => 0, 'conclusion' => 'Repairable; estimate consistent with the damage.'])->assertNotified(__('claims_workbench.actions.wbReport.done'));
    r7Notified($wb()->callAction('wbRecommend', ['heads' => [['head_code' => 'REPAIR', 'recommended_minor' => 250000, 'claimed_minor' => 300000]],
        'rationale' => 'Per the expert report, repair at the approved garage.']), 'claims_workbench.actions.wbRecommend.done');
    expect(DB::table('claim_assignments')->where('id', $assignment->id)->value('status'))->toBe('REPORT_SUBMITTED');

    // Checker: accept the expert report and the assessment.
    r7As($manager, $tenant);
    $view()->callAction('expertReview', ['assignment_id' => $assignment->id, 'outcome' => 'ACCEPT'])->assertHasNoActionErrors();
    expect(DB::table('claim_assignments')->where('id', $assignment->id)->value('status'))->toBe('REPORT_ACCEPTED');
    $asm = DB::table('claim_assessments')->where(['claim_id' => $claim->id, 'status' => 'SUBMITTED'])->sole();
    $view()->callAction('claimReviewAssessment', ['assessment_id' => $asm->id, 'outcome' => 'ACCEPT'])->assertHasNoActionErrors();
    expect(DB::table('claim_assessments')->where('id', $asm->id)->value('status'))->not->toBe('SUBMITTED');

    // Reserve (maker), approved by the checker when it needs approval.
    r7As($officer, $tenant);
    $view()->callAction('claimReserve', ['amount_minor' => 250000, 'reason_code' => 'INITIAL_ESTIMATE'])->assertHasNoActionErrors();
    $reserve = DB::table('claim_reserve_changes')->where('claim_id', $claim->id)->latest('created_at')->first();
    expect($reserve)->not->toBeNull();
    if ($reserve->status === 'PENDING_APPROVAL') {
        r7As($manager, $tenant);
        $view()->callAction('claimApproveReserve', ['reserve_id' => $reserve->id])->assertHasNoActionErrors();
        expect(DB::table('claim_reserve_changes')->where('id', $reserve->id)->value('status'))->toBeIn(['APPROVED', 'REFERRED']);
    }

    // Decision: the officer proposes, the manager approves.
    $claim->refresh()->update(['status' => 'CARRIER_REVIEW']);
    r7As($officer, $tenant);
    r7Notified($view()->callAction('claimDecide', ['decision' => 'PARTIAL', 'reason_codes' => ['DEDUCTIBLE_APPLIED'], 'heads' => [['head' => 'REPAIR', 'amount_minor' => 200000]],
        'rationale' => 'Assessed against the expert report and the policy wording.']), 'workflow_actions.claimDecide.done');
    $decision = ClaimDecision::where('claim_id', $claim->id)->sole();
    r7As($manager, $tenant);
    r7Notified($view()->callAction('claimApproveDecision', ['decision_id' => $decision->id, 'outcome' => 'APPROVE']), 'workflow_actions.claimApproveDecision.done');
    expect($decision->refresh()->status)->toBe('APPROVED');

    // Settlement: calculate, then offer.
    r7As($officer, $tenant);
    $view()->callAction('claimSettle', ['covered_minor' => 200000, 'deductible_minor' => 0])->assertHasNoActionErrors();
    $settlement = DB::table('claim_settlements')->where('claim_id', $claim->id)->first();
    expect($settlement)->not->toBeNull();
    if ($settlement->status === 'CALCULATED') {
        // Four eyes: the calculator may not offer its own settlement; a colleague does.
        r7Notified($view()->callAction('claimOfferSettlement', ['settlement_id' => $settlement->id]), 'workflow_actions.failed');
        r7As($manager, $tenant);
        r7Notified($view()->callAction('claimOfferSettlement', ['settlement_id' => $settlement->id]), 'workflow_actions.claimOfferSettlement.done');
        expect(DB::table('claim_settlements')->where('id', $settlement->id)->value('status'))->not->toBe('CALCULATED');
    }

    // Payment with four eyes: the manager who requests it cannot approve it; a second manager does.
    r7As($manager, $tenant);
    r7Notified($view()->callAction('claimRequestPayment', ['decision_id' => $decision->id, 'amount_minor' => 200000]), 'workflow_actions.claimRequestPayment.done');
    $payment = ClaimPayment::where('claim_id', $claim->id)->sole();
    r7Notified($view()->callAction('claimApprovePayment', ['payment_id' => $payment->id]), 'workflow_actions.failed');
    expect($payment->refresh()->status)->toBe('PENDING_APPROVAL');
    r7As($officer, $tenant); // maker role: no approve permission
    $view()->assertActionHidden(TestAction::make('claimApprovePayment'));
    r7As($manager2, $tenant);
    r7Notified($view()->callAction('claimApprovePayment', ['payment_id' => $payment->id]), 'workflow_actions.claimApprovePayment.done');
    expect($payment->refresh()->status)->toBe('APPROVED');

    // The other carrier's claim is never reachable.
    $theirs = Claim::where('claim_number', 'CLM-R7-THEIRS')->sole();
    $this->actingAs($manager2)->get('/insurer/claims/'.$theirs->id)->assertNotFound();
});

it('runs the cancellation review in /insurer: request, underwriter review, senior underwriter decision; the requester cannot decide its own', function () {
    $b = r7Book();
    [$tenant, $carrier] = [$b['tenant'], $b['mine']['carrier']->id];
    // The premium payment the cancellation refund is raised against.
    $b['p']->update(['payment_intent_id' => makeMobileTestPayment($b['mine']['proposal'], $tenant, ['amount_minor' => 50000, 'status' => 'SUCCEEDED'])->id]);
    $super = r7User($tenant, 'CARRIER_SUPER_ADMIN', $carrier);
    DB::table('cancellation_rule_versions')->insert(['id' => (string) Str::uuid(), 'line_code' => 'AUTO', 'version' => 1, 'status' => 'APPROVED', 'basis' => 'PRO_RATA',
        'short_rate_basis_points' => 10000, 'admin_fee_minor' => 0, 'effective_from' => now()->subYear()->toDateString(), 'effective_until' => null, 'created_by' => $super->id, 'created_at' => now(), 'updated_at' => now()]);
    r7As($super, $tenant);
    r7Notified(Livewire::test(ViewPolicy::class, ['record' => $b['p']->id])
        ->callAction('policyRequestCancellation', ['effective_at' => now()->addDays(2)->toDateTimeString(), 'initiated_by' => 'INSURED', 'reason_code' => 'SOLD_VEHICLE']), 'workflow_actions.policyRequestCancellation.done');
    $case = DB::table('policy_cancellations')->where('policy_id', $b['p']->id)->first();
    expect($case)->not->toBeNull();

    // The requester holds the approve permission but may not decide its own request.
    Livewire::test(CancellationReview::class)->assertCanSeeTableRecords([$b['p']])
        ->callAction(TestAction::make('policyDecideCancellation')->table($b['p']->id), ['outcome' => 'APPROVE'])->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('policy_cancellations')->where('id', $case->id)->value('status'))->toBeIn(['REQUESTED', 'UNDER_REVIEW']);

    r7As(r7User($tenant, 'UNDERWRITER', $carrier), $tenant);
    Livewire::test(CancellationReview::class)->assertCanSeeTableRecords([$b['p']])->assertCanNotSeeTableRecords([$b['t']])
        ->callAction(TestAction::make('policyReviewCancellation')->table($b['p']->id), ['note' => 'Sale deed on file.'])->assertHasNoActionErrors();
    expect(DB::table('policy_cancellations')->where('id', $case->id)->value('status'))->toBe('UNDER_REVIEW');

    r7As(r7User($tenant, 'SENIOR_UNDERWRITER', $carrier), $tenant);
    Livewire::test(CancellationReview::class)
        ->callAction(TestAction::make('policyDecideCancellation')->table($b['p']->id), ['outcome' => 'APPROVE', 'note' => 'Approved.'])->assertHasNoActionErrors();
    expect(DB::table('policy_cancellations')->where('id', $case->id)->value('status'))->not->toBeIn(['REQUESTED', 'UNDER_REVIEW']);
});

it('acknowledges its own submitted bordereau in /insurer and never another carrier\'s', function () {
    $b = r7Book();
    $admin = r7User($b['tenant'], 'CARRIER_ADMIN', $b['mine']['carrier']->id);
    $mine = \App\Models\Bordereau::where('bordereau_number', 'BOR-R7-MINE')->sole();
    $theirs = \App\Models\Bordereau::where('bordereau_number', 'BOR-R7-THEIRS')->sole();
    $this->actingAs($admin)->get('/insurer/bordereaux')->assertOk()->assertSee('BOR-R7-MINE')->assertDontSee('BOR-R7-THEIRS');
    expect($this->get('/insurer/bordereaux/'.$theirs->id)->status())->toBeIn([403, 404]);

    r7As($admin, $b['tenant']);
    Livewire::test(\App\Filament\Admin\Resources\Bordereaux\Pages\ViewBordereau::class, ['record' => $mine->id])
        ->callAction('bordereauAcknowledge', ['carrier_reference' => 'ACK-R7-1'])->assertNotified(__('finance_actions.bordereauAcknowledge.done'));
    expect($mine->refresh()->status)->not->toBe('SUBMITTED')->and($theirs->refresh()->status)->toBe('SUBMITTED');
});

it('lists only its own carrier\'s staff and invitations in /insurer', function () {
    $b = r7Book();
    $admin = r7User($b['tenant'], 'CARRIER_SUPER_ADMIN', $b['mine']['carrier']->id);
    $colleague = r7User($b['tenant'], 'UNDERWRITER', $b['mine']['carrier']->id);
    $foreign = r7User($b['tenant'], 'UNDERWRITER', $b['theirs']['carrier']->id);
    \App\Models\TenantInvitation::create(['tenant_id' => $b['tenant']->id, 'carrier_id' => $b['theirs']['carrier']->id, 'recipient_email' => 'foreign.r7@other.test', 'role_code' => 'CARRIER_STAFF',
        'token_hash' => hash('sha256', 'r7'), 'status' => 'PENDING', 'expires_at' => now()->addDay(), 'invited_by' => $foreign->id]);
    $this->actingAs($admin)->get('/insurer/memberships')->assertOk()->assertSee($colleague->full_name)->assertDontSee($foreign->full_name);
    $this->get('/insurer/invitations')->assertOk()->assertDontSee('foreign.r7@other.test');
});
