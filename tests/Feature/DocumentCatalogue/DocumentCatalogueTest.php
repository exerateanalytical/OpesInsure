<?php

declare(strict_types=1);

use App\Application\DocumentCatalogue\DocumentCatalogueService;
use App\Application\DocumentCatalogue\DocumentPublicationGate;
use App\Application\DocumentCatalogue\DocumentRequirementResolver;
use App\Application\DocumentCatalogue\ProductDocumentRequirementService;
use App\Models\Carrier;
use App\Models\DocumentCatalogue\DocumentPack;
use App\Models\DocumentCatalogue\DocumentPackItem;
use App\Models\DocumentCatalogue\DocumentProductType;
use App\Models\DocumentCatalogue\DocumentRequirementMatrixEntry;
use App\Models\DocumentCatalogue\DocumentType;
use App\Models\DocumentCatalogue\ProductDocumentRequirement;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->artisan('opesinsure:seed-document-catalogue')->assertExitCode(0));

function doccatUser(string $name = 'Maker'): User
{
    $party = Party::create(['type' => 'PERSON', 'display_name' => $name, 'status' => 'ACTIVE']);

    return User::create(['full_name' => $name, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'party_id' => $party->id, 'password' => 'x', 'locale' => 'fr', 'status' => 'ACTIVE']);
}

function doccatProduct(): InsuranceProduct
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Doc Assurances '.Str::random(4), 'status' => 'ACTIVE']);
    $carrier = Carrier::create(['party_id' => $party->id, 'cima_code' => 'D-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => []]);

    return InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'MOTOR', 'code' => 'MOTOR-'.Str::random(5), 'name' => 'Motor', 'version' => 1,
        'effective_from' => '2026-01-01', 'status' => 'DRAFT', 'coverages' => [], 'eligibility_rules' => ['conditions' => []], 'created_by' => doccatUser()->id, 'regulatory_reference' => 'REF']);
}

it('seeds the owner 220-type register as the authoritative registry, with subtypes and evidence', function () {
    $register = json_decode(file_get_contents(database_path('data/document_register_220_2026.json')), true);
    expect(DocumentType::where('namespace', 'REGISTER')->count())->toBe(220)
        ->and(DocumentType::count())->toBeGreaterThanOrEqual(180);
    foreach ($register['document_types'] as $d) {
        $t = DocumentType::where('type_id', $d['id'])->first();
        expect($t)->not->toBeNull()->and($t->canonical_code)->toBe($d['canonical_code'])->and($t->name_fr)->toBe($d['name_fr']);
    }
    expect(DocumentType::where('kind', 'SUBTYPE')->where('namespace', 'CLAIM')->count())->toBe(30)
        ->and(DocumentType::where('type_id', 'CLM-19')->value('parent_type_id'))->toBe('DOC-219')
        ->and(DocumentType::where('type_id', 'FINANCE.PREMIUM_RECEIPT')->value('legal_reference'))->toBe('CIMA Art. 13')
        ->and(DocumentType::where('is_evidence', true)->where('namespace', 'EVIDENCE')->count())->toBeGreaterThanOrEqual(40);
});

it('has EN and FR labels and complete attributes on every type', function () {
    DocumentType::all()->each(function (DocumentType $t) {
        expect($t->name_en)->not->toBeEmpty()->and($t->name_fr)->not->toBeEmpty()
            ->and($t->stages)->not->toBeEmpty()->and($t->issuer_authority)->not->toBeEmpty()->and($t->generation_triggers)->not->toBeEmpty()
            ->and($t->security_level)->toBeIn(['PUBLIC_VERIFIABLE', 'CUSTOMER_PRIVATE', 'INSURER_CONFIDENTIAL', 'MEDICAL_RESTRICTED', 'FINANCIAL_RESTRICTED', 'INTERNAL', 'REGULATORY']);
    });
    $att = DocumentType::where('canonical_code', 'MOTOR_INSURANCE_ATTESTATION')->first();
    expect($att->type_id)->toBe('DOC-041')->and($att->legal_reference)->toBe('CIMA Arts. 213, 221')->and($att->verifiable)->toBeTrue()
        ->and($att->scope)->toBe('PER_VEHICLE')->and($att->numbering_family)->toBe('ATT-MOT')->and($att->security_level)->toBe('PUBLIC_VERIFIABLE');
    expect(DocumentType::where('canonical_code', 'LIFE_CONTRACT_SUMMARY')->value('legal_reference'))->toBe('CIMA Art. 65-1')
        ->and(DocumentType::where('canonical_code', 'POLICE_REPORT')->value('document_origin'))->toBe('AUTHORITY');
});

