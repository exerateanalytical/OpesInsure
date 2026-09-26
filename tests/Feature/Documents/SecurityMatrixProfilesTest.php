<?php

declare(strict_types=1);

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Security\DocumentVerificationPresenter;
use App\Application\Documents\Security\PhysicalSecurityRegistry;
use App\Application\Documents\Security\SecurityMatrix;
use App\Filament\Admin\Resources\PhysicalSecurityAssets\PhysicalSecurityAssetResource;
use App\Models\Document;
use App\Models\DocumentSecurity\PhysicalSecurityAsset;
use App\Models\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/document_engine_helpers.php';

/*
 | Security Matrix v1 §4–11 (work item D1): REQ-DOC-SECMX-*.
 */

beforeEach(function () {
    $root = storage_path('framework/testing/disks/secmx-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
});

it('REQ-DOC-SECMX-001: parses §4–11 from the markdown source into the spec dictionary, idempotently', function () {
    $count = fn (string $kind) => DB::table('document_spec_dictionary')->where('kind', $kind)->count();
    expect($count('PHYSICAL_PROFILE'))->toBe(4)->and($count('WATERMARK_PROFILE'))->toBe(6)->and($count('SEAL_PROFILE'))->toBe(8)
        ->and($count('VERIFICATION_RULE'))->toBe(1)->and($count('REVOCATION_RULE'))->toBe(1)->and($count('ISSUANCE_GATE_STEP'))->toBe(15)
        ->and($count('DEVELOPER_INSTRUCTION'))->toBe(1)->and($count('SECURITY_PRINCIPLE'))->toBe(10);
    $ps04 = json_decode(DB::table('document_spec_dictionary')->where(['kind' => 'PHYSICAL_PROFILE', 'code' => 'PS-04'])->value('payload'), true);
    expect(array_keys($ps04['recommended_for']))->toContain('DOC-036', 'DOC-037', 'DOC-055', 'DOC-138', 'DOC-139')
        ->and($ps04['status'])->toBe('CONFIG_REQUIRED')->and($ps04['controls'])->toContain('custodian');
    $rule = json_decode(DB::table('document_spec_dictionary')->where(['kind' => 'VERIFICATION_RULE'])->value('payload'), true);
    expect($rule['never_public'])->toContain('medical details', 'KYC data', 'beneficiary allocations');
    $status = json_decode(DB::table('document_spec_dictionary')->where(['kind' => 'WATERMARK_PROFILE', 'code' => 'WM-STATUS'])->value('payload'), true);
    expect($status['elements'])->toContain('DEMONSTRATION', 'REVOKED', 'REVERSED');
    expect(DB::table('document_spec_dictionary')->where(['kind' => 'SEAL_PROFILE', 'code' => 'SEAL-08'])->value('name'))->toBe('REVOKED');

    $ids = DB::table('document_spec_dictionary')->whereIn('kind', ['PHYSICAL_PROFILE', 'SEAL_PROFILE'])->pluck('id', 'code');
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    expect(DB::table('document_spec_dictionary')->whereIn('kind', ['PHYSICAL_PROFILE', 'SEAL_PROFILE'])->pluck('id', 'code')->all())->toEqual($ids->all());
});

it('REQ-DOC-SECMX-002: assigns watermark, seal and physical profiles to canonical specs and catalogue types', function () {
    $spec = fn (string $id) => DB::table('document_canonical_specs')->where('spec_id', $id)->first();
    $att = $spec('DOC-036');
    expect($att->watermark_profile_code)->toBe('WM-PUBLIC-PROOF')
        ->and(json_decode($att->seal_profile_codes, true))->toContain('SEAL-01', 'SEAL-02')
        ->and(json_decode($att->physical_profile_codes, true))->toBe(['PS-01', 'PS-02', 'PS-03', 'PS-04']);
    expect($spec('DOC-001')->physical_profile_codes)->toBe('[]');
    expect($spec('DOC-064')->watermark_profile_code)->toBe('WM-MEDICAL');
    expect(json_decode($spec('DOC-198')->seal_profile_codes, true))->toContain('SEAL-03');
    expect(DB::table('document_canonical_specs')->whereNull('security_profile_assignment')->count())->toBe(0);

    $type = DB::table('document_types')->where('canonical_code', 'MOTOR_INSURANCE_ATTESTATION')->first();
    expect($type->watermark_profile_code)->toBe('WM-PUBLIC-PROOF')->and(json_decode($type->physical_profile_codes, true))->toContain('PS-04');

    // An admin-set watermark profile is kept; seals are only ever extended.
    DB::table('document_types')->where('type_id', $type->type_id)->update(['watermark_profile_code' => 'WM-FINANCE', 'seal_profile_codes' => json_encode(['SEAL-06'])]);
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    $after = DB::table('document_types')->where('type_id', $type->type_id)->first();
    expect($after->watermark_profile_code)->toBe('WM-FINANCE')->and(json_decode($after->seal_profile_codes, true))->toContain('SEAL-06', 'SEAL-01', 'SEAL-02');
});

it('REQ-DOC-SECMX-003: renders profiles at issuance; physical controls stay CONFIG_REQUIRED; SEAL-01 needs verified artwork', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-911-ZZ']);
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION']);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $att = Document::find(docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0]['document_id']);
    $controls = json_decode(DB::table('documents')->where('id', $att->id)->value('security_controls'), true);
    expect($controls['watermark']['profile']['code'])->toBe('WM-PUBLIC-PROOF')
        ->and($controls['seal']['profiles']['SEAL-02']['status'])->toBe('APPLIED')
        ->and($controls['seal']['profiles']['SEAL-01']['status'])->toBe('CONFIG_REQUIRED')
        ->and($controls['seal']['profiles']['SEAL-03']['status'])->toBe('NOT_APPLICABLE')
        ->and($controls['uv']['status'])->toBe('CONFIG_REQUIRED')
        ->and($controls['uv']['profiles']['PS-04']['status'])->toBe('CONFIG_REQUIRED');

    // Owner records and (a different admin) verifies corporate seal artwork: SEAL-01 becomes applicable.
    $recorder = docUser();
    PhysicalSecurityAsset::create(['asset_kind' => 'SEAL_ARTWORK', 'seal_profile_code' => 'SEAL-01', 'name' => 'Corporate seal', 'status' => 'VERIFIED',
        'artwork_sha256' => str_repeat('a', 64), 'recorded_by' => $recorder->id, 'verified_by' => (string) Str::uuid(), 'verified_at' => now()]);
    expect(PhysicalSecurityRegistry::corporateSealArtwork($f['policy']->carrier_id))->toBeTrue();
    // Physical assets alone never switch on physical issuance.
    PhysicalSecurityAsset::create(['asset_kind' => 'SECURE_STOCK_BATCH', 'physical_profile_code' => 'PS-04', 'name' => 'Batch 1', 'status' => 'VERIFIED', 'verified_at' => now(),
        'serial_prefix' => 'ATT', 'serial_from' => 1, 'serial_to' => 1000, 'quantity_received' => 1000]);
    expect(PhysicalSecurityRegistry::profileStates(['PS-04'])['PS-04'])->toMatchArray(['status' => 'CONFIG_REQUIRED', 'verified_assets' => 1]);
});

