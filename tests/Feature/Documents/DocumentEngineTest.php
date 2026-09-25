<?php

declare(strict_types=1);

use App\Application\Catalogue\CatalogueService;
use App\Application\Documents\Engine\CarrierDocumentService;
use App\Application\Documents\Engine\DocumentEngine;
use App\Application\Documents\Engine\DocumentNumberAllocator;
use App\Application\Documents\Engine\DocumentPackResolver;
use App\Application\Documents\Engine\DocumentStatusService;
use App\Application\Documents\Engine\DocumentTemplateService;
use App\Application\Documents\Engine\ProductDocumentGate;
use App\Models\Claim;
use App\Models\Document;
use App\Models\DocumentIssuanceProfile;
use App\Models\DocumentNumberingFamily;
use App\Models\DocumentTemplate;
use App\Models\Policy;
use App\Models\PolicyTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function docUser(): User
{
    return User::create(['full_name' => 'Doc Staff '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

/** An issued in-force policy of the given line with optional risk facts. */
function docPolicy(string $line = 'AUTO', array $facts = [], ?array $fixture = null): array
{
    $f = $fixture ?? makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    // Fixture only: published versions are frozen (REQ-PRD-001 trigger), so re-line it as a draft and republish.
    $status = $f['product']->status;
    $f['product']->update(['status' => 'DRAFT']);
    $f['product']->update(['line_code' => $line]);
    $f['product']->update(['status' => $status]);
    $f['quote']->update(['line_code' => $line, 'risk_facts' => $facts]);
    $policy = Policy::create([
        'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subDay(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => ['line_code' => $line], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now(),
    ]);
    $f['policy'] = $policy;

    return $f;
}

function docAuthorize(array $f, array $overrides = []): DocumentIssuanceProfile
{
    return DocumentIssuanceProfile::create(array_merge(['carrier_id' => $f['carrier']->id, 'issuance_mode' => 'OPES_GENERATED', 'opes_rendering_authorized' => true, 'authorization_reference' => 'AUTH-1', 'default_language' => 'BILINGUAL'], $overrides));
}

/** Published template shortcut for fixtures (the workflow itself is tested separately). */
function docTemplate(string $code, array $o = []): DocumentTemplate
{
    $author = $o['created_by'] ?? docUser()->id;
    $d = array_merge(['document_type_code' => $code, 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL', 'content' => ['sections' => [['heading_en' => 'Terms', 'heading_fr' => 'Conditions', 'body_en' => 'Policy {policy_number}', 'body_fr' => 'Police {policy_number}']]]], $o);
    $svc = app(DocumentTemplateService::class);
    $t = DocumentTemplate::create([
        'code' => DocumentTemplateService::lineage($d), 'document_type_code' => $code, 'ownership' => $d['ownership'], 'carrier_id' => $d['carrier_id'] ?? null,
        'broker_tenant_id' => $d['broker_tenant_id'] ?? null, 'product_id' => $d['product_id'] ?? null, 'insurance_class' => $d['insurance_class'] ?? null,
        'language' => $d['language'], 'version' => $d['version'] ?? 1, 'status' => 'PUBLISHED', 'title_en' => $code, 'title_fr' => $code, 'content' => $d['content'],
        'content_hash' => 'x', 'effective_from' => now()->subYear()->toDateString(), 'created_by' => $author,
    ]);
    $t->update(['content_hash' => $svc->hash($t)]);

    return $t->refresh();
}

function docTemplates(array $codes, array $o = []): void
{
    foreach ($codes as $c) {
        docTemplate($c, $o);
    }
}

function docItems($manifest, ?string $code = null): array
{
    return array_values(array_filter($manifest->items, fn ($i) => $code === null || $i['document_type_code'] === $code));
}

beforeEach(function () {
    // Isolated disk root: Storage::fake('local') shares one directory with concurrently running suites.
    $root = storage_path('framework/testing/disks/docengine-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));
    // The engine reads the single document catalogue (REQ-DUP-004): seed its tables.
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
});

it('resolves the class pack per insurance class from the register (motor, fleet, group health, life, cargo, travel)', function () {
    $r = app(DocumentPackResolver::class);

    $motor = $r->resolve(docPolicy('AUTO', ['registration_number' => 'LT-123-AA'])['policy'], 'POLICY_ISSUED');
    expect($motor['pack_code'])->toBe('MOTOR_NEW_BUSINESS_PACK')
        ->and(array_column($motor['items'], 'document_type_code'))->toContain('INSURANCE_POLICY', 'POLICY_SCHEDULE', 'GENERAL_CONDITIONS', 'MOTOR_INSURANCE_ATTESTATION', 'MOTOR_INSURANCE_CERTIFICATE', 'PREMIUM_RECEIPT');

    $fleet = $r->resolve(docPolicy('AUTO', ['vehicles' => [['registration_number' => 'A1'], ['registration_number' => 'A2']]])['policy'], 'POLICY_ISSUED');
    expect($fleet['insurance_class'])->toBe('FLEET')
        ->and(array_column($fleet['items'], 'document_type_code'))->toContain('MASTER_GROUP_POLICY', 'FLEET_VEHICLE_SCHEDULE')
        ->and(collect($fleet['items'])->firstWhere('document_type_code', 'MOTOR_INSURANCE_ATTESTATION')['per_subject'])->toBe('VEHICLE');

    $group = $r->resolve(docPolicy('HEALTH', ['members' => [['member_id' => 'E1', 'full_name' => 'A']]])['policy'], 'POLICY_ISSUED');
    expect($group['insurance_class'])->toBe('CORPORATE_HEALTH')
        ->and(collect($group['items'])->firstWhere('document_type_code', 'MEMBER_CERTIFICATE')['per_subject'])->toBe('MEMBER');

    $life = $r->resolve(docPolicy('LIFE')['policy'], 'POLICY_ISSUED');
    expect($life['pack_code'])->toBe('LIFE_NEW_BUSINESS_PACK')->and(array_column($life['items'], 'document_type_code'))->toContain('LIFE_CONTRACT_SUMMARY', 'BENEFICIARY_NOMINATION', 'LIFE_POLICY_SCHEDULE');

    $cargo = $r->resolve(docPolicy('MARINE', ['shipments' => [['shipment_reference' => 'BL-1']]])['policy'], 'POLICY_ISSUED');
    expect(collect($cargo['items'])->firstWhere('document_type_code', 'SHIPMENT_INSURANCE_CERTIFICATE')['per_subject'])->toBe('SHIPMENT');

    $travel = $r->resolve(docPolicy('TRAVEL')['policy'], 'POLICY_ISSUED');
    expect(array_column($travel['items'], 'document_type_code'))->toContain('CERTIFICATE_OF_INSURANCE');

    // Insurer adaptation from the catalogue (approved MATRIX_OVERRIDE): SPECIAL_CONDITIONS becomes mandatory.
    $f = docPolicy('AUTO');
    expect(collect($r->resolve($f['policy'], 'POLICY_ISSUED')['items'])->firstWhere('document_type_code', 'SPECIAL_CONDITIONS')['mode'])->toBe('CONDITIONAL');
    DB::table('product_document_requirements')->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $f['product']->id, 'kind' => 'PRODUCT_TYPE', 'product_type_code' => 'MOTOR_TPL', 'variant_code' => '', 'status' => 'ACTIVE', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('product_document_requirements')->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $f['product']->id, 'kind' => 'MATRIX_OVERRIDE', 'document_type_id' => 'DOC-024', 'stage' => 'ISSUANCE', 'variant_code' => '', 'level' => 'M', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $item = collect(app(DocumentPackResolver::class)->resolve($f['policy'], 'POLICY_ISSUED')['items'])->firstWhere('document_type_code', 'SPECIAL_CONDITIONS');
    expect($item['mode'])->toBe('GENERATE')->and($item['required_level'])->toBe('REQUIRED');
});

it('resolves templates insurer > broker > regulatory > platform, prefers the language, and requires life-specific templates for life', function () {
    $f = docPolicy('AUTO');
    $svc = app(DocumentTemplateService::class);
    $ctx = ['carrier_id' => $f['carrier']->id, 'tenant_id' => $f['tenant']->id, 'broker' => true, 'product_id' => $f['product']->id, 'insurance_class' => 'MOTOR'];

    $platform = docTemplate('POLICY_SCHEDULE');
    expect($svc->resolve('POLICY_SCHEDULE', $ctx)->id)->toBe($platform->id);
    $regulatory = docTemplate('POLICY_SCHEDULE', ['ownership' => 'REGULATORY']);
    expect($svc->resolve('POLICY_SCHEDULE', $ctx)->id)->toBe($regulatory->id);
    $broker = docTemplate('POLICY_SCHEDULE', ['ownership' => 'BROKER', 'broker_tenant_id' => $f['tenant']->id]);
    expect($svc->resolve('POLICY_SCHEDULE', $ctx)->id)->toBe($broker->id);
    $insurerFr = docTemplate('POLICY_SCHEDULE', ['ownership' => 'INSURER', 'carrier_id' => $f['carrier']->id, 'language' => 'FR']);
    $insurerEn = docTemplate('POLICY_SCHEDULE', ['ownership' => 'INSURER', 'carrier_id' => $f['carrier']->id, 'language' => 'EN']);
    expect($svc->resolve('POLICY_SCHEDULE', $ctx, 'FR')->id)->toBe($insurerFr->id)
        ->and($svc->resolve('POLICY_SCHEDULE', $ctx, 'EN')->id)->toBe($insurerEn->id);

    // Another insurer's contractual template never applies.
    $other = docPolicy('AUTO');
    expect($svc->resolve('POLICY_SCHEDULE', ['carrier_id' => $other['carrier']->id, 'tenant_id' => $other['tenant']->id, 'broker' => false, 'product_id' => null, 'insurance_class' => 'MOTOR'])->id)->toBe($regulatory->id);

    // Life: a generic platform template is not acceptable.
    docTemplate('LIFE_CONTRACT_SUMMARY');
    $lifeCtx = ['carrier_id' => $f['carrier']->id, 'tenant_id' => $f['tenant']->id, 'broker' => false, 'product_id' => null, 'insurance_class' => 'LIFE'];
    expect($svc->resolve('LIFE_CONTRACT_SUMMARY', $lifeCtx))->toBeNull();
    $life = docTemplate('LIFE_CONTRACT_SUMMARY', ['insurance_class' => 'LIFE']);
    expect($svc->resolve('LIFE_CONTRACT_SUMMARY', $lifeCtx)->id)->toBe($life->id);
});

it('runs the template workflow DRAFT → REVIEW → APPROVED → PUBLISHED → RETIRED with maker-checker and no destructive edits', function () {
    $maker = docUser();
    $checker = docUser();
    $svc = app(DocumentTemplateService::class);
    $t = $svc->createDraft(['document_type_code' => 'POLICY_SCHEDULE', 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL', 'content' => ['sections' => []]], $maker);
    expect($t->status)->toBe('DRAFT')->and($t->version)->toBe(1);
    $t = $svc->updateDraft($t, ['title_en' => 'Schedule'], $maker);
    $t = $svc->submit($t, $maker);
    expect(fn () => $svc->approve($t, $maker))->toThrow(ValidationException::class);
    expect(fn () => $svc->updateDraft($t, ['title_en' => 'x'], $maker))->toThrow(ValidationException::class);
    $t = $svc->approve($t, $checker);
    expect(fn () => $svc->publish($t, $maker))->toThrow(ValidationException::class);
    $v1 = $svc->publish($t, $checker, now()->subMonth()->toDateString());
    expect($v1->status)->toBe('PUBLISHED');

    // Tampering with a published template is detected at the next step of any version path.
    $v2 = $svc->createDraft(['document_type_code' => 'POLICY_SCHEDULE', 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL', 'content' => ['sections' => [['body_en' => 'v2']]]], $maker);
    expect($v2->version)->toBe(2)->and($v2->code)->toBe($v1->code);
    $v2 = $svc->approve($svc->submit($v2, $maker), $checker);
    DB::table('document_templates')->where('id', $v2->id)->update(['content' => json_encode(['sections' => [['body_en' => 'tampered']]])]);
    expect(fn () => $svc->publish($v2->refresh(), $checker))->toThrow(ValidationException::class);
    DB::table('document_templates')->where('id', $v2->id)->update(['content' => json_encode(['sections' => [['body_en' => 'v2']]])]);
    $svc->publish($v2->refresh(), $checker);
    expect($v1->refresh()->status)->toBe('RETIRED')->and($v1->effective_until)->not->toBeNull();

    expect(fn () => $svc->createDraft(['document_type_code' => 'NOT_A_TYPE', 'ownership' => 'PLATFORM', 'language' => 'FR'], $maker))->toThrow(ValidationException::class);
    expect(fn () => $svc->createDraft(['document_type_code' => 'POLICY_SCHEDULE', 'ownership' => 'INSURER', 'language' => 'FR'], $maker))->toThrow(ValidationException::class);
});

it('generates the motor pack on POLICY_ISSUED with number, hash, verification code; awaits carrier documents without authority', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-777-CM', 'make' => 'Toyota']);
    docTemplates(['INSURANCE_POLICY', 'POLICY_SCHEDULE', 'MOTOR_INSURANCE_ATTESTATION', 'MOTOR_INSURANCE_CERTIFICATE']);

    // No issuance profile: OpesInsure has no authority to render insurer documents.
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    expect(docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0]['state'])->toBe('AWAITING_CARRIER_DOCUMENT')
        ->and(docItems($m, 'INSURANCE_PROPOSAL')[0]['state'])->toBe('NOT_ON_FILE')
        ->and(docItems($m, 'COVER_NOTE')[0]['state'])->toBe('CONDITIONAL_NOT_TRIGGERED')
        ->and(Document::where('policy_id', $f['policy']->id)->count())->toBe(0);
    // Idempotent per event.
    expect(app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy'])->id)->toBe($m->id);

    $g = docPolicy('AUTO', ['registration_number' => 'LT-888-CM']);
    docAuthorize($g);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $g['policy']);
    $att = Document::find(docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0]['document_id']);
    expect($att->status)->toBe('VALID')
        ->and($att->document_origin)->toBe('INSURER')->and($att->issuer_type)->toBe('INSURER')
        ->and($att->document_number)->toStartWith('ATT-MOT-'.now()->format('Y').'-')
        ->and($att->verification_code)->toStartWith('OV')
        ->and($att->sha256)->toBe(hash('sha256', Storage::disk('local')->get($att->storage_key)))
        ->and($att->subject_key)->toBe('LT-888-CM')
        ->and($att->security_level)->toBe('PUBLIC_VERIFIABLE')
        ->and($att->template_version)->toBe(1)
        ->and($att->document_type_id)->toBe('DOC-041');
    expect(docItems($m, 'GENERAL_CONDITIONS')[0]['state'])->toBe('AWAITING_CARRIER_DOCUMENT') // no template
        ->and(Document::find(docItems($m, 'INSURANCE_POLICY')[0]['document_id'])->document_number)->toStartWith('POL-');
});

it('issues one attestation and one certificate per vehicle for a fleet', function () {
    $f = docPolicy('AUTO', ['vehicles' => [['registration_number' => 'CE-001-A', 'make' => 'Isuzu'], ['registration_number' => 'CE-002-A'], ['registration_number' => 'CE-003-A']]]);
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION', 'MOTOR_INSURANCE_CERTIFICATE', 'MASTER_GROUP_POLICY']);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);

    expect($m->pack_code)->toBe('FLEET_MASTER_PACK');
    $atts = Document::where('policy_id', $f['policy']->id)->where('document_type_code', 'MOTOR_INSURANCE_ATTESTATION')->orderBy('document_sequence')->get();
    expect($atts)->toHaveCount(3)->and($atts->pluck('subject_key')->all())->toBe(['CE-001-A', 'CE-002-A', 'CE-003-A'])
        ->and($atts->pluck('document_sequence')->all())->toBe([1, 2, 3]) // continuous ATT-MOT numbering
        ->and(Document::where('policy_id', $f['policy']->id)->where('document_type_code', 'MOTOR_INSURANCE_CERTIFICATE')->count())->toBe(3)
        ->and($atts->first()->subject_label)->toBe('Isuzu CE-001-A');
});

it('numbers documents continuously per tenant and family, honours tenant prefixes, and leaves no gap on rollback', function () {
    $f = docPolicy('AUTO');
    $alloc = app(DocumentNumberAllocator::class);
    $y = now()->format('Y');
    expect($alloc->allocate($f['tenant']->id, 'INSURANCE_POLICY')['number'])->toBe("POL-{$y}-000001")
        ->and($alloc->allocate($f['tenant']->id, 'INSURANCE_POLICY')['number'])->toBe("POL-{$y}-000002")
        ->and($alloc->allocate($f['tenant']->id, 'PREMIUM_RECEIPT')['number'])->toBe("RCT-{$y}-000001")
        ->and($alloc->allocate($f['tenant']->id, 'CLAIM_ACKNOWLEDGEMENT')['number'])->toBe("CLM-{$y}-000001")
        ->and($alloc->allocate($f['tenant']->id, 'POLICY_ENDORSEMENT')['number'])->toBe("AVN-{$y}-000001");

    try {
        DB::transaction(function () use ($alloc, $f) {
            $alloc->allocate($f['tenant']->id, 'INSURANCE_POLICY');
            throw new RuntimeException('issuance failed');
        });
    } catch (RuntimeException) {
    }
    expect($alloc->allocate($f['tenant']->id, 'INSURANCE_POLICY')['number'])->toBe("POL-{$y}-000003");

    // Other tenant: own sequence. Tenant-configured family prefix + claimed type.
    $other = docPolicy('AUTO');
    DocumentNumberingFamily::create(['tenant_id' => $other['tenant']->id, 'family_code' => 'POL', 'prefix' => 'ACM-POL', 'include_year' => false, 'pad' => 5, 'document_type_codes' => ['SPECIAL_CONDITIONS']]);
    expect($alloc->allocate($other['tenant']->id, 'INSURANCE_POLICY')['number'])->toBe('ACM-POL-00001')
        ->and($alloc->allocate($other['tenant']->id, 'SPECIAL_CONDITIONS')['number'])->toBe('ACM-POL-00002');
});

it('keeps issued documents immutable when templates change, and supersedes on endorsement (avenant 001, 002)', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-100-AA']);
    docAuthorize($f);
    $v1 = docTemplate('POLICY_SCHEDULE');
    docTemplates(['MOTOR_INSURANCE_ATTESTATION', 'POLICY_ENDORSEMENT']);
    $engine = app(DocumentEngine::class);
    $m = $engine->fire('POLICY_ISSUED', $f['policy']);
    $schedule = Document::find(docItems($m, 'POLICY_SCHEDULE')[0]['document_id']);
    $bytes = Storage::disk('local')->get($schedule->storage_key);

    // New template version published: the issued schedule is untouched.
    $maker = docUser();
    $checker = docUser();
    $svc = app(DocumentTemplateService::class);
    DB::table('document_templates')->where('id', $v1->id)->update(['code' => DocumentTemplateService::lineage(['document_type_code' => 'REVISED_POLICY_SCHEDULE', 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL'])]);
    $rev = $svc->createDraft(['document_type_code' => 'REVISED_POLICY_SCHEDULE', 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL', 'content' => ['sections' => [['body_en' => 'Revised {event}']]]], $maker);
    $rev = $svc->publish($svc->approve($svc->submit($rev, $maker), $checker), $checker, now()->subDay()->toDateString());
    expect($schedule->refresh()->template_version)->toBe(1)->and($schedule->template_hash)->toBe($v1->content_hash)
        ->and(Storage::disk('local')->get($schedule->storage_key))->toBe($bytes);

    $approver = docUser();
    foreach ([1, 2] as $n) {
        $tx = PolicyTransaction::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $f['policy']->id, 'type' => 'ENDORSEMENT', 'status' => 'APPROVED', 'transaction_number' => 'END-'.Str::random(8), 'effective_at' => now(), 'reason_code' => 'CHANGE', 'requested_changes' => ['registration_number' => 'LT-100-AA'], 'requested_by' => $maker->id, 'approved_by' => $approver->id, 'approved_at' => now()]);
        $f['policy']->update(['version' => $n + 1]);
        $em = $engine->fire('ENDORSEMENT_ISSUED', $f['policy']->refresh(), ['transaction' => $tx]);
        expect($em->event_label)->toContain('AVENANT 00'.$n)->and($em->sequence)->toBe($n);
    }
    expect($schedule->refresh()->status)->toBe('SUPERSEDED')->and($schedule->superseded_by_document_id)->not->toBeNull();
    $revised = Document::where('policy_id', $f['policy']->id)->where('document_type_code', 'REVISED_POLICY_SCHEDULE')->orderBy('created_at')->get();
    expect($revised)->toHaveCount(2)->and($revised[0]->refresh()->status)->toBe('SUPERSEDED')->and($revised[1]->status)->toBe('ISSUED')
        ->and($revised[1]->policy_version)->toBe(3)->and($revised[1]->supersedes_document_id)->toBe($revised[0]->id)
        ->and($revised[1]->document_number)->toStartWith('AVN-');
    $atts = Document::where('policy_id', $f['policy']->id)->where('document_type_code', 'MOTOR_INSURANCE_ATTESTATION')->get();
    expect($atts)->toHaveCount(3)->and($atts->where('status', 'VALID'))->toHaveCount(1);

    // A trigger without the required state is refused (no arbitrary generation).
    $pending = PolicyTransaction::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $f['policy']->id, 'type' => 'ENDORSEMENT', 'status' => 'PENDING_APPROVAL', 'transaction_number' => 'END-'.Str::random(8), 'effective_at' => now(), 'reason_code' => 'X', 'requested_by' => $maker->id]);
    expect(fn () => $engine->fire('ENDORSEMENT_ISSUED', $f['policy'], ['transaction' => $pending]))->toThrow(ValidationException::class);
    $f['policy']->update(['status' => 'PAID_PENDING_ISSUANCE']);
    expect(fn () => $engine->fire('RENEWAL_ISSUED', $f['policy']))->toThrow(ValidationException::class);
});

it('revokes and replaces through maker-checker, and verification reports SUPERSEDED / REVOKED / REPLACED / EXPIRED with lookup logging', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-555-CM']);
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION', 'MOTOR_INSURANCE_CERTIFICATE', 'POLICY_SCHEDULE']);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $att = Document::find(docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0]['document_id']);
    $cert = Document::find(docItems($m, 'MOTOR_INSURANCE_CERTIFICATE')[0]['document_id']);
    $schedule = Document::find(docItems($m, 'POLICY_SCHEDULE')[0]['document_id']);

    $verify = fn (string $code) => $this->postJson('/api/v1/public/insurance/verify', ['reference' => $code])->assertOk()->json('data');
    $ok = $verify($att->verification_code);
    expect($ok['result'])->toBe('valid')->and($ok['document']['document_number'])->toBe($att->document_number)
        ->and($ok['document']['status'])->toBe('VALID')->and($ok['document']['sha256'])->toBe($att->sha256)
        ->and($ok['document']['policy_reference'])->toBe($f['policy']->policy_number)->and($ok['document']['vehicle'])->toBe('LT-555-CM')
        ->and($ok)->not->toHaveKey('insured_name');
    expect(DB::table('public_verification_lookups')->where('document_id', $att->id)->count())->toBe(1);

    $maker = docUser();
    $checker = docUser();
    $status = app(DocumentStatusService::class);
    $change = $status->request($att, 'REVOKE', 'Issued on wrong vehicle', $maker);
    expect(fn () => $status->approve($change, $maker))->toThrow(ValidationException::class);
    $status->approve($change, $checker);
    expect($att->refresh()->status)->toBe('REVOKED')->and(Document::whereKey($att->id)->exists())->toBeTrue();
    $rev = $verify($att->verification_code);
    expect($rev['result'])->toBe('revoked')->and($rev['document']['status'])->toBe('REVOKED');

    // Replace the schedule with a carrier original → REPLACED, successor named.
    $carrier = app(CarrierDocumentService::class)->upload($f['policy'], '%PDF-1.4 carrier schedule', 'application/pdf', ['document_type_code' => 'POLICY_SCHEDULE', 'issue_date' => now()->toDateString(), 'carrier_document_number' => 'CP-2026-77'], $maker);
    $replaced = $verify($schedule->verification_code);
    expect($replaced['result'])->toBe('replaced')->and($replaced['document']['replaced_by'])->toBe('CP-2026-77');

    // Expired: validity ended.
    $cert->update(['valid_until' => now()->subDay()]);
    expect($verify($cert->verification_code)['result'])->toBe('expired');

    // Superseded (new certificate version).
    $cert->update(['valid_until' => now()->addYear(), 'status' => 'SUPERSEDED', 'superseded_by_document_id' => $carrier->id]);
    expect($verify($cert->verification_code)['result'])->toBe('superseded');

    // /verify page by code.
    $this->get('/verify?code='.$att->verification_code)->assertOk()->assertSee('Revoked')->assertSee($att->document_number);
    expect($verify('OVNOTACODE00')['result'])->toBe('not_found');
});

it('uploads carrier original documents with provenance; they are the official document and fill awaiting pack items', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-200-BB']);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    expect(docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0]['state'])->toBe('AWAITING_CARRIER_DOCUMENT');

    $staff = docUser();
    $doc = app(CarrierDocumentService::class)->upload($f['policy'], '%PDF-1.4 carrier attestation', 'application/pdf', [
        'document_type_code' => 'MOTOR_INSURANCE_ATTESTATION', 'issue_date' => '2026-09-20', 'carrier_document_number' => 'ATT-ACME-991', 'carrier_version' => '1', 'subject_key' => 'LT-200-BB', 'language' => 'FR',
    ], $staff);
    expect($doc->is_carrier_original)->toBeTrue()->and($doc->document_origin)->toBe('INSURER')->and($doc->status)->toBe('VALID')
        ->and($doc->provenance['carrier_document_number'])->toBe('ATT-ACME-991')->and($doc->provenance['uploaded_by'])->toBe($staff->id)
        ->and($doc->sha256)->toBe(hash('sha256', '%PDF-1.4 carrier attestation'));
    expect(docItems($m->refresh(), 'MOTOR_INSURANCE_ATTESTATION')[0]['state'])->toBe('CARRIER_PROVIDED');

    expect(fn () => app(CarrierDocumentService::class)->upload($f['policy'], 'x', 'application/pdf', ['document_type_code' => 'RISK_DECLARATION', 'issue_date' => '2026-09-20'], $staff))->toThrow(ValidationException::class);
    expect(fn () => app(CarrierDocumentService::class)->upload($f['policy'], '%PDF-1.4 carrier attestation', 'application/pdf', ['document_type_code' => 'MOTOR_INSURANCE_ATTESTATION', 'issue_date' => '2026-09-20'], $staff))->toThrow(ValidationException::class);
});

it('serves the customer documents API grouped by stage with history, keeps evidence separate, enforces security levels, and zips the pack', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-300-CC']);
    docAuthorize($f);
    docTemplates(['INSURANCE_POLICY', 'POLICY_SCHEDULE', 'MOTOR_INSURANCE_ATTESTATION', 'PREMIUM_RECEIPT']);
    $m = app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $staff = docUser();
    app(CarrierDocumentService::class)->upload($f['policy'], '%PDF-1.4 new att', 'application/pdf', ['document_type_code' => 'MOTOR_INSURANCE_ATTESTATION', 'issue_date' => now()->toDateString(), 'subject_key' => 'LT-300-CC'], $staff);

    // Customer evidence + an insurer-confidential report on the same policy.
    Document::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'policy_id' => $f['policy']->id, 'category' => 'CLAIM_PHOTO', 'storage_key' => 'x/p.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64)]);
    Document::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'policy_id' => $f['policy']->id, 'category' => 'ENGINE_ASSESSMENT_REPORT', 'storage_key' => 'x/r.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('b', 64), 'document_type_code' => 'ASSESSMENT_REPORT', 'document_origin' => 'ADJUSTER', 'security_level' => 'INSURER_CONFIDENTIAL']);

    Passport::actingAs($f['user']);
    $data = $this->getJson("/api/v1/mobile/policies/{$f['policy']->id}/documents", tenantHeaderFor($f['tenant']))->assertOk()->json('data');
    $groups = collect($data['groups'])->keyBy('group');
    expect(array_keys($groups->all()))->toBe(['POLICY_PACK', 'CERTIFICATES', 'SERVICING', 'CLAIMS', 'FINANCIAL']);
    $certs = $groups['CERTIFICATES'];
    expect($certs['documents'])->toHaveCount(1)->and($certs['documents'][0]['is_carrier_original'])->toBeTrue()
        ->and($certs['history'])->toHaveCount(1)->and($certs['history'][0]['status'])->toBe('REPLACED')
        ->and($groups['FINANCIAL']['documents'][0]['document_type_code'])->toBe('PREMIUM_RECEIPT')
        ->and(collect($groups['POLICY_PACK']['documents'])->pluck('document_type_code'))->toContain('INSURANCE_POLICY', 'POLICY_SCHEDULE');
    expect($data['evidence'])->toHaveCount(1)->and($data['evidence'][0]['category'])->toBe('CLAIM_PHOTO')->and($data['evidence'][0]['issued_by_insurer'])->toBeFalse();
    expect(json_encode($data))->not->toContain('ASSESSMENT_REPORT');
    expect($data['contract_history'][0]['kind'])->toBe('ORIGINAL')->and($data['packs'][0]['pack_code'])->toBe('MOTOR_NEW_BUSINESS_PACK');

    $filtered = $this->getJson("/api/v1/mobile/policies/{$f['policy']->id}/documents?group=CERTIFICATES", tenantHeaderFor($f['tenant']))->assertOk()->json('data.groups');
    expect($filtered)->toHaveCount(1);

    $url = $this->getJson("/api/v1/mobile/policies/{$f['policy']->id}/documents/pack", tenantHeaderFor($f['tenant']))->assertOk()->json('data.url');
    $zip = $this->get($url)->assertOk();
    expect($zip->headers->get('content-type'))->toContain('zip');
    $path = $zip->baseResponse->getFile()->getPathname();
    $archive = new ZipArchive;
    $archive->open($path);
    $names = [];
    for ($i = 0; $i < $archive->numFiles; $i++) {
        $names[] = $archive->getNameIndex($i);
    }
    expect($names)->toContain('manifest.json')->and(count($names))->toBe(5); // policy, schedule, carrier attestation, receipt + manifest

    // Another customer cannot read it; tampered signature rejected.
    $other = docPolicy('AUTO');
    Passport::actingAs($other['user']);
    $this->getJson("/api/v1/mobile/policies/{$f['policy']->id}/documents", tenantHeaderFor($other['tenant']))->assertStatus(404);
    $this->get($url.'x')->assertStatus(403);
});

it('fires claim and cancellation triggers: claim acknowledgement pack, certificates cancelled', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-400-DD']);
    docAuthorize($f);
    docTemplates(['MOTOR_INSURANCE_ATTESTATION', 'CLAIM_ACKNOWLEDGEMENT', 'CANCELLATION_TERMINATION_NOTICE']);
    $engine = app(DocumentEngine::class);
    $m = $engine->fire('POLICY_ISSUED', $f['policy']);
    $att = Document::find(docItems($m, 'MOTOR_INSURANCE_ATTESTATION')[0]['document_id']);

    $claim = Claim::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $f['policy']->id, 'claim_number' => 'CLM-T-'.Str::random(6), 'status' => 'SUBMITTED', 'loss_occurred_at' => now(), 'loss_details' => []]);
    $cm = $engine->fire('CLAIM_REGISTERED', $f['policy'], ['claim' => $claim]);
    $ack = Document::find(docItems($cm, 'CLAIM_ACKNOWLEDGEMENT')[0]['document_id']);
    expect($ack->claim_id)->toBe($claim->id)->and($ack->document_number)->toStartWith('CLM-')->and($ack->document_stage)->toBe('CLAIM');
    expect(fn () => $engine->fire('CLAIM_APPROVED', $f['policy'], ['claim' => $claim]))->toThrow(ValidationException::class);

    $u = docUser();
    $tx = PolicyTransaction::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $f['policy']->id, 'type' => 'CANCELLATION', 'status' => 'APPROVED', 'transaction_number' => 'CAN-'.Str::random(6), 'effective_at' => now(), 'reason_code' => 'CUSTOMER', 'requested_by' => $u->id]);
    $engine->fire('CANCELLATION_ISSUED', $f['policy'], ['transaction' => $tx]);
    expect($att->refresh()->status)->toBe('CANCELLED');
});