it('resolves brief / legacy codes through aliases to canonical ids', function () {
    $svc = app(DocumentCatalogueService::class);
    expect($svc->find('MOTOR_ATTESTATION')->type_id)->toBe('DOC-041')
        ->and($svc->find('POLICY')->type_id)->toBe('DOC-021')
        ->and($svc->find('PROVISIONAL_ATTESTATION')->type_id)->toBe('DOC-043')
        ->and($svc->find('PREMIUM_RECEIPT')->type_id)->toBe('FINANCE.PREMIUM_RECEIPT')
        ->and($svc->find('doc-074')->canonical_code)->toBe('GUARANTEE_OF_PAYMENT');
});

it('packs reference existing types and every class has new business, renewal and claim coverage', function () {
    expect(DocumentPackItem::whereNotIn('document_type_id', DocumentType::pluck('type_id'))->count())->toBe(0);
    $classes = [];
    DocumentPack::all()->each(function (DocumentPack $p) use (&$classes) {
        foreach ($p->class_codes as $c) {
            $classes[$c][$p->lifecycle_stage] = true;
        }
    });
    expect(count($classes))->toBeGreaterThanOrEqual(25);
    foreach ($classes as $class => $stages) {
        expect(array_keys($stages))->toContain('NEW_BUSINESS', 'RENEWAL', 'CLAIM');
    }
    foreach (['UNIVERSAL_SERVICING', 'UNIVERSAL_FINANCIAL', 'UNIVERSAL_CLAIM', 'MOTOR_NEW_BUSINESS_PACK', 'HEALTH_GROUP_MEMBER_PACK', 'LIFE_SURRENDER', 'FLEET_VEHICLE_PACK', 'MARINE_CARGO_SHIPMENT_PACK'] as $code) {
        expect(DocumentPack::where('code', $code)->exists())->toBeTrue("missing $code");
    }
    $motor = DocumentPack::where('code', 'MOTOR_NEW_BUSINESS_PACK')->first()->items->pluck('requirement', 'document_type_id');
    expect($motor['DOC-041'])->toBe('REQUIRED')->and($motor['DOC-042'])->toBe('REQUIRED');
});

it('seeding is idempotent', function () {
    $counts = fn () => collect(['document_types', 'document_packs', 'document_pack_items', 'document_type_class_applicability', 'document_product_types', 'document_requirement_matrix', 'document_matrix_variants'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    $before = $counts();
    $this->artisan('opesinsure:seed-document-catalogue')->expectsOutputToContain('no changes')->assertExitCode(0);
    expect($counts())->toBe($before);
});

it('seeded rows cannot be deleted or edited in place, only deactivated', function () {
    $t = DocumentType::where('type_id', 'DOC-021')->first();
    expect(fn () => $t->delete())->toThrow(LogicException::class);
    expect(fn () => $t->update(['name_fr' => 'X']))->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => DB::table('document_types')->where('type_id', 'DOC-021')->delete()))->toThrow(Illuminate\Database\QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('document_packs')->where('code', 'MOTOR_NEW_BUSINESS_PACK')->delete()))->toThrow(Illuminate\Database\QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('document_requirement_matrix')->limit(1)->delete()))->toThrow(Illuminate\Database\QueryException::class);
    $t->refresh()->deactivate();
    expect($t->refresh()->status)->toBe('INACTIVE')->and(DocumentType::where('type_id', 'DOC-021')->exists())->toBeTrue();
});