it('REQ-DOC-SECMX-004: public verification exposes only §7 privacy-safe fields on every channel', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-912-ZZ']);
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION', 'POLICY_SCHEDULE']);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $schedule = Document::find(docItems($m, 'POLICY_SCHEDULE')[0]['document_id']);

    // Reference lookup (the single PublicVerificationService) now uses the presenter: a customer-private document
    // no longer discloses product, vehicle or policy.
    $out = app(\App\Application\Documents\Verification\PublicVerificationService::class)->lookup($schedule->verification_code, null, 'API', 'fp');
    expect($out['disclosure'])->toBe('MINIMAL')->and($out['product_name'])->toBeNull()->and($out['document']['vehicle'])->toBeNull()
        ->and($out['document']['policy_reference'])->toBeNull()->and($out['status'])->toBe('VALID')
        ->and(SecurityMatrix::forbiddenKeys($out))->toBe([]);

    // Whatever a caller adds, forbidden categories never pass the sanitizer.
    $clean = SecurityMatrix::sanitizePublic(['result' => 'valid', 'diagnosis' => 'x', 'document' => ['document_number' => 'N', 'beneficiary_allocations' => [1], 'iban' => 'CM21']]);
    expect($clean)->toBe(['result' => 'valid', 'document' => ['document_number' => 'N']]);
});

