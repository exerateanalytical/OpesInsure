<?php

declare(strict_types=1);

use App\Application\Policies\PolicyDocumentService;
use App\Models\PolicyCertificate;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingDecision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
});

function counterOffered(array $f): void
{
    $f['proposal']->update(['status' => 'COUNTEROFFERED', 'terms_snapshot' => ['premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF']]);
    $case = UnderwritingCase::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'status' => 'DECIDED', 'priority' => 'NORMAL', 'referral_reasons' => []]);
    $uw = User::create(['full_name' => 'UW', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    UnderwritingDecision::create(['underwriting_case_id' => $case->id, 'decision' => 'COUNTEROFFERED', 'reason_code' => 'HIGHER_RISK', 'notes' => 'Revised premium for claims history.', 'conditions' => ['revised_premium_minor' => 120000, 'revised_total_minor' => 130000], 'decided_by' => $uw->id, 'decided_at' => now()]);
}

it('lists the caller\'s own proposals with product, carrier and totals', function () {
    $f = makeMobileCustomerFixture('+237672220001');
    makeMobileCustomerFixture('+237672220002'); // someone else's proposal
    Passport::actingAs($f['user']);

    $r = $this->getJson('/api/v1/mobile/proposals', tenantHeaderFor($f['tenant']))->assertOk();
    expect($r->json('data'))->toHaveCount(1)
        ->and($r->json('data.0'))->toMatchArray(['id' => $f['proposal']->id, 'status' => 'APPROVED', 'product_name' => 'Test Plan', 'carrier_name' => 'Mobile Wallet Test Carrier Org', 'total_minor' => 100000, 'currency' => 'XAF'])
        ->and($r->json('meta.total'))->toBe(1);
});

it('accepts a counter-offer: revised premium, PAYMENT_PENDING, history', function () {
    $f = makeMobileCustomerFixture('+237672220003');
    counterOffered($f);
    Passport::actingAs($f['user']);

    $list = $this->getJson('/api/v1/mobile/proposals', tenantHeaderFor($f['tenant']))->assertOk();
    expect($list->json('data.0.counter_offer.total_minor'))->toBe(130000);

    $r = $this->postJson("/api/v1/mobile/proposals/{$f['proposal']->id}/counteroffer/accept", [], tenantHeaderFor($f['tenant']))->assertOk();
    expect($r->json('data.status'))->toBe('PAYMENT_PENDING')->and($r->json('data.total_minor'))->toBe(130000);
    expect($f['proposal']->refresh()->terms_snapshot['premium_minor'])->toBe(120000)
        ->and(DB::table('proposal_status_history')->where('proposal_id', $f['proposal']->id)->value('reason_code'))->toBe('COUNTEROFFER_ACCEPTED');

    // No open counter-offer any more.
    $this->postJson("/api/v1/mobile/proposals/{$f['proposal']->id}/counteroffer/decline", [], tenantHeaderFor($f['tenant']))->assertStatus(422);
});

it('declines a counter-offer (WITHDRAWN) and hides other customers\' proposals', function () {
    $f = makeMobileCustomerFixture('+237672220004');
    counterOffered($f);
    $other = makeMobileCustomerFixture('+237672220005');
    Passport::actingAs($other['user']);
    $this->postJson("/api/v1/mobile/proposals/{$f['proposal']->id}/counteroffer/decline", [], tenantHeaderFor($other['tenant']))->assertNotFound();

    Passport::actingAs($f['user']);
    $this->postJson("/api/v1/mobile/proposals/{$f['proposal']->id}/counteroffer/decline", [], tenantHeaderFor($f['tenant']))->assertOk()->assertJsonPath('data.status', 'WITHDRAWN');
    $this->postJson("/api/v1/mobile/proposals/{$f['proposal']->id}/counteroffer/maybe", [], tenantHeaderFor($f['tenant']))->assertNotFound();
});

it('saves and returns the customer profile with address, occupation, birth date and beneficiaries', function () {
    $f = makeMobileCustomerFixture('+237672220006');
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    $this->patchJson('/api/v1/mobile/account/customer-profile', ['beneficiaries' => [['name' => 'Ada', 'relationship' => 'child', 'share_percent' => 60], ['name' => 'Ben', 'relationship' => 'spouse', 'share_percent' => 30]]], $h)
        ->assertStatus(422)->assertJsonValidationErrors('beneficiaries');

    $r = $this->patchJson('/api/v1/mobile/account/customer-profile', [
        'address_line1' => '12 Rue de la Joie', 'city' => 'Douala', 'region' => 'Littoral', 'occupation' => 'Teacher', 'date_of_birth' => '1990-04-12',
        'beneficiaries' => [['name' => 'Ada', 'relationship' => 'child', 'share_percent' => 60], ['name' => 'Ben', 'relationship' => 'spouse', 'share_percent' => 40]],
    ], $h)->assertOk();
    expect($r->json('data'))->toMatchArray(['address_line1' => '12 Rue de la Joie', 'city' => 'Douala', 'region' => 'Littoral', 'occupation' => 'Teacher', 'date_of_birth' => '1990-04-12'])
        ->and($r->json('data.beneficiaries.1'))->toBe(['name' => 'Ben', 'relationship' => 'SPOUSE', 'share_percent' => 40]);

    $this->patchJson('/api/v1/mobile/account/customer-profile', ['city' => 'Yaoundé'], $h)->assertOk();
    $g = $this->getJson('/api/v1/mobile/account/customer-profile', $h)->assertOk();
    expect($g->json('data.city'))->toBe('Yaoundé')->and($g->json('data.address_line1'))->toBe('12 Rue de la Joie')->and($g->json('data.beneficiaries'))->toHaveCount(2);
});

it('records consents with version and timestamp, and withdrawals', function () {
    $f = makeMobileCustomerFixture('+237672220007');
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    $initial = $this->getJson('/api/v1/mobile/account/consents', $h)->assertOk();
    expect(collect($initial->json('data'))->pluck('granted')->unique()->all())->toBe([false]);

    $r = $this->putJson('/api/v1/mobile/account/consents', ['notice_version' => 'privacy-2026-09', 'consents' => [['purpose' => 'MARKETING', 'granted' => true], ['purpose' => 'ANALYTICS', 'granted' => true]]], $h)->assertOk();
    $marketing = collect($r->json('data'))->firstWhere('purpose', 'MARKETING');
    expect($marketing['granted'])->toBeTrue()->and($marketing['notice_version'])->toBe('privacy-2026-09')->and($marketing['updated_at'])->not->toBeNull();

    $this->putJson('/api/v1/mobile/account/consents', ['consents' => [['purpose' => 'MARKETING', 'granted' => false]]], $h)->assertOk();
    expect(collect($this->getJson('/api/v1/mobile/account/consents', $h)->json('data'))->firstWhere('purpose', 'MARKETING')['granted'])->toBeFalse();
    expect(DB::table('consent_events')->count())->toBe(3);

    $this->putJson('/api/v1/mobile/account/consents', ['consents' => [['purpose' => 'SELL_MY_SOUL', 'granted' => true]]], $h)->assertStatus(422);
});

it('creates EXPORT/DELETE privacy requests in the DSR workflow, once per open request', function () {
    $f = makeMobileCustomerFixture('+237672220008');
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    $r = $this->postJson('/api/v1/mobile/account/privacy-requests', ['type' => 'EXPORT'], $h)->assertStatus(201);
    expect($r->json('data.type'))->toBe('EXPORT')->and($r->json('data.status'))->toBe('RECEIVED')->and($r->json('data.reference'))->toStartWith('DSR-');
    $this->postJson('/api/v1/mobile/account/privacy-requests', ['type' => 'EXPORT'], $h)->assertOk()->assertJsonPath('data.id', $r->json('data.id'));
    $this->postJson('/api/v1/mobile/account/privacy-requests', ['type' => 'DELETE'], $h)->assertStatus(201);

    expect(DB::table('data_subject_requests')->where('party_id', $f['party']->id)->pluck('type')->sort()->values()->all())->toBe(['ERASURE', 'PORTABILITY']);
    expect($this->getJson('/api/v1/mobile/account/privacy-requests', $h)->json('data'))->toHaveCount(2);
});

it('links support cases only to the customer\'s own records and escalates them', function () {
    $f = makeMobileCustomerFixture('+237672220009');
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant']);
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'P-SUP']);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party']);
    $other = makeMobileCustomerFixture('+237672220010');
    $theirPayment = makeMobileTestPayment($other['proposal'], $f['tenant']);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    $base = ['category' => 'PAYMENT', 'subject' => 'Charged twice', 'description' => 'I was charged twice for my policy.'];
    $this->postJson('/api/v1/mobile/support/cases', $base + ['payment_id' => $theirPayment->id], $h + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(422)->assertJsonValidationErrors('payment_id');

    $parent = $this->postJson('/api/v1/mobile/support/cases', $base, $h + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);
    $case = $this->postJson('/api/v1/mobile/support/cases', $base + ['payment_id' => $payment->id, 'policy_id' => $policy->id, 'claim_id' => $claim->id, 'parent_case_id' => $parent->json('data.id')], $h + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201);
    expect($case->json('data'))->toMatchArray(['payment_id' => $payment->id, 'policy_id' => $policy->id, 'claim_id' => $claim->id, 'parent_case_id' => $parent->json('data.id'), 'escalated' => false]);

    $e = $this->postJson("/api/v1/mobile/support/cases/{$case->json('data.id')}/escalate", ['reason' => 'No answer for 3 days'], $h)->assertOk();
    expect($e->json('data.priority'))->toBe('URGENT')->and($e->json('data.escalated'))->toBeTrue();
    $this->postJson("/api/v1/mobile/support/cases/{$case->json('data.id')}/escalate", [], $h)->assertOk(); // idempotent
    expect(DB::table('support_ticket_events')->where('support_ticket_id', $case->json('data.id'))->where('type', 'ESCALATED')->count())->toBe(1);
});