it('every matrix entry resolves to a register id and mandatory entries use the 220 register, subtypes or evidence', function () {
    $matrix = json_decode(file_get_contents(database_path('data/document_requirement_matrix_2026.json')), true);
    expect(DocumentProductType::count())->toBe(count($matrix['product_types']));
    DocumentRequirementMatrixEntry::with('documentType')->get()->each(function ($e) {
        expect($e->documentType)->not->toBeNull()->and($e->level)->toBeIn(['M', 'C', 'O', 'I', 'T']);
    });
    $mandatoryIds = DocumentRequirementMatrixEntry::where('level', 'M')->pluck('document_type_id')->unique();
    expect($mandatoryIds->filter(fn ($id) => ! preg_match('/^(DOC-\d{3}|CLM-\d{2}|[A-Z_]+\.[A-Z_]+|EVD-\d{3})$/', $id))->all())->toBe([]);
    expect(DocumentRequirementMatrixEntry::where('product_type_code', 'MOTOR_TPL')->where('matrix_label', 'Premium Receipt')->first()->qualifiers)->toBe(['AFTER_PAYMENT']);
    $medical = DocumentRequirementMatrixEntry::where('product_type_code', 'HEALTH_INDIVIDUAL')->where('document_type_id', 'DOC-062')->first();
    expect($medical->level)->toBe('C')->and($medical->alternate_level)->toBe('M')->and($medical->insurer_overridable)->toBeTrue();
});

it('applies the universal baseline to every product type', function () {
    $resolver = app(DocumentRequirementResolver::class);
    DocumentProductType::pluck('code')->each(function ($code) use ($resolver) {
        $ids = $resolver->resolve($code)->pluck('document_type_id')->all();
        foreach (['DOC-001', 'DOC-003', 'DOC-009', 'DOC-021', 'DOC-022', 'DOC-023', 'FINANCE.PREMIUM_RECEIPT'] as $id) {
            expect($ids)->toContain($id);
        }
    });
});

it('supports inheritance: comprehensive extends TPL, family/group health extend individual, whole life extends term life, education extends savings', function () {
    $r = app(DocumentRequirementResolver::class);
    expect($r->chain('MOTOR_COMPREHENSIVE'))->toBe(['MOTOR_TPL', 'MOTOR_COMPREHENSIVE'])
        ->and($r->chain('HEALTH_FAMILY'))->toBe(['HEALTH_INDIVIDUAL', 'HEALTH_FAMILY'])
        ->and($r->chain('HEALTH_GROUP'))->toBe(['HEALTH_INDIVIDUAL', 'HEALTH_GROUP'])
        ->and($r->chain('WHOLE_LIFE'))->toBe(['TERM_LIFE', 'WHOLE_LIFE'])
        ->and($r->chain('EDUCATION_SAVINGS'))->toBe(['SAVINGS_CAPITALIZATION', 'EDUCATION_SAVINGS']);
    $comp = $r->resolve('MOTOR_COMPREHENSIVE');
    $attestation = $comp->firstWhere('document_type_id', 'DOC-041');
    expect($attestation['source'])->toBe('INHERITED:MOTOR_TPL')->and($attestation['qualifiers'])->toBe(['PER_VEHICLE'])
        ->and($comp->firstWhere('variant_code', 'MOTOR_OWN_DAMAGE_SCHEDULE')['source'])->toBe('OWN')
        ->and($comp->firstWhere('document_type_id', 'DOC-005')['source'])->toBe('BASELINE');
    expect($r->resolve('MOTOR_TPL')->firstWhere('variant_code', 'MOTOR_OWN_DAMAGE_SCHEDULE'))->toBeNull();
    // Own definition overrides the baseline on the same key (Quote declared by motor TPL itself).
    expect($r->resolve('MOTOR_TPL')->where('document_type_id', 'DOC-001')->where('stage', 'PRE_CONTRACT')->first()['source'])->toBe('OWN');
});

