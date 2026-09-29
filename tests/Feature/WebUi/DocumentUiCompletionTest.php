<?php

declare(strict_types=1);

use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Application\Documents\Security\DocumentSigner;
use App\Application\Documents\Security\TamperCheck;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\DocumentEngine\SigningKeysPage;
use App\Filament\Admin\Resources\DocumentTemplates\Pages\ListDocumentTemplates;
use App\Filament\Admin\Resources\GeneratedDocuments\GeneratedDocumentResource;
use App\Filament\Admin\Resources\GeneratedDocuments\Pages\ListGeneratedDocuments;
use App\Filament\Admin\Resources\GeneratedDocuments\Pages\ViewGeneratedDocument;
use App\Filament\Admin\Resources\VerificationLookups\Pages\ListVerificationLookups;
use App\Filament\Shared\Pages\InsurerLetterheadPage;
use App\Models\Carrier;
use App\Models\Document;
use App\Models\DocumentNumberingFamily;
use App\Models\DocumentStatusChange;
use App\Models\DocumentTemplate;
use App\Models\Letterhead\LetterheadAsset;
use App\Models\Party;
use App\Models\PublicVerificationLookup;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

uses(RefreshDatabase::class);

/*
 | Document UI completion: register filters, revoke/replace history, verification lookup log, tamper check,
 | public /verify states, signing keys & numbering screen, insurer letterhead in /insurer, pending templates.
 */

beforeEach(function () {
    $root = storage_path('framework/testing/disks/docui-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    $this->tenant = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'DocUi Platform', 'slug' => 'docui-'.Str::random(6), 'status' => 'ACTIVE',
        'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'fr']);
    $this->admin = makeAuthTestUser($this->tenant, ['documents.templates.manage'], 'PLATFORM_ADMIN');
    $this->staff = makeAuthTestUser($this->tenant, [], 'TEST_ROLE');
    $this->carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'DocUi Assurances', 'status' => 'ACTIVE'])->id,
        'cima_code' => 'DUI-'.Str::random(5), 'status' => 'ACTIVE', 'capabilities' => []]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app(TenantContext::class)->set($this->tenant->id);
});

function docUiDoc(array $attrs = []): Document
{
    $n = strtoupper(Str::random(6));

    if (isset($attrs['signature']) && is_array($attrs['signature'])) {
        $attrs['signature'] = json_encode($attrs['signature']); // not cast on the model: stored as JSON, read raw by the verifiers
    }
    $d = new Document;
    $d->forceFill($attrs + ['category' => 'POLICY', 'storage_key' => 'docs/'.$n.'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
        'sha256' => str_repeat('a', 64), 'document_type_code' => 'POLICY_SCHEDULE', 'document_origin' => 'SYSTEM', 'security_level' => 'CUSTOMER_PRIVATE',
        'status' => 'VALID', 'document_number' => 'OPS-SCH-2026-'.$n, 'verification_code' => 'VC'.$n, 'issuer_type' => 'PLATFORM', 'issued_at' => now(), 'language' => 'BILINGUAL'])->save();

    return $d->refresh();
}

function docUiSigningKey(): void
{
    $kp = sodium_crypto_sign_keypair();
    config(['document_security.signing.private_key' => base64_encode(sodium_crypto_sign_secretkey($kp)), 'document_security.signing.key_id' => 'test-key-1',
        'document_security.signing.key_environment' => app()->environment()]);
}

// ------------------------------------------------------------------ 1. Register: filters, history, lookups, tamper check

it('filters the document register by type, status, tier and issuer', function () {
    $this->actingAs($this->admin);
    $a = docUiDoc(['security_tier' => 'S3', 'issuer_type' => 'CARRIER', 'issuer_carrier_id' => $this->carrier->id]);
    $b = docUiDoc(['document_type_code' => 'MOTOR_INSURANCE_ATTESTATION', 'security_tier' => 'S2', 'status' => 'REVOKED']);

    Livewire::test(ListGeneratedDocuments::class)->assertOk()->assertCanSeeTableRecords([$a, $b])
        ->filterTable('document_type_code', 'POLICY_SCHEDULE')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
        ->resetTableFilters()->filterTable('status', 'REVOKED')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])
        ->resetTableFilters()->filterTable('security_tier', 'S3')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
        ->resetTableFilters()->filterTable('issuer_carrier_id', $this->carrier->id)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
        ->resetTableFilters()->filterTable('issuer_type', 'CARRIER')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
});

