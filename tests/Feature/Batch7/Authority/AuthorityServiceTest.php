<?php

declare(strict_types=1);

use App\Application\Authority\AuthorityService;
use App\Application\CarrierOperations\Agreements\LegacyAgreementBackfill;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\Party;
use App\Models\Partner;
use App\Models\PolicyIssuanceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

/** Paid, reconciled proposal (total 100 000 XAF) + a broker partner of the tenant with an ACTIVE AUTO/CM agreement. */
function b7aFixture(int $maxPremium = 500000, bool $backfill = true, ?string $intermediaryStatus = 'AUTHORIZED'): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF']]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED', 'reconciled_at' => now()]);
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Broker 7A', 'status' => 'ACTIVE']);
    $f['partner'] = Partner::create(['tenant_id' => $f['tenant']->id, 'party_id' => $party->id, 'type' => 'BROKER', 'status' => 'ACTIVE']);
    if ($intermediaryStatus) {
        DB::table('intermediary_authorizations')->insert(['id' => (string) Str::uuid(), 'partner_id' => $f['partner']->id, 'intermediary_type' => 'BROKER',
            'reference_year' => (int) now()->format('Y'), 'status' => $intermediaryStatus, 'effective_from' => now()->subYear()->toDateString(),
            'source_authority' => 'MINFI', 'data_origin' => 'TEST', 'created_at' => now(), 'updated_at' => now()]);
    }
    $f['agreement_id'] = (string) Str::uuid();
    DB::table('delegated_authority_agreements')->insert(['id' => $f['agreement_id'], 'carrier_id' => $f['carrier']->id, 'partner_id' => $f['partner']->id,
        'agreement_number' => 'DA-7A-'.Str::random(6), 'effective_from' => now()->subMonth()->toDateString(), 'effective_until' => now()->addYear()->toDateString(),
        'status' => 'ACTIVE', 'permitted_lines' => json_encode(['AUTO']), 'max_policy_premium_minor' => $maxPremium, 'territories' => json_encode(['CM']),
        'created_at' => now(), 'updated_at' => now()]);
    if ($backfill) {
        app(LegacyAgreementBackfill::class)->run();
    }
    $f['actor'] = User::create(['full_name' => 'Broker Desk', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);

    return $f;
}

function b7aRequest(array $f, string $territory = 'CM'): PolicyIssuanceRequest
{
    return app(PolicyIssuanceService::class)->request($f['tenant'], $f['proposal'], $f['payment'], [
        'coverage_starts_at' => now()->addDay()->toIso8601String(), 'coverage_ends_at' => now()->addYear()->toIso8601String(),
        'delegated_authority_agreement_id' => $f['agreement_id'], 'territory' => $territory,
    ], $f['actor']);
}

it('allows delegated issuance within the authority_limits row and records consumption', function () {
    $f = b7aFixture();
    $req = b7aRequest($f);

    $limit = DB::table('authority_limits')->where('legacy_delegated_authority_agreement_id', $f['agreement_id'])->where('authority_type', 'POLICY_PREMIUM')->first();
    expect($req->status)->toBe('REQUESTED')
        ->and($req->authority_snapshot['mode'])->toBe('DELEGATED_AUTHORITY')
        ->and($req->authority_snapshot['source'])->toBe(AuthorityService::LIMIT_SOURCE)
        ->and($req->authority_snapshot['authority_limit_id'])->toBe($limit->id);
    expect(DB::table('authority_checks')->where('authority_limit_id', $limit->id)->where('outcome', 'ALLOWED')->count())->toBe(1);
});

it('reads authority_limits over the legacy agreement amount', function () {
    $f = b7aFixture(500000);
    DB::table('authority_limits')->where('legacy_delegated_authority_agreement_id', $f['agreement_id'])->where('authority_type', 'POLICY_PREMIUM')->update(['max_amount_minor' => 50000]);

    $req = b7aRequest($f);
    expect($req->status)->toBe('CARRIER_REVIEW')->and($req->authority_snapshot['outcome'])->toBe('REFERRED')
        ->and($req->authority_snapshot['max_amount_minor'])->toBe(50000);
});

it('refers a threshold-exceed to an AUTHORITY_REFERRAL case instead of a bare error', function () {
    $f = b7aFixture(50000);
    $req = b7aRequest($f);

    expect($req->status)->toBe('CARRIER_REVIEW')
        ->and($req->authority_snapshot['mode'])->toBe('AUTHORITY_REFERRAL')
        ->and($req->authority_snapshot['decision'])->toBe('PREMIUM_AUTHORITY_EXCEEDED');
    $case = DB::table('cases')->where('id', $req->authority_snapshot['referral_case_id'])->first();
    expect($case)->not->toBeNull()->and($case->case_type_code)->toBe('AUTHORITY_REFERRAL')
        ->and($case->subject_id)->toBe($f['proposal']->id)->and($case->carrier_id)->toBe($f['carrier']->id);
});

it('falls back to the legacy agreement via AuthorityChecker when no authority_limits row exists', function () {
    $f = b7aFixture(500000, backfill: false);
    $req = b7aRequest($f);
    expect($req->status)->toBe('REQUESTED')->and($req->authority_snapshot['source'])->toBe(AuthorityService::LEGACY_SOURCE);
});

it('still denies scope failures (territory) with a validation error', function () {
    $f = b7aFixture();
    expect(fn () => b7aRequest($f, 'GA'))->toThrow(ValidationException::class);
    expect(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->exists())->toBeFalse();
});

it('enforces intermediary authorization: lapsed denies, missing refers', function () {
    $lapsed = b7aFixture(intermediaryStatus: 'SUSPENDED');
    expect(fn () => b7aRequest($lapsed))->toThrow(ValidationException::class);

    $missing = b7aFixture(intermediaryStatus: null);
    $req = b7aRequest($missing);
    expect($req->status)->toBe('CARRIER_REVIEW')->and($req->authority_snapshot['decision'])->toBe('INTERMEDIARY_AUTHORIZATION_MISSING')
        ->and($req->authority_snapshot['referral_case_id'])->not->toBeNull();
});

it('keeps a denied attempt in authority_checks after the request transaction rolls back', function () {
    $f = b7aFixture(intermediaryStatus: 'SUSPENDED');
    expect(fn () => b7aRequest($f))->toThrow(ValidationException::class);

    $row = DB::table('authority_checks')->where('subject_id', $f['proposal']->id)->sole();
    expect($row->outcome)->toBe('DENIED')->and($row->reason)->toBe('INTERMEDIARY_NOT_AUTHORIZED')
        ->and($row->delegated_authority_agreement_id)->toBe($f['agreement_id'])
        ->and(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->exists())->toBeFalse();

    $territory = b7aFixture();
    expect(fn () => b7aRequest($territory, 'GA'))->toThrow(ValidationException::class);
    expect(DB::table('authority_checks')->where('subject_id', $territory['proposal']->id)->where('outcome', 'DENIED')->value('reason'))->toBe('TERRITORY_NOT_PERMITTED');
});