it('applies product overrides with maker-checker and never lets an insurer downgrade a strictly mandatory document', function () {
    $svc = app(ProductDocumentRequirementService::class);
    $product = doccatProduct();
    $maker = doccatUser('Maker');
    $checker = doccatUser('Checker');

    expect(fn () => $svc->propose($product, ['kind' => 'MATRIX_OVERRIDE', 'document_type_id' => 'DOC-041', 'stage' => 'ISSUANCE', 'level' => 'C'], $maker))->toThrow(ValidationException::class);

    $sel = $svc->propose($product, ['kind' => 'PRODUCT_TYPE', 'product_type_code' => 'MOTOR_TPL'], $maker);
    expect(app(DocumentPublicationGate::class)->check($product)['blocking'])->not->toBeEmpty();
    expect(fn () => $svc->approve($sel, $maker))->toThrow(ValidationException::class);
    $svc->approve($sel, $checker);
    expect(app(DocumentRequirementResolver::class)->productTypeFor($product))->toBe('MOTOR_TPL')
        ->and(app(DocumentPublicationGate::class)->check($product)['blocking'])->toBe([]);

    // Motor attestation is M (not overridable) → downgrade refused.
    expect(fn () => $svc->propose($product, ['kind' => 'MATRIX_OVERRIDE', 'document_type_id' => 'DOC-041', 'stage' => 'ISSUANCE', 'level' => 'C'], $maker))->toThrow(ValidationException::class);
    // Driver information is C → insurer may make it mandatory.
    $o = $svc->propose($product, ['kind' => 'MATRIX_OVERRIDE', 'document_code' => 'DRIVER_SCHEDULE', 'stage' => 'PRE_CONTRACT', 'level' => 'M'], $maker);
    $svc->approve($o, $checker);
    $row = app(DocumentRequirementResolver::class)->resolve('MOTOR_TPL', $product)->firstWhere('document_type_id', 'DOC-053');
    expect($row['level'])->toBe('M')->and($row['source'])->toBe('PRODUCT_OVERRIDE');
    // Platform matrix unchanged.
    expect(DocumentRequirementMatrixEntry::where(['product_type_code' => 'MOTOR_TPL', 'document_type_id' => 'DOC-053'])->value('level'))->toBe('C');

    expect(fn () => $o->delete())->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => DB::table('product_document_requirements')->where('id', $o->id)->delete()))->toThrow(Illuminate\Database\QueryException::class);
    $svc->retire($o->refresh(), $checker, 'No longer needed');
    expect(ProductDocumentRequirement::find($o->id)->status)->toBe('RETIRED');
});

it('blocks publication on a placeholder product type', function () {
    $svc = app(ProductDocumentRequirementService::class);
    $product = doccatProduct();
    $svc->approve($svc->propose($product, ['kind' => 'PRODUCT_TYPE', 'product_type_code' => 'AVIATION'], doccatUser()), doccatUser('C'));
    expect(fn () => app(DocumentPublicationGate::class)->assertPublishable($product))->toThrow(ValidationException::class);
});

