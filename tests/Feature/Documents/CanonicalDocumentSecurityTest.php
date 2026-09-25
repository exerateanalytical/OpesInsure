<?php

declare(strict_types=1);

use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Security\DocumentVerificationPresenter;
use App\Application\Documents\Security\VerificationCredentials;
use App\Models\Document;
use App\Models\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/document_engine_helpers.php';

/*
 | Canonical Implementation Specification v1: document_system, document_implementation_policy,
 | cryptographic_print_verification_security (REQ-DOC-CANON-*).
 */

beforeEach(function () {
    $root = storage_path('framework/testing/disks/canon-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
});

it('REQ-DOC-CANON-001: seeds the 220 canonical records with stable ids, dictionary and catalogue profiles idempotently', function () {
    expect(DB::table('document_canonical_specs')->count())->toBe(220)
        ->and(DB::table('document_canonical_specs')->where('spec_id', 'DOC-001')->value('name_en'))->toBe('Insurance Quote')
        ->and(DB::table('document_canonical_specs')->where('spec_id', 'DOC-220')->exists())->toBeTrue()
        ->and(DB::table('document_spec_dictionary')->where('kind', 'SECURITY_TIER')->count())->toBe(5)
        ->and(DB::table('document_spec_dictionary')->where('kind', 'FIELD_GROUP')->count())->toBe(15)
        ->and(DB::table('document_spec_dictionary')->where('kind', 'A4_ZONE')->count())->toBe(7)
        ->and(DB::table('document_spec_dictionary')->where('kind', 'MASTER_SHELL')->count())->toBe(5)
        ->and(DB::table('document_spec_dictionary')->where('kind', 'ACCESS_PROFILE')->count())->toBe(5)
        ->and(DB::table('document_spec_dictionary')->where('kind', 'CONFIDENTIALITY_CLASS')->count())->toBe(7);
    $att = DB::table('document_types')->where('canonical_code', 'MOTOR_INSURANCE_ATTESTATION')->first();
    expect($att->canonical_spec_id)->toBe('DOC-036')->and($att->security_tier)->toBe('S4')->and($att->security_tier_ceiling)->toBe('S5')
        ->and($att->master_shell_code)->toBe('TPL-SHELL-MOTOR-ATTESTATION-001');
    // DOC-016 "FG-01–07, FG-12–15": the range dropped by the JSON conversion is re-expanded.
    $groups = json_decode(DB::table('document_canonical_specs')->where('spec_id', 'DOC-016')->value('field_group_refs'), true);
    expect(array_keys($groups))->toContain('FG-06', 'FG-07', 'FG-14');

    $ids = DB::table('document_canonical_specs')->pluck('id', 'spec_id');
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    expect(DB::table('document_canonical_specs')->pluck('id', 'spec_id')->all())->toEqual($ids->all());
});

it('REQ-DOC-CANON-002: security can never be downgraded', function () {
    expect(fn () => DB::table('document_types')->where('canonical_code', 'MOTOR_INSURANCE_ATTESTATION')->update(['security_tier' => 'S2']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('REQ-DOC-CANON-003: a missing required field blocks issuance with a clear error; no number is consumed', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-901-ZZ']);
    $f['quote']->update(['risk_facts' => ['registration_number' => 'LT-901-ZZ', 'make' => 'Toyota', 'model' => 'Yaris', 'usage' => 'PRIVATE']]); // no VIN
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION', 'PREMIUM_RECEIPT', 'POLICY_SCHEDULE']);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $att = docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0];
    expect($att['state'])->toBe('BLOCKED_MISSING_FIELDS')->and($att['missing_fields'])->toContain('risk.vin')->and($att['reason'])->toContain('VIN')
        ->and($att['document_id'])->toBeNull();
    // Receipt without a reconciled payment: never "PAID".
    expect(docItems($m, 'PREMIUM_RECEIPT')[0]['state'])->toBe('BLOCKED_MISSING_FIELDS');
    expect(Document::where('policy_id', $f['policy']->id)->where('document_type_code', 'MOTOR_INSURANCE_ATTESTATION')->count())->toBe(0);
});

it('REQ-DOC-CANON-004: issued documents carry tier, controls, snapshot, hashes, token; they are immutable snapshots', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-902-ZZ']);
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION']);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $att = Document::find(docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0]['document_id']);
    $raw = DB::table('documents')->where('id', $att->id)->first();
    $controls = json_decode($raw->security_controls, true);
    $snapshot = json_decode($raw->issuance_snapshot, true);
    expect($raw->security_tier)->toBe('S4')->and($raw->confidentiality_class)->toBe('PUBLIC_VERIFY')
        ->and($controls['qr']['status'])->toBe('APPLIED')->and($controls['seal']['status'])->toBe('APPLIED')
        ->and($controls['uv']['status'])->toBe('CONFIG_REQUIRED')->and($controls['signature']['status'])->toBe('CONFIG_REQUIRED')
        ->and($snapshot['fields']['risk.vin'])->toBe('VF1TESTVIN0000001')
        ->and($raw->snapshot_hash)->toBe(app(\App\Application\Shared\CanonicalJson::class)->hash($snapshot))
        ->and(strlen($raw->content_hash_sha256))->toBe(64)->and(VerificationCredentials::checksumValid($att->verification_code))->toBeTrue()
        ->and(json_decode($raw->signature, true)['status'])->toBe('CONFIG_REQUIRED');
    // Security graphics are in the PDF (vector SVG), and the QR carries no identity.
    $pdf = Storage::disk('local')->get($att->storage_key);
    expect(strlen($pdf))->toBeGreaterThan(1000);

    // Customer data changes later: the issued snapshot does not.
    $f['party']->update(['display_name' => 'Renamed Holder']);
    expect(json_decode(DB::table('documents')->where('id', $att->id)->value('issuance_snapshot'), true)['fields']['party.name'])->not->toBe('Renamed Holder');
    expect(fn () => DB::transaction(fn () => DB::table('documents')->where('id', $att->id)->update(['sha256' => str_repeat('0', 64)])))->toThrow(\Illuminate\Database\QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('documents')->where('id', $att->id)->delete()))->toThrow(\Illuminate\Database\QueryException::class);
    // Status changes (revocation) remain possible.
    DB::table('documents')->where('id', $att->id)->update(['status' => 'REVOKED', 'status_changed_at' => now()]);
    expect(Document::find($att->id)->status)->toBe('REVOKED');
});

it('REQ-DOC-CANON-005: token verification API, tamper detection and privacy-safe disclosure', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-903-ZZ']);
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION', 'POLICY_SCHEDULE']);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $att = Document::find(docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0]['document_id']);
    $schedule = Document::find(docItems($m, 'POLICY_SCHEDULE')[0]['document_id']);

    $this->getJson('/api/v1/public/verify-document/'.str_repeat('A', 22))->assertOk()->assertJsonPath('data.verification_result', 'INVALID_TOKEN');

    // Public proof: holder masked, policy masked, registration shown for roadside checks.
    $presenter = app(DocumentVerificationPresenter::class);
    $policy = Policy::with(['carrier.party', 'party', 'proposal.offer.product'])->find($f['policy']->id);
    $out = $presenter->publicPayload($att, $policy, $presenter->evaluate($att, $policy), 'x');
    expect($out['verification_result'])->toBeIn(['VALID', 'DEMO_VALID'])->and($out['document']['vehicle'])->toBe('LT-903-ZZ')
        ->and($out['document']['holder'])->not->toBe($f['party']->display_name)->and($out['document']['policy_reference'])->not->toBe($f['policy']->policy_number);
    // Field consistency: another document number presented with this QR -> POTENTIAL_TAMPERING.
    expect($presenter->evaluate($att, $policy, ['document_number' => 'ATT-MOT-FAKE'])['code'])->toBe('POTENTIAL_TAMPERING');
    // Customer-private: no holder, no policy, no product.
    $priv = $presenter->publicPayload($schedule, $policy, $presenter->evaluate($schedule, $policy), 'x');
    expect($priv['disclosure'])->toBe('MINIMAL')->and($priv['product_name'])->toBeNull()->and($priv['document']['policy_reference'])->toBeNull()->and($priv['document']['holder'])->toBeNull();
    // Restricted class never leaks dates, product or policy.
    DB::table('documents')->where('id', $schedule->id)->update(['status' => 'VALID']); // allowed column
    $medical = new Document($schedule->getAttributes());
    $medical->forceFill(['confidentiality_class' => 'MEDICAL_RESTRICTED']);
    $restricted = $presenter->publicPayload($medical, $policy, ['code' => 'VALID', 'legacy' => 'valid', 'disclosure' => DocumentVerificationPresenter::disclosure($medical), 'integrity' => []], 'x');
    expect($restricted['disclosure'])->toBe('RESTRICTED')->and($restricted['coverage_starts_at'])->toBeNull()->and($restricted['document']['valid_until'])->toBeNull()
        ->and($restricted['product_class'])->toBeNull()->and($restricted['document']['document_number'])->toStartWith('*');

    // Altered stored bytes -> HASH_MISMATCH.
    Storage::disk('local')->put($att->storage_key, 'tampered');
    expect($presenter->evaluate($att->refresh(), $policy)['code'])->toBe('HASH_MISMATCH');
});

it('REQ-DOC-CANON-006: signs with a configured platform key and verifies; refuses a key bound to another environment', function () {
    $kp = sodium_crypto_sign_keypair();
    config(['document_security.signing.private_key' => base64_encode(sodium_crypto_sign_secretkey($kp)), 'document_security.signing.key_id' => 'test-key-1', 'document_security.signing.key_environment' => 'production']);
    $signer = app(\App\Application\Documents\Security\DocumentSigner::class);
    expect($signer->configured())->toBeFalse(); // production key refused in testing
    config(['document_security.signing.key_environment' => app()->environment()]);
    $sig = $signer->sign(['final_file_hash' => str_repeat('a', 64), 'snapshot_hash' => 'b']);
    expect($sig['status'])->toBe('SIGNED')->and($signer->verify($sig))->toBe('VALID');
    $sig['payload']['final_file_hash'] = str_repeat('c', 64);
    expect($signer->verify($sig))->toBe('SIGNATURE_INVALID');
    $this->getJson('/api/v1/public/document-signing-keys')->assertOk()->assertJsonPath('data.keys.test-key-1', base64_encode(sodium_crypto_sign_publickey($kp)));
});
