<?php

declare(strict_types=1);

// Agent C6 — REQ-CLM-005 claim evidence rules, metadata, checklist, WF-051/052 review, DECISION_PENDING guard.

use App\Application\Claims\ClaimEvidenceService;
use App\Application\Claims\Evidence\ClaimEvidenceChecklist;
use App\Application\Claims\Evidence\ClaimEvidenceGate;
use App\Application\Claims\Evidence\ClaimEvidenceReviewService;
use App\Application\Claims\Evidence\ClaimEvidenceRules;
use App\Application\Rules\RuleSetService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

beforeEach(function () {
    $this->seed(\Database\Seeders\DocumentCatalogueSeeder::class);
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['terms_snapshot' => ['line_code' => 'AUTO']]);
    $this->f = $f;
    $this->claim = makeMobileTestClaim($f['tenant'], $policy, $f['party'], ['loss_details' => ['description' => 'Stolen car', 'cause' => 'THEFT']]);
    $this->submitter = makeAuthTestUser($f['tenant'], ['claims.evidence.manage'], 'C6_SUB');
    $this->reviewer = makeAuthTestUser($f['tenant'], ['claims.view', 'claims.evidence.verify'], 'C6_REV');
    app(TenantContext::class)->set($f['tenant']->id);
});

function c6Doc(array $o = []): Document
{
    return makeMobileTestDocument(test()->f['tenant'], test()->f['party'], $o + ['uploaded_by' => test()->submitter->id]);
}

function c6Attach(string $typeId, array $o = []): Document
{
    $d = c6Doc(['document_type_id' => $typeId] + $o);
    app(ClaimEvidenceService::class)->attach(test()->claim, $d, $typeId, 'CLAIM_EVIDENCE', test()->submitter);

    return $d;
}

function c6Mandatory(): array
{
    return array_values(array_map(fn ($r) => $r['document_type_id'], array_filter(app(ClaimEvidenceRules::class)->forClaim(test()->claim)['rules'], fn ($r) => $r['mandatory'])));
}

it('derives the evidence rules for the claim type from the catalogue CLAIM pack of the policy class', function () {
    $spec = app(ClaimEvidenceRules::class)->forClaim($this->claim);
    expect($spec['class_code'])->toBe('MOTOR')->and($spec['source'])->toBe('PACK');
    $byId = collect($spec['rules'])->keyBy('document_type_id');
    expect($byId['CLM-01']['mandatory'])->toBeTrue()
        ->and($byId['CLM-01']['sources'])->toContain('PACK:MOTOR_CLAIM_PACK')
        ->and($byId['CLM-05']['mandatory'])->toBeFalse()
        ->and($byId['EVD-001']['third_party'])->toBeTrue();
});

it('falls back to the universal claim pack (nothing mandatory) for an unknown class', function () {
    $this->claim->policy->update(['terms_snapshot' => ['line_code' => 'UNKNOWN_LINE']]);
    $spec = app(ClaimEvidenceRules::class)->forClaim($this->claim->refresh());
    expect($spec['rules'])->not->toBeEmpty()->and(collect($spec['rules'])->where('mandatory', true)->all())->toBe([]);
});

it('applies DOCUMENTS rules from the rules engine: REQUIRE makes mandatory, WAIVE waives', function () {
    $this->claim->policy->update(['terms_snapshot' => ['line_code' => 'MOTOR']]);
    $set = app(RuleSetService::class)->createDraft(['code' => 'CLAIM_DOCS_'.Str::upper(Str::random(4)), 'domain' => 'DOCUMENTS', 'line_code' => 'MOTOR', 'effective_from' => '2026-01-01', 'rules' => [
        ['code' => 'THEFT_NEEDS_EVD018', 'condition' => ['op' => 'EQUAL', 'left' => ['fact' => 'cause'], 'right' => ['value' => 'THEFT']], 'outcome' => ['result' => 'REQUIRE', 'document_codes' => ['EVD-018']]],
        ['code' => 'THEFT_WAIVES_CLM21', 'condition' => ['op' => 'EQUAL', 'left' => ['fact' => 'cause'], 'right' => ['value' => 'THEFT']], 'outcome' => ['result' => 'WAIVE', 'document_codes' => ['CLM-21']]],
    ]], makeAuthTestUser($this->f['tenant'], ['rules.manage'], 'C6_RULES'));
    $set->update(['status' => 'APPROVED']);

    $items = collect(app(ClaimEvidenceChecklist::class)->build($this->claim->refresh())['items'])->keyBy('document_type_id');
    expect($items['EVD-018']['mandatory'])->toBeTrue()->and($items['EVD-018']['required_by_rule'])->toBeTrue()
        ->and($items['CLM-21']['status'])->toBe('WAIVED')->and($items['CLM-21']['mandatory'])->toBeFalse();
});

