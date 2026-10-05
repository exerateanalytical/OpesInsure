<?php

declare(strict_types=1);

use App\Application\Uploads\FileSignature;
use App\Models\Claim;
use App\Models\ClaimDraft;
use App\Models\Proposal;
use App\Models\QuoteOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

/*
 | Customer account gaps (phase-1 fix C): per-policy filters on the mobile claims/payments lists, the
 | quote_offer_id filter and checklist progress on /mobile/proposals, the claim wizard's draft → submit path
 | (evidence saved on the draft, declaration recorded on submit) and QuickTime video uploads.
 */

function gapHeaders($tenant): array
{
    return array_merge(tenantHeaderFor($tenant), ['Idempotency-Key' => (string) Str::uuid()]);
}

/** A second application (own offer) and policy for the same customer. */
function gapSecondPolicy(array $f): array
{
    $quote = makeMobileTestQuote($f['tenant'], $f['party']);
    $offer = QuoteOffer::create(['quote_id' => $quote->id, 'carrier_id' => $f['carrier']->id, 'product_id' => $f['product']->id, 'tariff_version_id' => $f['tariff']->id,
        'premium_minor' => 50000, 'total_minor' => 50000, 'currency' => 'XAF', 'status' => 'OFFERED', 'calculation_breakdown' => [], 'valid_until' => now()->addDays(7)]);
    $proposal = Proposal::create(['tenant_id' => $f['tenant']->id, 'quote_offer_id' => $offer->id, 'party_id' => $f['party']->id, 'status' => 'APPROVED']);

    return [$proposal, makeMobileTestPolicy($proposal, $f['tenant'], $f['carrier']->id, $f['party']->id)];
}

it('filters the claims list by policy, staying owner-scoped', function () {
    $f = makeMobileCustomerFixture('+237670000401');
    $policyA = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    [, $policyB] = gapSecondPolicy($f);
    $a = makeMobileTestClaim($f['tenant'], $policyA, $f['party']);
    makeMobileTestClaim($f['tenant'], $policyB, $f['party']);
    Passport::actingAs($f['user']);

    $all = $this->getJson('/api/v1/mobile/claims', tenantHeaderFor($f['tenant']))->assertOk();
    expect($all->json('data.data'))->toHaveCount(2);

    $one = $this->getJson('/api/v1/mobile/claims?policy_id='.$policyA->id, tenantHeaderFor($f['tenant']))->assertOk();
    expect($one->json('data.data'))->toHaveCount(1)->and($one->json('data.data.0.id'))->toBe($a->id);

    // Someone else's policy id: nothing (never their claims).
    $other = makeMobileCustomerFixture('+237670000402');
    $theirPolicy = makeMobileTestPolicy($other['proposal'], $f['tenant'], $other['carrier']->id, $other['party']->id);
    makeMobileTestClaim($f['tenant'], $theirPolicy, $other['party']);
    expect($this->getJson('/api/v1/mobile/claims?policy_id='.$theirPolicy->id, tenantHeaderFor($f['tenant']))->json('data.data'))->toHaveCount(0);

    $this->getJson('/api/v1/mobile/claims?policy_id=not-a-uuid', tenantHeaderFor($f['tenant']))->assertStatus(422);
});

it('filters the payments list by policy and by application', function () {
    $f = makeMobileCustomerFixture('+237670000403');
    $policyA = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    [$proposalB] = gapSecondPolicy($f);
    $payA = makeMobileTestPayment($f['proposal'], $f['tenant']);
    $payB = makeMobileTestPayment($proposalB, $f['tenant']);
    Passport::actingAs($f['user']);

    expect($this->getJson('/api/v1/mobile/payments', tenantHeaderFor($f['tenant']))->assertOk()->json('data'))->toHaveCount(2);
    $byPolicy = $this->getJson('/api/v1/mobile/payments?policy_id='.$policyA->id, tenantHeaderFor($f['tenant']))->assertOk();
    expect(collect($byPolicy->json('data'))->pluck('id')->all())->toBe([$payA->id]);
    $byProposal = $this->getJson('/api/v1/mobile/payments?proposal_id='.$proposalB->id, tenantHeaderFor($f['tenant']))->assertOk();
    expect(collect($byProposal->json('data'))->pluck('id')->all())->toBe([$payB->id]);
});

it('finds the application for an offer and carries checklist progress on open rows', function () {
    $f = makeMobileCustomerFixture('+237670000404');
    [$proposalB] = gapSecondPolicy($f); // issued (has a policy)
    Passport::actingAs($f['user']);

    $hit = $this->getJson('/api/v1/mobile/proposals?quote_offer_id='.$f['proposal']->quote_offer_id, tenantHeaderFor($f['tenant']))->assertOk();
    expect($hit->json('data'))->toHaveCount(1)->and($hit->json('data.0.id'))->toBe($f['proposal']->id);
    expect($hit->json('data.0'))->toHaveKey('required_documents');
    expect($hit->json('data.0.required_documents'))->toBeArray();

    $issued = $this->getJson('/api/v1/mobile/proposals?quote_offer_id='.$proposalB->quote_offer_id, tenantHeaderFor($f['tenant']))->assertOk();
    expect($issued->json('data.0.policy_id'))->not->toBeNull()->and($issued->json('data.0'))->not->toHaveKey('required_documents');

    expect($this->getJson('/api/v1/mobile/proposals?quote_offer_id='.Str::uuid(), tenantHeaderFor($f['tenant']))->json('data'))->toHaveCount(0);
});