it('shows the revoke / replace history and the verification lookups on the document page', function () {
    $this->actingAs($this->admin);
    $old = docUiDoc(['status' => 'REPLACED']);
    $new = docUiDoc(['supersedes_document_id' => $old->id]);
    $old->forceFill(['superseded_by_document_id' => $new->id])->save();
    DocumentStatusChange::create(['document_id' => $old->id, 'action' => 'REPLACE', 'reason' => 'Wrong vehicle registration', 'replacement_document_id' => $new->id,
        'status' => 'APPROVED', 'requested_by' => $this->staff->id, 'decided_by' => $this->admin->id, 'decided_at' => now()]);
    $this->get('/verify?code='.$old->verification_code)->assertOk();

    Livewire::test(ViewGeneratedDocument::class, ['record' => $old->getRouteKey()])->assertOk()
        ->assertSee('Revoke / replace history')->assertSee('Wrong vehicle registration')->assertSee($new->document_number)
        ->assertSee('Verification lookups')->assertSee('QR_PAGE');
});

it('lists public verification lookups read-only, with hashes only', function () {
    $this->actingAs($this->admin);
    $d = docUiDoc();
    $this->get('/verify?code='.$d->verification_code)->assertOk();
    $this->get('/verify?code=NOPE-NOPE')->assertOk();
    expect(PublicVerificationLookup::count())->toBe(2);

    Livewire::test(ListVerificationLookups::class)->assertOk()->assertCanSeeTableRecords(PublicVerificationLookup::all())
        ->filterTable('matched', true)->assertCanSeeTableRecords(PublicVerificationLookup::whereNotNull('document_id')->get())
        ->assertCanNotSeeTableRecords(PublicVerificationLookup::whereNull('document_id')->get())
        ->assertDontSee('NOPE-NOPE');

    $this->actingAs($this->staff);
    Livewire::test(ListVerificationLookups::class)->assertForbidden();
});

it('tamper-checks an uploaded PDF against the registry hash and the platform signature', function () {
    docUiSigningKey();
    $this->actingAs($this->admin);
    $pdf = "%PDF-1.4 genuine document\n%%EOF";
    $hash = hash('sha256', $pdf);
    $signature = app(DocumentSigner::class)->sign(['document_id' => 'x', 'final_file_hash' => $hash, 'snapshot_hash' => null]);
    $doc = docUiDoc(['sha256' => $hash, 'signature' => $signature]);

    $check = app(TamperCheck::class);
    $ok = $check->against($doc, $pdf);
    expect($ok['verdict'])->toBe('AUTHENTIC')->and($ok['hash'])->toBe('MATCH')->and($ok['signature'])->toBe('VALID')->and($ok['signed_hash'])->toBe('MATCH');
    $bad = $check->against($doc, $pdf.' altered');
    expect($bad['verdict'])->toBe('TAMPERED')->and($bad['hash'])->toBe('MISMATCH');
    expect($check->identify($pdf)['document_id'])->toBe($doc->id)
        ->and($check->identify('%PDF unknown')['verdict'])->toBe('UNKNOWN');

    // A forged signature on an unchanged file is reported, not accepted.
    $forged = $signature;
    $forged['value'] = base64_encode(str_repeat("\0", 64));
    $doc2 = docUiDoc(['sha256' => $hash, 'signature' => $forged]);
    expect($check->against($doc2, $pdf)['verdict'])->toBe('SIGNATURE_INVALID');

    // Through the register: the file is consumed and the result is audited.
    Storage::disk('local')->put('tamper-checks/in.pdf', $pdf.' altered');
    $r = GeneratedDocumentResource::runTamperCheck(['file' => 'tamper-checks/in.pdf'], $doc);
    expect($r['verdict'])->toBe('TAMPERED')->and(Storage::disk('local')->exists('tamper-checks/in.pdf'))->toBeFalse();
    expect(\Illuminate\Support\Facades\DB::table('audit_events')->where('action', 'document.tamper_check')->count())->toBeGreaterThan(0);

    Livewire::test(ListGeneratedDocuments::class)->assertTableActionExists('tamperCheck')->assertActionExists(\Filament\Actions\Testing\TestAction::make('tamperIdentify')->table());
});

// ------------------------------------------------------------------ 2. Public /verify page

