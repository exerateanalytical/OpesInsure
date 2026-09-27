<?php

declare(strict_types=1);

use App\Application\Documents\Engine\DocumentTemplateService;
use App\Application\Documents\Security\EnforcementReadiness;
use App\Filament\Admin\Actions\LetterheadActions;
use App\Filament\Admin\Pages\LetterheadDesigner;
use App\Filament\Admin\Pages\OrganisationSettingsPage;
use App\Filament\Admin\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Filament\Admin\Resources\DocumentTemplates\Pages\EditDocumentTemplate;
use App\Filament\Admin\Resources\PhysicalSecurityAssets\Pages\CreatePhysicalSecurityAsset;
use App\Filament\Admin\Resources\PhysicalSecurityAssets\Pages\EditPhysicalSecurityAsset;
use App\Models\Carrier;
use App\Models\DocumentNumberingFamily;
use App\Models\DocumentSecurity\PhysicalSecurityAsset;
use App\Models\Letterhead\LetterheadAsset;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantBranch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

uses(RefreshDatabase::class);

/*
 | Web UI phase 4 — configuration screens: letterhead designer, template editor, physical security / seal,
 | organisation settings. Render, save, permission denial, specimen preview is a PDF.
 */

beforeEach(function () {
    $root = storage_path('framework/testing/disks/cfg-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    $this->tenant = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'Cfg Platform', 'slug' => 'cfg-'.Str::random(6), 'status' => 'ACTIVE',
        'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'fr']);
    $this->admin = makeAuthTestUser($this->tenant, [], 'PLATFORM_ADMIN');
    $this->checker = makeAuthTestUser($this->tenant, [], 'COMPLIANCE_ADMIN');
    $this->staff = makeAuthTestUser($this->tenant, [], 'TEST_ROLE');
    $this->carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Cfg Assurances', 'status' => 'ACTIVE'])->id,
        'cima_code' => 'CFG-'.Str::random(5), 'status' => 'ACTIVE', 'capabilities' => []]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function cfgPng(int $w = 200, int $h = 80): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 11, 42, 74));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

// ------------------------------------------------------------------ 1. Letterhead designer

it('renders the letterhead designer, saves a versioned letterhead with contact block and bilingual footer', function () {
    $this->actingAs($this->admin);
    Livewire::test(LetterheadDesigner::class, ['ownerType' => 'CARRIER', 'ownerId' => $this->carrier->id])->assertOk()
        ->assertSee('Contact block')->assertSee('Legal footer text')
        ->set('data.brand_color', '#123456')->set('data.registered_address', 'BP 1, Douala')->set('data.contact_phone', '+237699000000')
        ->set('data.contact_email', 'contact@cfg.test')->set('data.footer_text_fr', 'Société anonyme régie par le Code CIMA')->set('data.footer_text_en', 'Public limited company under the CIMA Code')
        ->set('data.authorized_by', 'DG Cfg')->set('data.authorized_on', now()->toDateString())->set('data.authorization_source', 'Letter 2026/01')
        ->call('save')->assertHasNoErrors();

    $a = LetterheadAsset::where('carrier_id', $this->carrier->id)->sole();
    expect($a->version)->toBe(1)->and($a->status)->toBe('ACTIVE')->and($a->contact_email)->toBe('contact@cfg.test')
        ->and($a->footerLines())->toContain('Société anonyme régie par le Code CIMA', 'Public limited company under the CIMA Code', 'Tel. +237699000000');
});

it('previews the unsaved letterhead as a secure-shell PDF (download action and uploaded logo)', function () {
    $this->actingAs($this->admin);
    Livewire::test(LetterheadDesigner::class, ['ownerType' => 'CARRIER', 'ownerId' => $this->carrier->id])
        ->set('data.brand_color', '#0B2A4A')->set('data.footer_text_en', 'Preview footer')
        ->callAction('preview')->assertFileDownloaded('letterhead-specimen.pdf');

    Storage::disk('local')->put('letterhead-uploads/logo.png', cfgPng());
    $lh = LetterheadActions::previewLetterhead('CARRIER', $this->carrier->id, 'Cfg Assurances', ['logo' => 'letterhead-uploads/logo.png', 'footer_text_en' => 'Preview footer', 'brand_color' => '#0B2A4A']);
    expect($lh['issuer']['logo'])->toStartWith('data:image/png;base64,')->and($lh['footer_lines'])->toBe(['Preview footer'])
        ->and(Storage::disk('local')->exists('letterhead-uploads/logo.png'))->toBeTrue(); // preview never consumes the upload
    $pdf = app(\App\Application\Documents\Engine\SecureShellRenderer::class)->specimen(LetterheadDesigner::PREVIEW_TYPE, 'Cfg Assurances', $lh);
    expect($pdf)->toStartWith('%PDF');
    expect(LetterheadAsset::count())->toBe(0);
});