it('REQ-DOC-SECMX-005: revoked documents keep verifying as REVOKED with SEAL-08; replacement keeps the original history', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-913-ZZ']);
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION']);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $att = Document::find(docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0]['document_id']);
    $admin = docUser();
    DB::table('documents')->where('id', $att->id)->update(['status' => 'REVOKED', 'status_changed_at' => now(), 'status_changed_by' => $admin->id, 'status_reason' => 'Internal investigation finding']);

    $presenter = app(DocumentVerificationPresenter::class);
    $policy = Policy::with(['carrier.party', 'party', 'proposal.offer.product'])->find($f['policy']->id);
    $att->refresh();
    $out = $presenter->publicPayload($att, $policy, $presenter->evaluate($att, $policy), 'x');
    expect($out['verification_result'])->toBe('REVOKED')->and($out['status_seal'])->toBe('SEAL-08')
        ->and($out['lifecycle']['verifies_as'])->toBe('REVOKED')->and(json_encode($out))->not->toContain('investigation');

    $record = $presenter->revocationRecord($att, $policy);
    expect($record)->toHaveKeys(['original_document_id', 'status', 'superseded_by', 'replacement_of', 'duplicate_of', 'revoked_at', 'revoked_by', 'revocation_reason', 'replacement_reason', 'verification_result'])
        ->and($record['revoked_by'])->toBe($admin->id)->and($record['verification_result'])->toBe('REVOKED');
    expect(Document::find($att->id))->not->toBeNull(); // never erased

    expect(SecurityMatrix::statusOverlay('REPLACED'))->toBe('SUPERSEDED')->and(SecurityMatrix::statusOverlay(null, true))->toBe('DEMONSTRATION')
        ->and(SecurityMatrix::statusOverlay('ISSUED', false, true))->toBe('DUPLICATE');
});

it('REQ-DOC-SECMX-006: physical security asset admin guard (maker-checker, custody) and data readiness gate', function () {
    $maker = docUser();
    $this->actingAs($maker);
    expect(fn () => PhysicalSecurityAssetResource::prepare(['asset_kind' => 'PRINT_SUPPLIER', 'name' => 'X', 'status' => 'VERIFIED']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => PhysicalSecurityAssetResource::prepare(['asset_kind' => 'SECURE_STOCK_BATCH', 'name' => 'X', 'status' => 'PENDING_VERIFICATION', 'quantity_received' => 5, 'quantity_issued' => 6]))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    $asset = PhysicalSecurityAsset::create(['recorded_by' => $maker->id] + PhysicalSecurityAssetResource::prepare(['asset_kind' => 'PRINT_SUPPLIER', 'name' => 'Supplier', 'supplier_name' => 'PENDING', 'status' => 'PENDING_VERIFICATION']));
    expect(fn () => PhysicalSecurityAssetResource::prepare(['status' => 'VERIFIED'], $asset, $maker->id))->toThrow(\Illuminate\Validation\ValidationException::class);

    $rows = collect(app(DataReadinessRegistry::class)->domain('documents'))->keyBy('item');
    expect($rows['physical_security_print_supplier']['status'])->toBe('CONFIG_REQUIRED')->and($rows['physical_security_print_supplier']['production_usable'])->toBeFalse();

    $checker = docUser();
    $asset->update(PhysicalSecurityAssetResource::prepare(['status' => 'VERIFIED'], $asset, $checker->id));
    expect($asset->refresh()->verified_by)->toBe($checker->id);
    $rows = collect(app(DataReadinessRegistry::class)->domain('documents'))->keyBy('item');
    expect($rows['physical_security_print_supplier']['status'])->toBe('VERIFIED');
});

it('REQ-DOC-SECMX-007: records the §9 15-step issuance gate on every issued document', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-914-ZZ']);
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION']);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $id = docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0]['document_id'];
    $gate = json_decode(DB::table('documents')->where('id', $id)->value('security_controls'), true)['issuance_gate'];
    expect($gate['steps'])->toHaveCount(15)->and($gate['failed'])->toBe([])->and($gate['deferred'])->toBe([]);
    foreach (['GATE-01', 'GATE-02', 'GATE-03', 'GATE-04', 'GATE-05', 'GATE-07', 'GATE-08', 'GATE-09', 'GATE-12', 'GATE-13', 'GATE-14', 'GATE-15'] as $step) {
        expect($gate['steps'][$step]['status'])->toBe('PASS');
    }
    // No signing key / no verified corporate seal artwork in tests: step 10 is CONFIG_REQUIRED, never faked.
    expect($gate['steps']['GATE-10']['status'])->toBe('CONFIG_REQUIRED')->and($gate['steps']['GATE-10']['reason'])->toContain('CORPORATE_SEAL_ARTWORK_NOT_VERIFIED')
        ->and($gate['steps']['GATE-11']['status'])->toBe('NOT_APPLICABLE');
});

