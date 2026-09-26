<?php

declare(strict_types=1);

use App\Models\Claim;
use App\Models\ClaimDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

function cdwHeaders($tenant): array
{
    return array_merge(tenantHeaderFor($tenant), ['Idempotency-Key' => (string) Str::uuid()]);
}

function cdwFixture(string $phone = '+237670000310'): array
{
    $f = makeMobileCustomerFixture($phone);
    $f['policy'] = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['coverage_starts_at' => now()->subMonths(6)]);

    return $f;
}

// ---- withdrawal ----

it('lets the claimant withdraw an early-stage claim, audited with a claim event', function (string $status) {
    $f = cdwFixture();
    $claim = makeMobileTestClaim($f['tenant'], $f['policy'], $f['party'], ['status' => $status]);
    Passport::actingAs($f['user']);

    expect($this->getJson('/api/v1/mobile/claims/'.$claim->id, tenantHeaderFor($f['tenant']))->json('data.can_withdraw'))->toBeTrue();

    $r = $this->postJson('/api/v1/mobile/claims/'.$claim->id.'/withdraw', ['reason' => 'Settled privately with the other driver.'], cdwHeaders($f['tenant']));

    $r->assertOk();
    expect($r->json('data.status'))->toBe('CLOSED')->and($r->json('data.can_withdraw'))->toBeFalse();
    $claim->refresh();
    expect($claim->withdrawn_at)->not->toBeNull()
        ->and($claim->withdrawal_reason)->toBe('Settled privately with the other driver.')
        ->and($claim->closed_at)->not->toBeNull();
    expect(DB::table('claim_events')->where(['claim_id' => $claim->id, 'type' => 'CLAIM_WITHDRAWN', 'from_status' => $status, 'to_status' => 'CLOSED'])->exists())->toBeTrue();
    expect(DB::table('audit_log')->where(['action' => 'claim.withdrawn', 'subject_id' => $claim->id, 'reason_code' => 'WITHDRAWN_BY_CLAIMANT'])->exists())->toBeTrue();
    expect(DB::table('outbox_messages')->where(['event_name' => 'claim.withdrawn', 'aggregate_id' => $claim->id])->exists())->toBeTrue();
})->with(['SUBMITTED', 'ACKNOWLEDGED', 'EVIDENCE_PENDING']);

it('refuses withdrawal once the claim is being assessed, decided or paid', function (string $status) {
    $f = cdwFixture();
    $claim = makeMobileTestClaim($f['tenant'], $f['policy'], $f['party'], ['status' => $status]);
    Passport::actingAs($f['user']);

    expect($this->getJson('/api/v1/mobile/claims/'.$claim->id, tenantHeaderFor($f['tenant']))->json('data.can_withdraw'))->toBeFalse();
    $this->postJson('/api/v1/mobile/claims/'.$claim->id.'/withdraw', ['reason' => 'Changed my mind.'], cdwHeaders($f['tenant']))->assertStatus(422);
    expect($claim->refresh()->status)->toBe($status)->and($claim->withdrawn_at)->toBeNull();
})->with(['ASSESSMENT', 'CARRIER_REVIEW', 'APPROVED', 'DECLINED', 'PAID', 'CLOSED']);

it('requires a reason and forbids withdrawing another customer\'s claim', function () {
    $f = cdwFixture();
    $other = makeMobileCustomerFixture('+237670000311');
    $theirPolicy = makeMobileTestPolicy($other['proposal'], $f['tenant'], $other['carrier']->id, $other['party']->id);
    $theirs = makeMobileTestClaim($f['tenant'], $theirPolicy, $other['party']);
    $mine = makeMobileTestClaim($f['tenant'], $f['policy'], $f['party']);
    Passport::actingAs($f['user']);

    $this->postJson('/api/v1/mobile/claims/'.$mine->id.'/withdraw', [], cdwHeaders($f['tenant']))->assertStatus(422);
    $code = $this->postJson('/api/v1/mobile/claims/'.$theirs->id.'/withdraw', ['reason' => 'Not mine.'], cdwHeaders($f['tenant']))->status();
    expect($code)->toBeIn([403, 404]);
    $this->postJson('/api/v1/mobile/claims/'.Str::uuid().'/withdraw', ['reason' => 'Nothing.'], cdwHeaders($f['tenant']))->assertStatus(404);
    expect($theirs->refresh()->status)->toBe('SUBMITTED');
});

// ---- drafts ----