it('denies the letterhead designer to users without an admin role', function () {
    $this->actingAs($this->staff);
    expect(LetterheadDesigner::canAccess())->toBeFalse();
    Livewire::test(LetterheadDesigner::class)->assertForbidden();
});

// ------------------------------------------------------------------ 2. Template editor

function cfgDraft($admin, string $carrierId): \App\Models\DocumentTemplate
{
    return app(DocumentTemplateService::class)->createDraft(['document_type_code' => 'POLICY_SCHEDULE', 'ownership' => 'INSURER', 'carrier_id' => $carrierId,
        'language' => 'BILINGUAL', 'content' => ['sections' => [['heading_fr' => 'Objet', 'heading_en' => 'Purpose', 'body_fr' => 'Police {policy_number}', 'body_en' => 'Policy {policy_number}']]]], $admin);
}

it('renders the template designer with the placeholder help, previews the unsaved sections and saves & submits', function () {
    $this->actingAs($this->admin);
    $t = cfgDraft($this->admin, $this->carrier->id);
    Livewire::test(EditDocumentTemplate::class, ['record' => $t->getRouteKey()])->assertOk()
        ->assertSee('{insured_name}')->assertSee('Insured / policyholder name')
        ->callAction('previewPdf')->assertFileDownloaded('template-POLICY_SCHEDULE-specimen.pdf');

    $pdf = DocumentTemplateResource::previewPdf($t, ['sections' => [['heading_en' => 'Changed', 'body_en' => 'For {insured_name}']]]);
    expect($pdf)->toStartWith('%PDF')->and($t->refresh()->status)->toBe('DRAFT');

    Livewire::test(EditDocumentTemplate::class, ['record' => $t->getRouteKey()])
        ->set('data.title_en', 'Schedule v2')->callAction('saveAndSubmit')->assertHasNoErrors();
    expect($t->refresh()->status)->toBe('REVIEW')->and($t->title_en)->toBe('Schedule v2');

    // Maker-checker: the author cannot approve; a second admin can.
    $this->actingAs($this->checker);
    Livewire::test(\App\Filament\Admin\Resources\DocumentTemplates\Pages\ViewDocumentTemplate::class, ['record' => $t->getRouteKey()])->assertOk()->callAction('approve');
    expect($t->refresh()->status)->toBe('APPROVED');
});

it('denies the template designer to users without document engine access', function () {
    $t = cfgDraft($this->admin, $this->carrier->id);
    $this->actingAs($this->staff);
    Livewire::test(EditDocumentTemplate::class, ['record' => $t->getRouteKey()])->assertForbidden();
});

// ------------------------------------------------------------------ 3. Physical security assets / seal