it('gates product publication on the document acceptance checks only for OPES_GENERATED issuance', function () {
    $f = docPolicy('AUTO');
    $gate = app(ProductDocumentGate::class);

    $manual = $gate->evaluate($f['product']);
    expect($manual['mode'])->toBe('MANUAL_UPLOAD')->and($manual['blocking'])->toBeFalse()->and($manual['checks'])->toHaveCount(16)->and($manual['passed'])->toBeTrue();
    expect($gate->assertPublishable($f['product']))->toBe([]);

    docAuthorize($f);
    $r = $gate->evaluate($f['product']);
    expect($r['blocking'])->toBeTrue()->and($r['passed'])->toBeFalse()
        ->and(collect($r['checks'])->firstWhere('code', 'APPROVED_TEMPLATES')['passed'])->toBeFalse();
    expect(fn () => $gate->assertPublishable($f['product']))->toThrow(ValidationException::class);

    docTemplates(['INSURANCE_POLICY', 'POLICY_SCHEDULE', 'GENERAL_CONDITIONS', 'MOTOR_INSURANCE_ATTESTATION', 'MOTOR_INSURANCE_CERTIFICATE', 'PREMIUM_RECEIPT']);
    expect($gate->evaluate($f['product'])['passed'])->toBeTrue();
});