it('shows clear bilingual VALID, EXPIRED, REVOKED and REPLACED states with masked data', function () {
    $valid = docUiDoc();
    $expired = docUiDoc(['valid_until' => now()->subDay()]);
    $revoked = docUiDoc(['status' => 'REVOKED', 'status_changed_at' => now()]);
    $next = docUiDoc();
    $replaced = docUiDoc(['status' => 'REPLACED', 'superseded_by_document_id' => $next->id]);

    $this->get('/verify?code='.$valid->verification_code)->assertOk()->assertSee('data-status="VALID"', false)->assertSee('VALID · VALIDE')
        ->assertSee('Ce document est authentique et en vigueur.')->assertDontSee($valid->document_number)
        ->assertSee(\App\Application\Documents\Security\DocumentVerificationPresenter::mask($valid->document_number, 4))
        ->assertSee('width=device-width', false)->assertSee('@media (max-width:560px)', false);
    $this->get('/verify?code='.$expired->verification_code)->assertOk()->assertSee('data-status="EXPIRED"', false)->assertSee('EXPIRED · EXPIRÉ');
    $this->get('/verify?code='.$revoked->verification_code)->assertOk()->assertSee('data-status="REVOKED"', false)->assertSee('REVOKED · RÉVOQUÉ')->assertSee('Revoked on / Révoqué le');
    $this->get('/verify?code='.$replaced->verification_code)->assertOk()->assertSee('data-status="REPLACED"', false)->assertSee('REPLACED · REMPLACÉ')
        ->assertSee('Replaced by / Remplacé par');
    $this->get('/verify?code=UNKNOWN-CODE')->assertOk()->assertSee('data-status="NOT_FOUND"', false)->assertSee('Certificate not found');
});

// ------------------------------------------------------------------ 3. Signing keys and numbering families

it('shows the signing keys read-only (id, public key, status) and the numbering families', function () {
    docUiSigningKey();
    DocumentNumberingFamily::create(['family_code' => 'DUI_FAMILY', 'prefix' => 'DUI', 'include_year' => true, 'pad' => 6, 'document_type_codes' => ['POLICY_SCHEDULE'], 'status' => 'ACTIVE']);
    $this->actingAs($this->admin);
    $keys = SigningKeysPage::keys();
    expect($keys)->toHaveCount(1)->and($keys[0]['key_id'])->toBe('test-key-1')->and($keys[0]['status'])->toBe('ACTIVE')
        ->and($keys[0]['public_key'])->toBe(app(DocumentSigner::class)->publicKeys()['test-key-1']);

    Livewire::test(SigningKeysPage::class)->assertOk()->assertSee('test-key-1')->assertSee($keys[0]['public_key'])->assertSee('ACTIVE')
        ->assertSee('DUI_FAMILY')->assertDontSee(config('document_security.signing.private_key'));

    config(['document_security.signing.private_key' => null]);
    Livewire::test(SigningKeysPage::class)->assertOk()->assertSee('CONFIG_REQUIRED');

    $this->actingAs($this->staff);
    Livewire::test(SigningKeysPage::class)->assertForbidden();
});

// ------------------------------------------------------------------ 4. Insurer letterhead in /insurer

function docUiInsurerUser(Tenant $tenant, string $role, ?string $carrierId, array $extra = []): User
{
    $u = User::create(['id' => (string) Str::uuid(), 'full_name' => 'Ins '.Str::random(5), 'email' => Str::lower(Str::random(10)).'@ins.test',
        'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(),
        'permissions' => \App\Application\Identity\RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);
    if ($extra !== []) { // owner rule 2026-09-29: letterhead is permission-gated; extra grants on their own role
        $m->roles()->attach(Role::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'code' => 'DOCUI-'.Str::random(8), 'permissions' => $extra, 'is_system' => false])->id);
    }

    return $u;
}