it('shows the public QR verification page only with the QR token, and logs every lookup', function () {
    Storage::fake('local');
    $f = makeMobileCustomerFixture('+237672220011');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'P-QR', 'coverage_starts_at' => now()->subDay(), 'terms_snapshot' => ['total_minor' => 100000, 'currency' => 'XAF']]);
    $docs = app(PolicyDocumentService::class)->ensure($policy, $f['user']);
    $cert = $docs['certificate'];
    $url = PolicyDocumentService::verifyUrl($cert);
    expect($url)->toContain('&t=');
    $path = '/verify?'.parse_url($url, PHP_URL_QUERY);

    $ok = $this->get($path)->assertOk();
    $ok->assertSee('Valid — insured')->assertSee('Valide — assuré')->assertSee('Mobile Wallet Test Carrier Org')->assertDontSee('Mobile Wallet Test Party')->assertDontSee('1 000');

    // Ref without the token, a wrong token and an unknown ref all look identical.
    $noToken = $this->get('/verify?ref='.$cert->serial_number)->assertOk();
    $wrong = $this->get('/verify?ref='.$cert->serial_number.'&t='.Str::random(64))->assertOk();
    $unknown = $this->get('/verify?ref=OPS-MOT-2026-NOPE1234&t='.Str::random(64))->assertOk();
    foreach ([$noToken, $wrong, $unknown] as $r) {
        $r->assertSee('Certificate not found')->assertDontSee('Mobile Wallet Test Carrier Org');
    }

    expect(DB::table('public_verification_lookups')->count())->toBe(4)
        ->and(DB::table('public_verification_lookups')->where('result', 'not_found')->count())->toBe(3)
        ->and(DB::table('certificate_verification_events')->where('policy_certificate_id', $cert->id)->count())->toBe(1);

    // Revoked certificate.
    PolicyCertificate::whereKey($cert->id)->update(['status' => 'VOID']);
    $this->get($path)->assertOk()->assertSee('Revoked');

    // The API reference check logs not-found lookups too.
    $this->postJson('/api/v1/public/insurance/verify', ['reference' => 'NOPE-999'])->assertOk()->assertJsonPath('data.result', 'not_found');
    expect(DB::table('public_verification_lookups')->where('channel', 'API')->count())->toBe(1);
});