it('records a seal artwork with security features, previews it, shows enforcement status and needs a second admin to verify', function () {
    config(['document_security.enforce_controls' => false]);
    $this->actingAs($this->admin);
    Storage::disk('local')->put('document-security/seal-artwork/seal.png', cfgPng(300, 300));
    $asset = PhysicalSecurityAsset::create(['recorded_by' => $this->admin->id] + \App\Filament\Admin\Resources\PhysicalSecurityAssets\PhysicalSecurityAssetResource::prepare([
        'asset_kind' => 'SEAL_ARTWORK', 'seal_profile_code' => 'SEAL-01', 'name' => 'Corporate seal', 'artwork_path' => 'document-security/seal-artwork/seal.png',
        'supplier_name' => 'Imprimerie Test', 'status' => 'PENDING_VERIFICATION', 'security_features' => ['paper_stock_grade' => 'Secure 90', 'uv_ink' => 'Blue UV fibres']]));
    expect($asset->artwork_sha256)->toHaveLength(64)->and($asset->security_features['uv_ink'])->toBe('Blue UV fibres');

    $summary = EnforcementReadiness::summary();
    expect($summary['enforced'])->toBeFalse()->and($summary['types'])->not->toBeEmpty();

    Livewire::test(EditPhysicalSecurityAsset::class, ['record' => $asset->getRouteKey()])->assertOk()
        ->assertSee('DOCUMENT_ENFORCE_CONTROLS: ')->assertSee('OFF')->assertSee('GATE-10')->assertSee('data:image/png;base64,', false)
        ->assertFormSet(['security_features.paper_stock_grade' => 'Secure 90'])
        ->callAction('verify');
    expect($asset->refresh()->status)->toBe('PENDING_VERIFICATION'); // the recorder cannot verify

    $this->actingAs($this->checker);
    Livewire::test(EditPhysicalSecurityAsset::class, ['record' => $asset->getRouteKey()])->callAction('verify');
    expect($asset->refresh()->status)->toBe('VERIFIED')->and($asset->verified_by)->toBe($this->checker->id)
        ->and(\App\Application\Documents\Security\PhysicalSecurityRegistry::corporateSealArtwork(null))->toBeTrue();
    // Enforcement itself is never switched by this screen.
    expect(config('document_security.enforce_controls'))->toBeFalse();
});

it('saves security features from the create form and denies the screen to non-admins', function () {
    $this->actingAs($this->admin);
    Livewire::test(CreatePhysicalSecurityAsset::class)->assertOk()
        ->fillForm(['asset_kind' => 'HOLOGRAM_BATCH', 'physical_profile_code' => 'PS-03', 'name' => 'Holo batch 1', 'status' => 'PENDING_VERIFICATION',
            'security_features' => ['hologram_type' => '2D/3D foil', 'hologram_serial_format' => 'H-000000']])
        ->call('create')->assertHasNoFormErrors();
    expect(PhysicalSecurityAsset::where('name', 'Holo batch 1')->sole()->security_features['hologram_type'])->toBe('2D/3D foil');

    $this->actingAs($this->staff);
    Livewire::test(CreatePhysicalSecurityAsset::class)->assertForbidden();
});

// ------------------------------------------------------------------ 4. Organisation settings

it('renders organisation settings and saves user, organisation and branch settings; shows numbering prefixes', function () {
    $branch = TenantBranch::create(['tenant_id' => $this->tenant->id, 'code' => 'DLA', 'name' => 'Douala', 'status' => 'ACTIVE']);
    DocumentNumberingFamily::create(['tenant_id' => null, 'family_code' => 'CFGX', 'prefix' => 'CFX', 'include_year' => true, 'pad' => 5, 'document_type_codes' => [], 'status' => 'ACTIVE']);
    $this->actingAs($this->admin);
    $page = Livewire::test(OrganisationSettingsPage::class)->assertOk()->assertSee('Business hours')->assertSee('CFX-'.now()->format('Y').'-00001');
    $key = array_key_first($page->get('data.branches'));
    $page->set('data.locale', 'fr')->set('data.display_timezone', 'Africa/Lagos')->set('data.tenant_timezone', 'Africa/Douala')
        ->set("data.branches.{$key}.timezone", 'Africa/Lagos')
        ->call('save')->assertHasNoErrors();

    expect($this->admin->refresh()->locale)->toBe('fr')->and($this->admin->display_timezone)->toBe('Africa/Lagos');
    expect($this->tenant->refresh()->timezone)->toBe('Africa/Douala');
    expect($branch->refresh()->timezone)->toBe('Africa/Lagos');
});

it('lets a non-manager change only their own preferences', function () {
    $this->actingAs($this->staff);
    Livewire::test(OrganisationSettingsPage::class)->assertOk()->assertDontSee('Organisation timezone')
        ->set('data.locale', 'fr')->set('data.tenant_timezone', 'Africa/Lagos')->call('save');
    expect($this->staff->refresh()->locale)->toBe('fr')->and($this->tenant->refresh()->timezone)->toBeNull();
});