it('builds a checklist with MISSING / SUBMITTED / ACCEPTED / REJECTED and links evidence without copying documents', function () {
    $docs = DB::table('documents')->count();
    $a = c6Attach('CLM-01');
    $b = c6Attach('CLM-02');
    $svc = app(ClaimEvidenceReviewService::class);
    $svc->review($this->claim, $a, 'ACCEPT', null, null, $this->reviewer);
    $svc->review($this->claim, $b, 'REJECT', 'ILLEGIBLE', 'Photo blurred', $this->reviewer);
    c6Attach('ZZ-UNLISTED');

    $c = app(ClaimEvidenceChecklist::class)->build($this->claim);
    $items = collect($c['items'])->keyBy('document_type_id');
    expect($items['CLM-01']['status'])->toBe('ACCEPTED')
        ->and($items['CLM-02']['status'])->toBe('REJECTED')
        ->and($items['CLM-02']['evidence'][0]['review_reason_code'])->toBe('ILLEGIBLE')
        ->and($items['CLM-02']['evidence'][0]['review_notes'])->toBe('Photo blurred')
        ->and($items['CLM-04']['status'])->toBe('MISSING')
        ->and($c['complete'])->toBeFalse()
        ->and($c['other_evidence'])->toHaveCount(1)
        ->and(DB::table('documents')->count())->toBe($docs + 3)
        ->and(DB::table('outbox_messages')->where('event_name', 'claim.evidence.reviewed')->count())->toBe(2)
        ->and(DB::table('claim_evidence_custody_events')->where(['claim_id' => $this->claim->id, 'event_type' => 'REJECTED'])->count())->toBe(1);
});

it('counts a replacement version of rejected evidence and ignores the superseded one', function () {
    $old = c6Attach('CLM-02');
    app(ClaimEvidenceReviewService::class)->review($this->claim, $old, 'REJECT', 'ILLEGIBLE', null, $this->reviewer);
    $new = c6Attach('CLM-02', ['supersedes_document_id' => $old->id]);
    $old->update(['superseded_by_document_id' => $new->id]);

    $item = collect(app(ClaimEvidenceChecklist::class)->build($this->claim)['items'])->firstWhere('document_type_id', 'CLM-02');
    expect($item['status'])->toBe('SUBMITTED')->and($item['evidence'])->toHaveCount(2);
});

it('requires a reason to reject, refuses self-review and re-review', function () {
    $d = c6Attach('CLM-01');
    $svc = app(ClaimEvidenceReviewService::class);
    expect(fn () => $svc->review($this->claim, $d, 'REJECT', null, 'no', $this->reviewer))->toThrow(ValidationException::class)
        ->and(fn () => $svc->review($this->claim, $d, 'ACCEPT', null, null, $this->submitter))->toThrow(ValidationException::class);
    $svc->review($this->claim, $d, 'ACCEPT', null, null, $this->reviewer);
    expect(fn () => $svc->review($this->claim, $d, 'REJECT', 'LATE', null, $this->reviewer))->toThrow(ValidationException::class);
});