it('REQ-DOC-SECMX-008: CONFIG_REQUIRED gate steps block only under DOCUMENT_ENFORCE_CONTROLS; a FAIL always blocks with a coded reason', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-915-ZZ']);
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION']);
    config(['document_security.enforce_controls' => true]);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $item = docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0];
    expect($item['state'])->toBe('BLOCKED_ISSUANCE_GATE')->and(implode(' ', $item['missing_fields']))->toContain('GATE-10:SEAL_SIGNATURE_AUTHORITY_VALID')
        ->and($item['document_id'])->toBeNull();

    // Pure evaluator: a voided source and an inactive template FAIL regardless of enforcement.
    $policy = $f['policy']->replicate()->forceFill(['status' => 'VOID']);
    $policy->exists = true;
    $template = \App\Models\DocumentTemplate::where('document_type_code', 'MOTOR_INSURANCE_ATTESTATION')->first()->replicate()->forceFill(['status' => 'RETIRED']);
    $security = ['tier' => 'S2', 'controls' => ['maker_checker' => ['status' => 'NOT_APPLICABLE'], 'signature' => ['status' => 'NOT_APPLICABLE'], 'seal' => ['status' => 'NOT_APPLICABLE']]];
    $gate = \App\Application\Documents\Security\IssuanceGate::evaluate($policy, $template, $security, ['issuer' => 'PLATFORM', 'issuer_authorized' => true, 'missing_fields' => [], 'field_enforcement' => 'block']);
    $refused = \App\Application\Documents\Security\IssuanceGate::refusals($gate, false);
    expect($refused)->toContain('GATE-02:SOURCE_STATUS_PERMITS_ISSUANCE:SOURCE_STATUS_VOID')
        ->and(implode(' ', $refused))->toContain('GATE-04:TEMPLATE_VERSION_ACTIVE:TEMPLATE_NOT_ACTIVE');
    // Missing fields in "record" mode are PENDING_VERIFICATION: they block only under enforcement.
    $rec = \App\Application\Documents\Security\IssuanceGate::evaluate($f['policy'], $template->forceFill(['status' => 'PUBLISHED']), $security, ['issuer' => 'PLATFORM', 'issuer_authorized' => true, 'missing_fields' => ['risk.vin'], 'field_enforcement' => 'record']);
    expect($rec['steps']['GATE-05']['status'])->toBe('PENDING_VERIFICATION')
        ->and(\App\Application\Documents\Security\IssuanceGate::refusals($rec, false))->toBe([])
        ->and(\App\Application\Documents\Security\IssuanceGate::refusals($rec, true))->toBe(['GATE-05:REQUIRED_FIELDS_COMPLETE:MISSING_FIELDS:risk.vin']);
});

it('REQ-DOC-SECMX-009: provider contract, tariff and settlement documents (no policy) pass gate step 1 through their provider source', function () {
    $template = new \App\Models\DocumentTemplate(['code' => 'TPL-PROVIDER-CONTRACT-TEST', 'version' => 1, 'status' => 'PUBLISHED', 'effective_from' => now()->subDay()->toDateString()]);
    $security = ['tier' => 'S4', 'controls' => ['maker_checker' => ['status' => 'NOT_APPLICABLE'], 'signature' => ['status' => 'NOT_APPLICABLE'], 'seal' => ['status' => 'NOT_APPLICABLE']]];
    $in = ['issuer' => 'INSURER', 'issuer_authorized' => true, 'missing_fields' => [], 'field_enforcement' => 'block'];
    foreach (['provider-contract:'.Str::uuid(), 'provider-tariff:'.Str::uuid(), 'provider-settlement:'.Str::uuid()] as $source) {
        $gate = \App\Application\Documents\Security\IssuanceGate::evaluate(new Policy(), $template, $security, $in + ['provider_source' => $source]);
        expect($gate['steps']['GATE-01']['status'])->toBe('PASS')->and(\App\Application\Documents\Security\IssuanceGate::refusals($gate, false))->toBe([]);
    }
    // Without a policy or a provider source, step 1 fails with a coded reason.
    $none = \App\Application\Documents\Security\IssuanceGate::evaluate(new Policy(), $template, $security, $in);
    expect(\App\Application\Documents\Security\IssuanceGate::refusals($none, false))->toBe(['GATE-01:SOURCE_TRANSACTION_EXISTS:SOURCE_POLICY_MISSING']);
});
