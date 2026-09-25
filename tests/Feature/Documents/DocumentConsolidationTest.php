<?php

declare(strict_types=1);

/*
 | Batch 3C — document model consolidation.
 | REQ-DUP-004 (one catalogue), REQ-DUP-005 (certificates are engine document
 | types), REQ-DUP-021 (documents canonical, link tables), REQ-SET-006
 | (server-side numbering) + customer notification on generated documents.
 */

use App\Application\Certificates\CertificateService;
use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Engine\DocumentNumberAllocator;
use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Documents\Engine\DocumentTemplateService;
use App\Application\Documents\SubjectDocuments;
use App\Models\CertificateTemplate;
use App\Models\Document;
use App\Models\DocumentIssuanceProfile;
use App\Models\DocumentNumberingFamily;
use App\Models\DocumentTemplate;
use App\Models\Policy;
use App\Models\PolicyCertificate;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function b3cUser(): User
{
    return User::create(['full_name' => 'B3C Staff '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function b3cPolicy(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['product']->update(['line_code' => 'AUTO']);
    $f['quote']->update(['line_code' => 'AUTO', 'risk_facts' => ['registration_number' => 'LT-777-CM', 'vin' => 'VF1TESTVIN0000002', 'make' => 'Toyota', 'model' => 'Corolla', 'usage' => 'PRIVATE']]);
    \App\Models\QuoteOffer::whereKey($f['proposal']->quote_offer_id)->update(['tax_minor' => 0, 'fee_minor' => 0]);
    $f['policy'] = Policy::create([
        'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subDay(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => ['line_code' => 'AUTO', 'coverage_snapshot' => ['coverages' => [['code' => 'RC', 'name' => 'Third-party liability', 'limit_minor' => 500000000, 'deductible_minor' => 0]]]], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now(),
    ]);

    return $f;
}

beforeEach(function () {
    $root = storage_path('framework/testing/disks/b3c-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
});

it('REQ-DUP-004: the catalogue is the single definition source and the engine reads it', function () {
    $types = app(DocumentRegister::class)->types();
    expect(count($types))->toBe(DB::table('document_types')->distinct()->count('canonical_code'))
        ->and(app(\App\Application\Documents\Engine\CatalogueSource::class)->fromDatabase())->toBeTrue();

    // No parallel definition: no reader of product_document_rules or of the owner's register JSON in the engine.
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'product_document_rules')) {
            $offenders[] = $file->getPathname();
        }
    }
    expect($offenders)->toBe([])
        ->and(file_get_contents(app_path('Application/Documents/Engine/DocumentRegister.php')))->not->toContain('file_get_contents');
});

it('REQ-SET-006: policy numbers come from the tenant numbering family, gap-free and never duplicated', function () {
    $f = b3cPolicy();
    DocumentNumberingFamily::create(['tenant_id' => $f['tenant']->id, 'family_code' => 'POL', 'prefix' => 'AXA-MOT', 'include_year' => true, 'pad' => 6, 'status' => 'ACTIVE']);
    $alloc = app(DocumentNumberAllocator::class);
    $year = now()->format('Y');

    expect($alloc->allocatePolicyNumber($f['tenant']->id, $f['carrier']->id))->toBe("AXA-MOT-{$year}-000001");
    // A legacy policy already holding the next number is skipped.
    $f['policy']->update(['policy_number' => "AXA-MOT-{$year}-000002"]);
    expect($alloc->allocatePolicyNumber($f['tenant']->id, $f['carrier']->id))->toBe("AXA-MOT-{$year}-000003");

    // Issuance never takes a caller-supplied number.
    expect(file_get_contents(app_path('Application/Policies/PolicyIssuanceService.php')))->toContain('allocatePolicyNumber(')->not->toContain("\$data['policy_number']")
        ->and(file_get_contents(app_path('Interfaces/Http/Controllers/Api/V1/Policies/PolicyController.php')))->toContain('unset($d[\'policy_number\'])');
});

it('REQ-DUP-005: a certificate is issued as an engine document; policy_certificates is not written; serial+token verifies', function () {
    $f = b3cPolicy();
    $actor = b3cUser();
    $svc = app(CertificateService::class);
    $before = PolicyCertificate::count();

    $r = $svc->issue($f['policy'], null, ['serial_number' => 'SER-B3C-1', 'document_hash' => str_repeat('a', 64), 'document_type_code' => 'MOTOR_INSURANCE_CERTIFICATE'], $actor);

    /** @var Document $doc */
    $doc = $r['certificate'];
    expect($doc)->toBeInstanceOf(Document::class)
        ->and($doc->document_type_code)->toBe('MOTOR_INSURANCE_CERTIFICATE')
        ->and($doc->status)->toBe('VALID')
        ->and($doc->document_number)->toStartWith('ATT-MOT-')
        ->and($doc->verification_code)->not->toBeNull()
        ->and(PolicyCertificate::count())->toBe($before);

    // The certificate PDF is rendered, stored and served by the signed download endpoint.
    expect($doc->size_bytes)->toBeGreaterThan(0)->and($doc->provenance['external_document_hash'])->toBe(str_repeat('a', 64));
    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('mobile.policy-documents.download', now()->addMinutes(5), ['document' => $doc->id]);
    $res = $this->get($url);
    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('application/pdf')
        ->and(hash('sha256', $res->streamedContent()))->toBe($doc->sha256);

    $ok = $svc->verify('SER-B3C-1', $r['verification_token'], 'fp');
    expect($ok['document_id'])->toBe($doc->id)->and($ok['policy']->id)->toBe($f['policy']->id);
    expect(fn () => $svc->verify('SER-B3C-1', str_repeat('z', 64), 'fp'))->toThrow(ValidationException::class);

    // A second certificate of the same kind supersedes the first (engine lifecycle).
    $svc->issue($f['policy'], null, ['serial_number' => 'SER-B3C-2', 'document_hash' => str_repeat('b', 64), 'document_type_code' => 'MOTOR_INSURANCE_CERTIFICATE'], $actor);
    expect($doc->refresh()->status)->toBe('SUPERSEDED');
    expect(fn () => $svc->verify('SER-B3C-1', $r['verification_token'], 'fp'))->toThrow(ValidationException::class);

    // Duplicate serial and non-certificate types are refused.
    expect(fn () => $svc->issue($f['policy'], null, ['serial_number' => 'SER-B3C-2', 'document_hash' => str_repeat('c', 64)], $actor))->toThrow(ValidationException::class)
        ->and(fn () => $svc->issue($f['policy'], null, ['serial_number' => 'SER-B3C-3', 'document_hash' => str_repeat('c', 64), 'document_type_code' => 'INSURANCE_POLICY'], $actor))->toThrow(ValidationException::class);
});

it('REQ-DUP-005: certificate templates are read-only history; legacy certificates still verify', function () {
    $f = b3cPolicy();
    $actor = b3cUser();
    $svc = app(CertificateService::class);
    expect(fn () => $svc->createTemplate(['type' => 'MOTOR', 'code' => 'X', 'layout_schema' => [], 'effective_from' => '2026-01-01'], $actor))->toThrow(ValidationException::class);

    $t = CertificateTemplate::create(['type' => 'MOTOR', 'code' => 'LEGACY-B3C', 'version' => 1, 'status' => 'ACTIVE', 'template_hash' => str_repeat('0', 64), 'layout_schema' => [], 'effective_from' => '2020-01-01']);
    $token = Str::random(64);
    PolicyCertificate::create(['policy_id' => $f['policy']->id, 'certificate_template_id' => $t->id, 'serial_number' => 'LEG-B3C-1', 'verification_token_hash' => hash('sha256', $token),
        'document_hash' => str_repeat('d', 64), 'status' => 'VALID', 'issued_at' => now(), 'issued_by' => $actor->id]);

    expect($svc->verify('LEG-B3C-1', $token, 'fp')['document_id'])->toBeNull();

    // Legacy template type maps onto the catalogue certificate type.
    expect($svc->typeFor(null, $t)['code'])->toBe('MOTOR_INSURANCE_CERTIFICATE');
});

it('REQ-DUP-021: documents are canonical; subject link tables stamp stage and read through SubjectDocuments', function () {
    $f = b3cPolicy();
    $doc = Document::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'category' => 'ID_CARD', 'storage_key' => 'k/'.Str::random(6), 'mime_type' => 'application/pdf',
        'size_bytes' => 10, 'sha256' => str_repeat('e', 64), 'ocr_data' => []]);
    DB::table('proposal_documents')->insert(['proposal_id' => $f['proposal']->id, 'document_id' => $doc->id, 'requirement_code' => 'NATIONAL_ID', 'status' => 'SUBMITTED']);

    expect($doc->refresh()->document_stage)->toBe('UNDERWRITING')
        ->and($doc->document_origin)->toBe('CUSTOMER');

    $rows = app(SubjectDocuments::class)->forSubject('PROPOSAL', $f['proposal']->id);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->document_id)->toBe($doc->id)
        ->and($rows[0]->role)->toBe('NATIONAL_ID')
        ->and($rows[0]->status)->toBe('SUBMITTED')
        ->and($rows[0]->sha256)->toBe(str_repeat('e', 64));

    // Old link tables are kept (no data moved out of them).
    expect(DB::table('proposal_documents')->where('document_id', $doc->id)->exists())->toBeTrue();
    expect(fn () => app(SubjectDocuments::class)->forSubject('NOPE', 'x'))->toThrow(InvalidArgumentException::class);
});