it('keeps evidence and coordinates on a draft, files it on submit with the declaration, and attaches the files once', function () {
    $f = makeMobileCustomerFixture('+237670000405');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);
    $doc = makeMobileTestDocument($f['tenant'], $f['party']);
    $other = makeMobileCustomerFixture('+237670000406');
    $theirDoc = makeMobileTestDocument($f['tenant'], $other['party']);
    Passport::actingAs($f['user']);

    $id = $this->postJson('/api/v1/mobile/claims/drafts', ['policy_id' => $policy->id, 'incident_type' => 'COLLISION',
        'description' => 'Rear-ended at a traffic light while stationary.', 'incident_at' => now()->subDay()->toIso8601String(),
        'latitude' => 4.05, 'longitude' => 9.7], gapHeaders($f['tenant']))->assertCreated()->json('data.id');

    $this->patchJson('/api/v1/mobile/claims/drafts/'.$id, ['evidence' => [
        ['document_id' => $doc->id, 'evidence_type' => 'PHOTO', 'name' => 'front.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1234],
        ['document_id' => $theirDoc->id, 'evidence_type' => 'PHOTO', 'name' => 'not-mine.jpg'],
    ]], gapHeaders($f['tenant']))->assertOk()->assertJsonPath('data.payload.evidence.0.name', 'front.jpg');
    // A file reference is required.
    $this->patchJson('/api/v1/mobile/claims/drafts/'.$id, ['evidence' => [['evidence_type' => 'PHOTO']]], gapHeaders($f['tenant']))->assertStatus(422);
    expect(Claim::count())->toBe(0);

    $r = $this->postJson('/api/v1/mobile/claims/drafts/'.$id.'/submit', ['declaration_confirmed' => true], gapHeaders($f['tenant']));
    $r->assertCreated();
    $claimId = $r->json('data.id');
    expect($r->json('evidence.attached') + $r->json('evidence.pending'))->toBe(1)
        ->and($r->json('evidence.failed'))->toHaveCount(1)
        ->and($r->json('evidence.failed.0.name'))->toBe('not-mine.jpg');
    expect(DB::table('claim_documents')->where(['claim_id' => $claimId, 'document_id' => $doc->id])->exists())->toBeTrue()
        ->and(DB::table('claim_documents')->where(['claim_id' => $claimId, 'document_id' => $theirDoc->id])->exists())->toBeFalse();
    $incident = Claim::find($claimId)->loss_details['incident'] ?? [];
    expect($incident['declaration_confirmed'] ?? null)->toBeTrue()
        ->and((float) ($incident['latitude'] ?? 0))->toBe(4.05);
    expect(DB::table('audit_log')->where(['action' => 'claim.declaration.confirmed', 'subject_id' => $claimId])->exists())->toBeTrue();

    // Replay: same claim, nothing attached twice.
    $again = $this->postJson('/api/v1/mobile/claims/drafts/'.$id.'/submit', ['declaration_confirmed' => true], gapHeaders($f['tenant']))->assertOk();
    expect($again->json('data.id'))->toBe($claimId)->and($again->json('evidence.attached'))->toBe(0)->and(Claim::count())->toBe(1);
    expect(DB::table('claim_documents')->where('claim_id', $claimId)->count())->toBe(1);
    expect(ClaimDraft::find($id)->claim_id)->toBe($claimId);
});

it('still submits a draft with an empty body (web flow unchanged)', function () {
    $f = makeMobileCustomerFixture('+237670000407');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);
    Passport::actingAs($f['user']);
    $id = $this->postJson('/api/v1/mobile/claims/drafts', ['policy_id' => $policy->id, 'description' => 'Windscreen cracked by a stone on the highway.',
        'incident_at' => now()->subDay()->toIso8601String()], gapHeaders($f['tenant']))->json('data.id');

    $r = $this->postJson('/api/v1/mobile/claims/drafts/'.$id.'/submit', [], gapHeaders($f['tenant']))->assertCreated();
    expect($r->json('data.status'))->toBe('SUBMITTED')->and($r->json('evidence.failed'))->toBe([]);
    expect(Claim::find($r->json('data.id'))->loss_details['incident']['declaration_confirmed'] ?? false)->toBeFalse();
});

it('accepts QuickTime video uploads by their real type and still checks the signature', function () {
    expect(FileSignature::ALLOWED)->toContain('video/quicktime')
        ->and(FileSignature::matches("\x00\x00\x00\x14ftypqt  ", 'video/quicktime'))->toBeTrue()
        ->and(FileSignature::matches("\x00\x00\x00\x08wide\x00\x00\x00\x00", 'video/quicktime'))->toBeTrue()
        ->and(FileSignature::matches('<html><script>xx', 'video/quicktime'))->toBeFalse()
        ->and(FileSignature::matches("\x00\x00\x00\x18ftypmp42", 'video/mp4'))->toBeTrue();

    $f = makeMobileCustomerFixture('+237670000408');
    Passport::actingAs($f['user']);
    $this->postJson('/api/v1/mobile/uploads', ['resource_type' => 'CLAIM_EVIDENCE', 'mime_type' => 'video/quicktime', 'total_chunks' => 1, 'total_size_bytes' => 16],
        gapHeaders($f['tenant']))->assertCreated();
});