it('renders the admin document catalogue screens for platform admins only', function () {
    require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
    $tenant = App\Models\Tenant::create(['type' => 'CARRIER', 'legal_name' => 'Doc Admin Tenant', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'fr']);
    $type = DocumentType::where('type_id', 'DOC-041')->first();
    $pack = DocumentPack::where('code', 'MOTOR_NEW_BUSINESS_PACK')->first();
    $this->actingAs(makeMobileTenantStaffUser($tenant, '+237670003301', 'PLATFORM_ADMIN'), 'web');
    foreach (['/admin/document-types', "/admin/document-types/{$type->id}", '/admin/document-packs', "/admin/document-packs/{$pack->id}", '/admin/document-pack-items',
        '/admin/document-class-applicability', '/admin/product-document-requirements', '/admin/document-requirement-matrix?productType=MOTOR_COMPREHENSIVE'] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get('/admin/document-requirement-matrix?productType=MOTOR_COMPREHENSIVE')->assertSee('MOTOR_OWN_DAMAGE_SCHEDULE')->assertSee('INHERITED:MOTOR_TPL');
    $this->get("/admin/document-types/{$type->id}")->assertSee('CIMA Arts. 213, 221');

    $this->flushSession();
    app('auth')->forgetGuards();
    $this->actingAs(makeMobileTenantStaffUser($tenant, '+237670003302', 'CLAIMS_OFFICER'), 'web');
    $this->get('/admin/document-types')->assertForbidden();
});

it('exposes the engine read contract typeFor / packFor / requirementsFor and marks legacy requirement versions', function () {
    $svc = app(DocumentCatalogueService::class);
    expect($svc->typeFor('MOTOR_CERTIFICATE')->type_id)->toBe('DOC-042')->and($svc->typeFor('NOPE'))->toBeNull();
    $pack = $svc->packFor('motor_new_business_pack');
    expect($pack->items->pluck('document_type_id'))->toContain('DOC-041', 'DOC-042');
    expect($svc->requirementsFor('MOTOR_TPL', 'ISSUANCE')->pluck('document_type_id'))->toContain('DOC-041')
        ->and($svc->requirementsFor('MOTOR_TPL', 'ISSUANCE')->pluck('stage')->unique()->all())->toBe(['ISSUANCE']);
    $product = doccatProduct();
    expect($svc->requirementsFor($product))->toBeEmpty();
    $p = app(ProductDocumentRequirementService::class);
    $p->approve($p->propose($product, ['kind' => 'PRODUCT_TYPE', 'product_type_code' => 'MOTOR_COMPREHENSIVE'], doccatUser()), doccatUser('C'));
    expect($svc->requirementsFor($product)->pluck('variant_code'))->toContain('MOTOR_OWN_DAMAGE_SCHEDULE');
    expect(Illuminate\Support\Facades\Schema::hasColumns('document_requirement_versions', ['is_legacy', 'catalogue_type_id']))->toBeTrue();
});

it('serves the public API with EN/FR labels', function () {
    $types = $this->getJson('/api/v1/public/document-types?group=CERTIFICATE&class=MOTOR')->assertOk()->json('data');
    expect(collect($types)->pluck('type_id'))->toContain('DOC-041', 'DOC-042')
        ->and($types[0]['label'])->toHaveKeys(['en', 'fr']);
    $this->getJson('/api/v1/public/document-types?stage=CLAIM')->assertOk()->assertJsonFragment(['type_id' => 'CLM-01']);
    $this->getJson('/api/v1/public/document-types/MOTOR_ATTESTATION')->assertOk()->assertJsonPath('data.type_id', 'DOC-041');
    $this->getJson('/api/v1/public/document-types/DOC-219')->assertOk()->assertJsonFragment(['type_id' => 'CLM-19']);

    $packs = collect($this->getJson('/api/v1/public/document-packs?class=MOTOR&stage=NEW_BUSINESS')->assertOk()->json('data'));
    expect($packs->pluck('code'))->toContain('MOTOR_NEW_BUSINESS_PACK');
    $item = collect($packs->firstWhere('code', 'MOTOR_NEW_BUSINESS_PACK')['items'])->firstWhere('document_type_id', 'DOC-042');
    expect($item['label']['fr'])->toBe("Certificat d'assurance automobile")->and($item['requirement'])->toBe('REQUIRED');
    expect(collect($this->getJson('/api/v1/public/document-packs?class=MOTOR')->json('data'))->pluck('code'))->toContain('UNIVERSAL_CLAIM');

    $req = $this->getJson('/api/v1/public/document-requirements?product_type=MOTOR_COMPREHENSIVE')->assertOk()->json('data');
    expect($req['chain'])->toBe(['MOTOR_TPL', 'MOTOR_COMPREHENSIVE'])->and(collect($req['requirements'])->pluck('source')->unique()->values()->all())->toContain('BASELINE', 'OWN', 'INHERITED:MOTOR_TPL');
    expect(count($this->getJson('/api/v1/public/document-requirements')->assertOk()->json('data')))->toBe(DocumentProductType::count());
    $this->getJson('/api/v1/public/document-requirements?product_type=NOPE')->assertNotFound();
});
