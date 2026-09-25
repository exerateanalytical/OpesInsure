<?php

declare(strict_types=1);

/*
 * REQ-DOC-007 / REQ-DUP-015 / REQ-DUP-005 (WF-031, WF-082): one public
 * verification service behind every entry point, canonical statuses
 * VALID / EXPIRED / REVOKED / REPLACED / NOT_FOUND, minimal disclosure.
 */

use App\Application\Certificates\CertificateService;
use App\Application\Documents\Verification\PublicVerificationService;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b88User(): User
{
    return User::create(['full_name' => 'B88 Staff '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function b88Policy(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['product']->update(['line_code' => 'AUTO']);
    $f['quote']->update(['line_code' => 'AUTO', 'risk_facts' => ['registration_number' => 'LT-888-CM']]);
    $f['policy'] = Policy::create([
        'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subDay(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => ['line_code' => 'AUTO'], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now(),
    ]);

    return $f;
}

beforeEach(function () {
    $root = storage_path('framework/testing/disks/b88-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
});

it('REQ-DUP-015: the legacy certificates verification class is an alias of the one service', function () {
    expect(app(\App\Application\Certificates\PublicVerificationService::class))->toBeInstanceOf(PublicVerificationService::class);
    foreach (['valid' => 'VALID', 'expired' => 'EXPIRED', 'revoked' => 'REVOKED', 'cancelled' => 'REVOKED', 'replaced' => 'REPLACED', 'superseded' => 'REPLACED', 'not_found' => 'NOT_FOUND'] as $in => $out) {
        expect(PublicVerificationService::canonicalStatus($in))->toBe($out);
    }
});

it('REQ-DOC-007: an engine motor certificate verifies through all entry points with canonical statuses and minimal disclosure', function () {
    $f = b88Policy();
    $svc = app(CertificateService::class);
    $r = $svc->issue($f['policy'], null, ['serial_number' => 'SER-B88-1', 'document_hash' => str_repeat('a', 64), 'document_type_code' => 'MOTOR_INSURANCE_CERTIFICATE'], b88User());
    $doc = $r['certificate'];

    // Canonical API: by verification code, by serial + QR token, by serial alone.
    $byCode = $this->postJson('/api/v1/public/verify', ['reference' => $doc->verification_code])->assertOk()->json('data');
    expect($byCode['status'])->toBe('VALID')->and($byCode['document']['document_number'])->toBe($doc->document_number)
        ->and($byCode)->not->toHaveKey('insured_name')->and(json_encode($byCode))->not->toContain((string) $f['party']->display_name);
    expect($this->postJson('/api/v1/public/verify', ['reference' => 'SER-B88-1', 'token' => $r['verification_token']])->json('data.status'))->toBe('VALID');
    expect($this->postJson('/api/v1/public/verify', ['reference' => 'SER-B88-1', 'token' => str_repeat('z', 64)])->json('data.status'))->toBe('NOT_FOUND');
    expect($this->postJson('/api/v1/public/verify', ['reference' => 'NOPE-B88-000'])->json('data.status'))->toBe('NOT_FOUND');

    // Aliases.
    expect($this->postJson('/api/v1/public/insurance/verify', ['reference' => $doc->verification_code])->json('data.status'))->toBe('VALID');
    $this->postJson('/api/v1/public/certificates/verify', ['serial_number' => 'SER-B88-1', 'token' => $r['verification_token']])->assertOk()
        ->assertJsonPath('data.verified', true)->assertJsonPath('data.status', 'VALID');
    $this->get('/verify?ref=SER-B88-1&t='.$r['verification_token'])->assertOk();

    // Every lookup is logged (hashes only).
    expect(DB::table('public_verification_lookups')->where('document_id', $doc->id)->count())->toBeGreaterThanOrEqual(4);

    // Expired.
    $doc->update(['valid_until' => now()->subDay()]);
    expect($this->postJson('/api/v1/public/verify', ['reference' => $doc->verification_code])->json('data.status'))->toBe('EXPIRED');
    $doc->update(['valid_until' => now()->addYear()]);

    // Replaced by a newer certificate.
    $svc->issue($f['policy'], null, ['serial_number' => 'SER-B88-2', 'document_hash' => str_repeat('b', 64), 'document_type_code' => 'MOTOR_INSURANCE_CERTIFICATE'], b88User());
    expect($this->postJson('/api/v1/public/verify', ['reference' => $doc->verification_code])->json('data.status'))->toBe('REPLACED');

    // Revoked.
    $doc->update(['status' => 'REVOKED']);
    expect($this->postJson('/api/v1/public/verify', ['reference' => $doc->verification_code])->json('data.status'))->toBe('REVOKED');
});