it('gates DECISION_PENDING until every mandatory item is accepted', function () {
    $gate = app(ClaimEvidenceGate::class);
    expect($gate->check($this->claim, 'DECISION_PENDING'))->toBe(ClaimEvidenceGate::REASON)
        ->and($gate->check($this->claim, 'CARRIER_REVIEW'))->toBe(ClaimEvidenceGate::REASON)
        ->and($gate->check($this->claim, 'UNDER_ASSESSMENT'))->toBeNull();

    foreach (c6Mandatory() as $typeId) {
        $d = c6Attach($typeId);
        expect($gate->check($this->claim, 'DECISION_PENDING'))->toBe(ClaimEvidenceGate::REASON); // submitted, not reviewed
        app(ClaimEvidenceReviewService::class)->review($this->claim, $d, 'ACCEPT', null, null, $this->reviewer);
    }
    expect($gate->check($this->claim, 'DECISION_PENDING'))->toBeNull()
        ->and(app(ClaimEvidenceChecklist::class)->build($this->claim)['complete'])->toBeTrue();
});

it('registers the transition guard with the claims contract when the contract exists', function () {
    $guards = collect(app()->tagged('claims.transition_guards'))->filter(fn ($g) => $g instanceof \App\Application\Claims\Evidence\ClaimEvidenceTransitionGuard);
    expect($guards)->toHaveCount(1);
    $g = $guards->first();
    expect($g->events())->toBe(['refer_for_decision'])
        ->and($g->check($this->claim, 'refer_for_decision', ['to' => 'DECISION_PENDING']))->toBe(ClaimEvidenceGate::REASON)
        ->and($g->check($this->claim, 'refer_for_decision', []))->toBe(ClaimEvidenceGate::REASON)
        ->and($g->check($this->claim, 'start_assessment', ['to' => 'UNDER_ASSESSMENT']))->toBeNull();
});

it('exposes checklist, metadata and review over HTTP with permissions and tenant scoping', function () {
    $d = c6Attach('CLM-01', ['mime_type' => 'image/jpeg']);
    DB::table('document_versions')->insert(['id' => (string) Str::uuid(), 'document_id' => $d->id, 'version' => 1, 'storage_key' => $d->storage_key, 'sha256' => $d->sha256, 'size_bytes' => 1024, 'mime_type' => 'image/jpeg', 'uploaded_by' => $this->submitter->id, 'created_at' => now()]);
    $h = ['X-Tenant-Id' => $this->f['tenant']->id];
    $base = "/api/v1/claims/{$this->claim->id}/evidence";

    Passport::actingAs($this->reviewer);
    $this->getJson("$base/checklist", $h)->assertOk()->assertJsonPath('data.complete', false)->assertJsonPath('data.class_code', 'MOTOR');
    $this->getJson("$base/rules", $h)->assertOk()->assertJsonPath('data.source', 'PACK');
    $this->getJson("$base/{$d->id}", $h)->assertOk()
        ->assertJsonPath('data.mime_type', 'image/jpeg')->assertJsonPath('data.sha256', $d->sha256)
        ->assertJsonPath('data.uploaded_by', $this->submitter->id)->assertJsonPath('data.link.hash_matches', true)
        ->assertJsonCount(1, 'data.versions')->assertJsonPath('data.version_chain', [$d->id]);
    $this->postJson("$base/{$d->id}/review", ['decision' => 'REJECT'], $h)->assertStatus(422);
    $this->postJson("$base/{$d->id}/review", ['decision' => 'ACCEPT', 'reason_code' => 'LEGIBLE'], $h)->assertOk();
    expect(DB::table('claim_documents')->where('document_id', $d->id)->value('status'))->toBe('VERIFIED');

    Passport::actingAs($this->submitter);
    $this->getJson("$base/checklist", $h)->assertForbidden();

    $other = \App\Models\Tenant::create(['type' => 'CARRIER', 'legal_name' => 'Other '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    Passport::actingAs(makeAuthTestUser($other, ['claims.view'], 'C6_OTHER'));
    $this->getJson("$base/checklist", ['X-Tenant-Id' => $other->id])->assertNotFound();
});