it('saves, updates and resumes a draft without creating a claim or consuming a claim number', function () {
    $f = cdwFixture();
    Passport::actingAs($f['user']);

    $r = $this->postJson('/api/v1/mobile/claims/drafts', ['policy_id' => $f['policy']->id, 'incident_type' => 'COLLISION', 'client_state' => ['location' => 'Akwa']], cdwHeaders($f['tenant']));
    $r->assertCreated();
    $id = $r->json('data.id');
    expect(Claim::count())->toBe(0)
        ->and(DB::table('outbox_messages')->where('event_name', 'like', 'claim.%')->count())->toBe(0);

    $this->patchJson('/api/v1/mobile/claims/drafts/'.$id, ['description' => 'Hit a pothole'], cdwHeaders($f['tenant']))->assertOk()
        ->assertJsonPath('data.payload.description', 'Hit a pothole')->assertJsonPath('data.payload.incident_type', 'COLLISION');

    $this->getJson('/api/v1/mobile/claims/drafts', tenantHeaderFor($f['tenant']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    $this->getJson('/api/v1/mobile/claims/drafts/'.$id, tenantHeaderFor($f['tenant']))->assertOk()->assertJsonPath('data.payload.client_state.location', 'Akwa');
    expect(Claim::count())->toBe(0);
});

it('submits a complete draft through FNOL once, then stops listing it', function () {
    $f = cdwFixture();
    Passport::actingAs($f['user']);
    $id = $this->postJson('/api/v1/mobile/claims/drafts', ['policy_id' => $f['policy']->id, 'description' => 'Short'], cdwHeaders($f['tenant']))->json('data.id');

    // Incomplete: description too short, no incident date.
    $this->postJson('/api/v1/mobile/claims/drafts/'.$id.'/submit', [], cdwHeaders($f['tenant']))->assertStatus(422)->assertJsonValidationErrors(['draft', 'incident_at']);
    expect(Claim::count())->toBe(0);

    $this->patchJson('/api/v1/mobile/claims/drafts/'.$id, ['description' => 'Rear-ended at a traffic light while stationary.', 'incident_at' => now()->subDay()->toIso8601String()], cdwHeaders($f['tenant']))->assertOk();
    $r = $this->postJson('/api/v1/mobile/claims/drafts/'.$id.'/submit', [], cdwHeaders($f['tenant']));
    $r->assertCreated();
    expect($r->json('data.status'))->toBe('SUBMITTED')
        ->and($r->json('data.claim_number'))->toStartWith('CLM-')
        ->and($r->json('data.claimant_party_id'))->toBe($f['party']->id);

    $again = $this->postJson('/api/v1/mobile/claims/drafts/'.$id.'/submit', [], cdwHeaders($f['tenant']));
    $again->assertOk();
    expect($again->json('data.id'))->toBe($r->json('data.id'))->and(Claim::count())->toBe(1);
    $this->getJson('/api/v1/mobile/claims/drafts', tenantHeaderFor($f['tenant']))->assertJsonCount(0, 'data');
    expect(ClaimDraft::find($id)->claim_id)->toBe($r->json('data.id'));
});

it('keeps drafts private to their owner and rejects someone else\'s policy', function () {
    $f = cdwFixture();
    $other = makeMobileCustomerFixture('+237670000312');
    $theirPolicy = makeMobileTestPolicy($other['proposal'], $f['tenant'], $other['carrier']->id, $other['party']->id);
    Passport::actingAs($f['user']);

    $this->postJson('/api/v1/mobile/claims/drafts', ['policy_id' => $theirPolicy->id], cdwHeaders($f['tenant']))->assertStatus(422)->assertJsonValidationErrors(['policy_id']);
    $id = $this->postJson('/api/v1/mobile/claims/drafts', ['policy_id' => $f['policy']->id], cdwHeaders($f['tenant']))->json('data.id');

    // Another customer (own tenant) using this tenant header, and with their own tenant: never sees the draft.
    Passport::actingAs($other['user']);
    foreach ([tenantHeaderFor($f['tenant']), tenantHeaderFor($other['tenant'])] as $h) {
        $idem = $h + ['Idempotency-Key' => (string) Str::uuid()];
        expect($this->getJson('/api/v1/mobile/claims/drafts/'.$id, $h)->status())->toBeIn([403, 404]);
        expect($this->patchJson('/api/v1/mobile/claims/drafts/'.$id, ['description' => 'x'], $idem)->status())->toBeIn([403, 404]);
        expect($this->postJson('/api/v1/mobile/claims/drafts/'.$id.'/submit', [], $h + ['Idempotency-Key' => (string) Str::uuid()])->status())->toBeIn([403, 404]);
        expect($this->deleteJson('/api/v1/mobile/claims/drafts/'.$id, [], $h + ['Idempotency-Key' => (string) Str::uuid()])->status())->toBeIn([403, 404]);
    }
    $this->getJson('/api/v1/mobile/claims/drafts', tenantHeaderFor($other['tenant']))->assertOk()->assertJsonCount(0, 'data');
    expect(ClaimDraft::find($id)->payload)->not->toHaveKey('description');

    Passport::actingAs($f['user']);
    $this->deleteJson('/api/v1/mobile/claims/drafts/'.$id, [], cdwHeaders($f['tenant']))->assertNoContent();
    expect(ClaimDraft::count())->toBe(0);
});
