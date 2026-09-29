<?php

declare(strict_types=1);

use App\Application\Claims\Adjusters\AdjusterWorkbench;
use App\Application\Documents\Adapters\MalwareScanAdapter;
use App\Application\Documents\Adapters\ScanResult;
use App\Application\Providers\ProviderRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Pages\ClaimsWorkbench\AssignmentWorkbench;
use App\Filament\Shared\Pages\ClaimsWorkbench\ClaimsWorkbenchDashboard;
use App\Filament\Shared\Pages\ClaimsWorkbench\CompletedAssignments;
use App\Filament\Shared\Pages\ClaimsWorkbench\MyAssignments;
use App\Models\Claim;
use App\Models\Policy;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Q9 claims professional / adjuster workbench in /insurer (CLP-001, 004, 006-018): the adjuster works only their own
 * expert assignments on their own carrier's claims, with the same permissions and services as the adjuster API.
 */
uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

const CWB_ADJUSTER = ['claims.view', 'claims.evidence.manage', 'documents.carrier.upload', 'claims.experts.work', 'claims.assessment.record'];

function cwbUser(string $tenantId, array $permissions, ?string $carrierId, ?string $partyId = null, string $roleCode = 'ADJUSTER'): User
{
    $u = User::create(['full_name' => 'CWB '.$roleCode.' '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE', 'party_id' => $partyId]);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $roleCode, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => $roleCode.'-'.Str::random(6), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function cwbPolicy(string $tenantId, array $chain): Policy
{
    return Policy::create([
        'tenant_id' => $tenantId, 'proposal_id' => $chain['proposal']->id, 'carrier_id' => $chain['carrier']->id, 'party_id' => $chain['party']->id,
        'policy_number' => 'POL-CWB-'.Str::upper(Str::random(6)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => ['coverages' => [['code' => 'RC', 'name' => 'Third-party liability', 'limit_minor' => 5000000]]], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subMonth(),
    ]);
}

function cwbClaim(string $tenantId, Policy $policy, string $status = 'ASSESSMENT'): Claim
{
    return Claim::create(['tenant_id' => $tenantId, 'policy_id' => $policy->id, 'claimant_party_id' => $policy->party_id, 'claim_number' => 'CLM-CWB-'.Str::upper(Str::random(6)),
        'status' => $status, 'loss_occurred_at' => now()->subDays(2), 'loss_location' => 'Akwa, Douala', 'currency' => 'XAF',
        'loss_details' => ['description' => 'Rear-end collision at a junction', 'incident' => ['incident_type' => 'COLLISION', 'police_report_number' => 'PV-123', 'vehicle_drivable' => false]]]);
}

function cwbAssign(string $tenantId, Claim $claim, string $providerId, string $status = 'ASSIGNMENT_PENDING', array $extra = []): string
{
    $id = (string) Str::uuid();
    $by = User::create(['full_name' => 'CWB assigner', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    DB::table('claim_assignments')->insert($extra + [
        'id' => $id, 'claim_id' => $claim->id, 'assignee_id' => null, 'assigned_by' => $by->id, 'reason_code' => 'EXPERT_APPOINTMENT', 'assigned_at' => now()->subDays(3),
        'assignment_type' => 'EXPERT', 'tenant_id' => $tenantId, 'provider_profile_id' => $providerId, 'provider_network_id' => test()->network->id, 'status' => $status, 'fee_amount_minor' => 50000, 'fee_currency' => 'XAF',
        'instructions' => 'Inspect the vehicle at the garage.', 'version' => 1, 'updated_at' => now(),
    ]);

    return $id;
}

function cwbAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('insurer'));
}

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    $this->carrier = $this->f['carrier']->id;
    $this->policy = cwbPolicy($this->tenant, $this->f);
    $this->claim = cwbClaim($this->tenant, $this->policy);
    $this->otherChain = makeMobileFinanceProposalChain($this->f['tenant']);
    $this->otherClaim = cwbClaim($this->tenant, cwbPolicy($this->tenant, $this->otherChain));

    $reg = app(ProviderRegistry::class);
    $this->expert = $reg->register(['category' => 'ADJUSTER', 'name' => 'Cabinet Expertise Douala', 'provider_type_code' => 'MOTOR_EXPERT'], null);
    $this->rival = $reg->register(['category' => 'ADJUSTER', 'name' => 'Cabinet Rival', 'provider_type_code' => 'MOTOR_EXPERT'], null);
    $this->network = app(\App\Application\Providers\ProviderNetworkService::class)->createNetwork($this->tenant, ['code' => 'ADJ_PANEL', 'name' => 'Adjuster panel', 'network_type_code' => 'PANEL', 'category' => 'ADJUSTER'], null);
    $this->adjuster = cwbUser($this->tenant, CWB_ADJUSTER, $this->carrier, $this->expert->party_id);
    $this->mine = cwbAssign($this->tenant, $this->claim, $this->expert->id);
    $this->theirs = cwbAssign($this->tenant, $this->claim, $this->rival->id);
    $this->foreign = cwbAssign($this->tenant, $this->otherClaim, $this->expert->id);
    $this->done = cwbAssign($this->tenant, $this->claim, $this->expert->id, 'REPORT_ACCEPTED',
        ['accepted_at' => now()->subDays(3), 'report_submitted_at' => now()->subDays(2), 'reviewed_at' => now()->subDay(), 'assessed_loss_minor' => 100000]);

    app()->bind(MalwareScanAdapter::class, fn () => new class implements MalwareScanAdapter
    {
        public function scan(string $absolutePath, string $declaredMimeType): ScanResult
        {
            return ScanResult::clean();
        }
    });
});

it('shows the adjuster dashboard, open and completed assignments: own assignments on own carrier claims only (CLP-001, 017, 018)', function () {
    cwbAs($this->adjuster, $this->tenant);
    expect(ClaimsWorkbenchDashboard::canAccess())->toBeTrue()->and(MyAssignments::canAccess())->toBeTrue();

    $open = app(AdjusterWorkbench::class)->assignments($this->tenant, $this->adjuster, AdjusterWorkbench::OPEN);
    expect(array_map(fn ($r) => $r->id, $open))->toBe([$this->mine]);

    Livewire::test(ClaimsWorkbenchDashboard::class)->assertOk()
        ->assertSee(__('claims_workbench.dashboard.pipeline'))->assertSee(__('claims_workbench.performance.title'))->assertSee($this->claim->claim_number);
    Livewire::test(MyAssignments::class)->assertOk()->assertCanSeeTableRecords([$this->mine])->assertCanNotSeeTableRecords([$this->theirs, $this->foreign, $this->done]);
    Livewire::test(CompletedAssignments::class)->assertOk()->assertCanSeeTableRecords([$this->done])->assertCanNotSeeTableRecords([$this->mine]);

    $perf = app(AdjusterWorkbench::class)->performance($this->tenant, $this->adjuster);
    expect($perf['total'])->toBe(2)->and($perf['completed'])->toBe(1)->and($perf['first_time_right_pct'])->toBe(100);
});

it('runs the assignment file end to end: accept, schedule, field capture with photos, evidence, report, recommendation (CLP-004…016)', function () {
    cwbAs($this->adjuster, $this->tenant);
    $page = fn () => Livewire::test(AssignmentWorkbench::class, ['assignment' => $this->mine]);

    $page()->assertOk()->assertSee($this->claim->claim_number)->assertSee('PV-123')->assertSee('Third-party liability')
        ->assertActionVisible('wbAccept')->assertActionHidden('wbReport')
        ->callAction('wbAccept')->assertNotified(__('claims_workbench.actions.wbAccept.done'));
    expect(DB::table('claim_assignments')->where('id', $this->mine)->value('status'))->toBe('ACCEPTED');

    $page()->callAction('wbSchedule', ['scheduled_for' => now()->addDay()->toDateTimeString(), 'location' => 'Garage Bonapriso'])
        ->assertNotified(__('claims_workbench.actions.wbSchedule.done'));
    expect(DB::table('claim_assignments')->where('id', $this->mine)->value('inspection_location'))->toBe('Garage Bonapriso');

    $png = UploadedFile::fake()->createWithContent('front.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $page()->callAction('wbFieldCapture', ['inspected_at' => now()->subMinute()->toDateTimeString(), 'condition' => 'REPAIRABLE', 'attendees' => 'Claimant, garage owner',
        'observations' => 'Rear bumper and boot lid crushed; lights broken.', 'photos' => [$png]])
        ->assertNotified(__('claims_workbench.actions.wbFieldCapture.done'));
    $a = DB::table('claim_assignments')->where('id', $this->mine)->first();
    expect($a->status)->toBe('INSPECTED')->and($a->inspection_notes)->toContain('Rear bumper');
    expect(DB::table('claim_documents')->where(['claim_id' => $this->claim->id, 'evidence_type' => 'INSPECTION_PHOTO'])->count())->toBe(1);

    $pdf = UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    $page()->callAction('wbUploadEvidence', ['evidence_type' => 'REPAIR_QUOTE', 'files' => [$pdf]])->assertNotified(__('claims_workbench.actions.wbUploadEvidence.done'));
    expect(DB::table('claim_documents')->where(['claim_id' => $this->claim->id, 'evidence_type' => 'REPAIR_QUOTE'])->count())->toBe(1);

    $page()->callAction('wbReport', [
        'circumstances' => 'Vehicle hit from behind while stopped at a red light.', 'cause' => 'Third-party rear impact',
        'damage_items' => [['item' => 'Rear bumper', 'severity' => 'SEVERE', 'treatment' => 'REPLACE', 'estimate_minor' => 180000],
            ['item' => 'Boot lid', 'severity' => 'MODERATE', 'treatment' => 'REPAIR', 'estimate_minor' => 90000]],
        'salvage_minor' => 20000, 'conclusion' => 'Repairable; the estimate is consistent with the damage observed.',
    ])->assertNotified(__('claims_workbench.actions.wbReport.done'));
    $a = DB::table('claim_assignments')->where('id', $this->mine)->first();
    expect($a->status)->toBe('REPORT_SUBMITTED')->and((int) $a->assessed_loss_minor)->toBe(250000)->and($a->report_summary)->toContain('Rear bumper');

    $page()->callAction('wbRecommend', ['heads' => [['head_code' => 'REPAIR', 'recommended_minor' => 250000, 'claimed_minor' => 300000]],
        'rationale' => 'Per the expert report, repair at the approved garage.'])->assertNotified(__('claims_workbench.actions.wbRecommend.done'));
    $asm = DB::table('claim_assessments')->where('claim_id', $this->claim->id)->sole();
    expect($asm->status)->toBe('SUBMITTED')->and((int) $asm->recommended_total_minor)->toBe(250000)->and($asm->assessor_user_id)->toBe($this->adjuster->id);
    // A recommendation never decides: the claim status is unchanged.
    expect($this->claim->refresh()->status)->toBe('ASSESSMENT');
});

it('refuses another expert\'s assignment, another carrier\'s claim and users without the permission', function () {
    cwbAs($this->adjuster, $this->tenant);
    Livewire::test(AssignmentWorkbench::class, ['assignment' => $this->theirs])->assertNotFound();
    Livewire::test(AssignmentWorkbench::class, ['assignment' => $this->foreign])->assertNotFound();

    // Without claims.assessment.record the recommendation is not offered; without claims.evidence.manage no upload.
    $narrow = cwbUser($this->tenant, ['claims.view', 'claims.experts.work'], $this->carrier, $this->expert->party_id);
    DB::table('claim_assignments')->where('id', $this->mine)->update(['status' => 'INSPECTED']);
    cwbAs($narrow, $this->tenant);
    Livewire::test(AssignmentWorkbench::class, ['assignment' => $this->mine])->assertOk()
        ->assertActionHidden('wbRecommend')->assertActionHidden('wbUploadEvidence')->assertActionVisible('wbReport');

    // A claims handler without claims.experts.work sees the dashboard (their claims) but not the assignment pages.
    $handler = cwbUser($this->tenant, ['claims.view'], $this->carrier, null, 'CLAIMS_OFFICER');
    $this->claim->update(['assigned_to' => $handler->id]);
    $this->otherClaim->update(['assigned_to' => $handler->id]);
    cwbAs($handler, $this->tenant);
    expect(MyAssignments::canAccess())->toBeFalse()->and(AssignmentWorkbench::canAccess())->toBeFalse()->and(ClaimsWorkbenchDashboard::canAccess())->toBeTrue();
    Livewire::test(ClaimsWorkbenchDashboard::class)->assertOk()->assertSee($this->claim->claim_number)->assertDontSee($this->otherClaim->claim_number)
        ->assertDontSee(__('claims_workbench.performance.title'));

    $nobody = cwbUser($this->tenant, [], $this->carrier, null, 'CARRIER_STAFF');
    cwbAs($nobody, $this->tenant);
    expect(ClaimsWorkbenchDashboard::canAccess())->toBeFalse();
});

it('keeps an evidence upload made while the scanner is down and attaches it once the rescan is clean (Q1 queue)', function () {
    app()->bind(MalwareScanAdapter::class, fn () => new \App\Application\Documents\Adapters\FailClosedMalwareScanAdapter);
    DB::table('claim_assignments')->where('id', $this->mine)->update(['status' => 'ACCEPTED']);
    cwbAs($this->adjuster, $this->tenant);

    $pdf = UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    Livewire::test(AssignmentWorkbench::class, ['assignment' => $this->mine])
        ->callAction('wbUploadEvidence', ['evidence_type' => 'REPAIR_QUOTE', 'files' => [$pdf]])
        ->assertNotified(__('scan_queue.client.in_progress'));

    $pending = DB::table('document_pending_attachments')->where(['target_type' => 'CLAIM_EVIDENCE', 'target_id' => $this->claim->id])->sole();
    expect($pending->status)->toBe('PENDING')
        ->and(DB::table('claim_documents')->where('claim_id', $this->claim->id)->count())->toBe(0);

    // Scanner back: the rescan finds the file clean and performs the recorded attachment.
    app()->bind(MalwareScanAdapter::class, fn () => new class implements MalwareScanAdapter
    {
        public function scan(string $absolutePath, string $declaredMimeType): ScanResult
        {
            return ScanResult::clean();
        }
    });
    app(\App\Application\Documents\Scanning\DocumentScanQueue::class)->rescanNow($pending->document_id);

    expect(DB::table('document_pending_attachments')->where('id', $pending->id)->value('status'))->toBe('ATTACHED')
        ->and(DB::table('claim_documents')->where(['claim_id' => $this->claim->id, 'document_id' => $pending->document_id, 'evidence_type' => 'REPAIR_QUOTE'])->count())->toBe(1);
});

it('composes the report and never lets salvage push the assessed loss below zero (CLP-012/013/014)', function () {
    [$text, $assessed] = \App\Filament\Shared\Actions\ClaimsWorkbenchActions::composeReport([
        'circumstances' => 'x', 'cause' => 'y', 'conclusion' => 'z', 'salvage_minor' => 999999,
        'damage_items' => [['item' => 'Door', 'severity' => 'MINOR', 'treatment' => 'REPAIR', 'estimate_minor' => 1000]],
    ]);
    expect($assessed)->toBe(0)->and($text)->toContain('Door');
});