it('notifies the customer when the engine generates new documents', function () {
    $f = b3cPolicy();
    DocumentIssuanceProfile::create(['carrier_id' => $f['carrier']->id, 'issuance_mode' => 'OPES_GENERATED', 'opes_rendering_authorized' => true, 'authorization_reference' => 'AUTH-B3C', 'default_language' => 'BILINGUAL']);
    $d = ['document_type_code' => 'INSURANCE_POLICY', 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL'];
    $t = DocumentTemplate::create([
        'code' => DocumentTemplateService::lineage($d), 'document_type_code' => 'INSURANCE_POLICY', 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL', 'version' => 1, 'status' => 'PUBLISHED',
        'title_en' => 'Policy', 'title_fr' => 'Police', 'content' => ['sections' => [['heading_en' => 'T', 'heading_fr' => 'T', 'body_en' => 'Policy {policy_number}', 'body_fr' => 'Police {policy_number}']]],
        'content_hash' => 'x', 'effective_from' => now()->subYear()->toDateString(), 'created_by' => b3cUser()->id,
    ]);
    $t->update(['content_hash' => app(DocumentTemplateService::class)->hash($t)]);

    $manifest = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    expect(collect($manifest->items)->where('state', 'GENERATED')->count())->toBeGreaterThan(0);

    $n = UserNotification::where('user_id', $f['user']->id)->where('type', 'DOCUMENT')->get();
    expect($n)->toHaveCount(1)->and($n[0]->path)->toBe('/policy/'.$f['policy']->id)->and($n[0]->body)->toContain($f['policy']->policy_number);

    // Idempotent re-fire: no second notification.
    app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    expect(UserNotification::where('user_id', $f['user']->id)->where('type', 'DOCUMENT')->count())->toBe(1);
});