it('renders the DOC-ADM document engine admin screens for platform admins only', function () {
    $f = docPolicy('AUTO', ['registration_number' => 'LT-900-ZZ']);
    docAuthorize($f);
    $tpl = docTemplate('MOTOR_INSURANCE_ATTESTATION');
    app(DocumentEngine::class)->fire('POLICY_ISSUED', $f['policy']);
    $admin = makeMobileTenantStaffUser($f['tenant'], '+237670009901', 'PLATFORM_ADMIN');
    $this->actingAs($admin, 'web');
    foreach (['', '/types', '/families', '/templates', '/templates/create', "/templates/{$tpl->id}", '/product-mapping', '/numbering', '/numbering/create',
        '/signature', '/qr', '/issuance-rules', '/issuance-rules/create', '/registry', '/revocations', '/localization', '/audit', '/quality'] as $path) {
        $this->get('/admin/document-engine'.$path)->assertOk();
    }
    $this->get('/admin/document-engine/registry')->assertSee('MOTOR_INSURANCE_ATTESTATION');

    $this->flushSession();
    app('auth')->forgetGuards();
    $this->actingAs(makeMobileTenantStaffUser($f['tenant'], '+237670009902', 'CLAIMS_OFFICER'), 'web');
    $this->get('/admin/document-engine/registry')->assertForbidden();
});