it('lets insurer admins manage their own letterhead in /insurer with maker-checker, feeding the public logo_url', function () {
    config(['letterheads.maker_checker' => false]); // the insurer page forces maker-checker regardless
    $ins = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'DocUi Carrier Tenant', 'slug' => 'duic-'.Str::random(5), 'status' => 'ACTIVE',
        'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $other = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Other Assurances', 'status' => 'ACTIVE'])->id,
        'cima_code' => 'OTH-'.Str::random(5), 'status' => 'ACTIVE', 'capabilities' => []]);
    $maker = docUiInsurerUser($ins, 'CARRIER_ADMIN', $this->carrier->id, ['documents.letterheads.manage']); // owner rule 2026-09-29: permission-gated, not role-gated
    $checker = docUiInsurerUser($ins, 'CARRIER_SUPER_ADMIN', $this->carrier->id, ['documents.letterheads.approve']);
    $staff = docUiInsurerUser($ins, 'CARRIER_STAFF', $this->carrier->id);

    Filament::setCurrentPanel(Filament::getPanel('insurer'));
    app(TenantContext::class)->set($ins->id);

    $this->actingAs($staff);
    expect(InsurerLetterheadPage::canAccess())->toBeFalse();

    $this->actingAs($maker);
    expect(InsurerLetterheadPage::canAccess())->toBeTrue();
    $png = (function () {
        $img = imagecreatetruecolor(300, 120);
        imagefill($img, 0, 0, imagecolorallocate($img, 11, 42, 74));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    })();
    Livewire::test(InsurerLetterheadPage::class)->assertOk()->assertDontSee('Letterhead of')
        ->set('ownerId', $other->id) // cannot be switched from the browser
        ->set('data.logo', [UploadedFile::fake()->createWithContent('logo.png', $png)])
        ->set('data.public_display', true)->set('data.brand_color', '#0B2A4A')
        ->set('data.authorized_by', 'DG DocUi')->set('data.authorized_on', now()->toDateString())->set('data.authorization_source', 'Board minute 2026/07')
        ->call('save')->assertHasNoErrors();

    $v = LetterheadAsset::where('owner_type', 'CARRIER')->sole();
    expect($v->carrier_id)->toBe($this->carrier->id)->and($v->status)->toBe('PENDING_APPROVAL')->and($v->public_display)->toBeTrue()->and($v->logo_path)->not->toBeNull();
    expect(LetterheadResolver::carrierLogoUrl($this->carrier->id))->toBeNull(); // nothing public before approval

    // The maker cannot approve (no documents.letterheads.approve; the service also refuses the uploader).
    Livewire::test(InsurerLetterheadPage::class)->assertActionHidden('approvePending');
    expect($v->refresh()->status)->toBe('PENDING_APPROVAL');

    $this->actingAs($checker);
    Livewire::test(InsurerLetterheadPage::class)->assertActionVisible('approvePending')->callAction('approvePending');
    expect($v->refresh()->status)->toBe('ACTIVE')->and($v->approved_by)->toBe($checker->id);
    expect(LetterheadResolver::carrierLogoUrl($this->carrier->id))->toContain('/api/v1/public/letterheads/'.$v->id.'/logo');
    expect(LetterheadAsset::where('carrier_id', $other->id)->exists())->toBeFalse();

    // Same page in the browser, served by the insurer panel.
    $this->get('/insurer/letterhead')->assertOk()->assertSee('Letterhead &amp; logo', false);
});

// ------------------------------------------------------------------ 5. Templates pending approval

it('makes the templates in REVIEW easy to find: Pending approval tab and filter with approve & publish', function () {
    $this->actingAs($this->admin);
    app(TenantContext::class)->set($this->tenant->id);
    $svc = app(\App\Application\Documents\Engine\DocumentTemplateService::class);
    $review = $svc->createDraft(['document_type_code' => 'POLICY_SCHEDULE', 'ownership' => 'INSURER', 'carrier_id' => $this->carrier->id, 'language' => 'BILINGUAL',
        'content' => ['sections' => [['heading_en' => 'A', 'body_en' => 'B']]]], $this->staff);
    $review->forceFill(['status' => 'REVIEW'])->save();
    $draft = $svc->createDraft(['document_type_code' => 'POLICY_SCHEDULE', 'ownership' => 'INSURER', 'carrier_id' => $this->carrier->id, 'language' => 'FR',
        'content' => ['sections' => [['heading_fr' => 'A', 'body_fr' => 'B']]]], $this->staff);

    expect(\App\Filament\Admin\Resources\DocumentTemplates\DocumentTemplateResource::getNavigationBadge())
        ->toBe((string) DocumentTemplate::where('status', 'REVIEW')->count());
    Livewire::test(ListDocumentTemplates::class)->assertOk()->assertSet('activeTab', 'pending')->set('tableRecordsPerPage', 50)->assertSee('Pending approval')
        ->assertCanSeeTableRecords([$review])->assertCanNotSeeTableRecords([$draft])
        ->set('activeTab', 'all')->assertCanSeeTableRecords([$review, $draft])
        ->filterTable('pending_approval')->assertCanSeeTableRecords([$review])->assertCanNotSeeTableRecords([$draft])
        ->assertTableActionVisible('templateApprove', $review);

    // The system-seeded provider templates get the one-step Approve & publish button in the same list.
    $seeded = DocumentTemplate::where('status', 'REVIEW')->where('created_by', \Database\Seeders\ProviderDocumentTemplateSeeder::SYSTEM_USER_ID)->first();
    expect($seeded)->not->toBeNull();
    Livewire::test(ListDocumentTemplates::class)->set('tableRecordsPerPage', 50)->assertCanSeeTableRecords([$seeded])->assertTableActionVisible('templateApprovePublish', $seeded);
});
