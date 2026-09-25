<?php

declare(strict_types=1);

// REQ-SEED-002 (official register enrichment), REQ-SEED-006 (unknown facts stay null), REQ-SEED-003 (no name/authorization rewrite)

use App\Models\Carrier;
use App\Models\Directory\InstitutionOffice;
use App\Models\Directory\InstitutionProfile;
use App\Models\InsurerAuthorization;
use Database\Seeders\CameroonInsuranceRegisterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function seedDirectory(): void
{
    test()->seed(CameroonInsuranceRegisterSeeder::class);
    test()->artisan('opesinsure:seed-insurer-directory')->assertSuccessful();
}

it('matches all 29 directory entries to the existing register without creating, deleting or renaming carriers', function () {
    test()->seed(CameroonInsuranceRegisterSeeder::class);
    $before = Carrier::orderBy('id')->get(['id', 'legal_name', 'trade_name', 'short_name'])->toArray();
    $authorizations = InsurerAuthorization::count();
    $names = DB::table('organization_name_histories')->count();
    $irAuth = DB::table('insurer_regulatory_authorizations')->count();

    test()->artisan('opesinsure:seed-insurer-directory')->assertSuccessful();

    expect(Carrier::orderBy('id')->get(['id', 'legal_name', 'trade_name', 'short_name'])->toArray())->toBe($before)
        ->and(InstitutionProfile::count())->toBe(29)
        ->and(InsurerAuthorization::count())->toBe($authorizations)
        ->and(DB::table('insurer_regulatory_authorizations')->count())->toBe($irAuth)
        ->and(DB::table('organization_name_histories')->count())->toBe($names);

    // Each profile sits on the carrier with the same canonical ID and licence branch.
    InstitutionProfile::with('carrier')->get()->each(fn ($p) => expect($p->carrier->canonical_id)->toBe($p->directory_id));
});

it('stores contacts, HQ, branches, sources and verification; nulls stay null; regulatory reference is a pending note', function () {
    seedDirectory();

    $activa = Carrier::where('canonical_id', 'CM-INS-IARD-001')->firstOrFail();
    $p = InstitutionProfile::where('carrier_id', $activa->id)->firstOrFail();
    expect($p->website)->toBe('https://cameroun.group-activa.com/')
        ->and($p->po_box)->toBe('12970 Douala')
        ->and($p->phones)->toContain('+237 233 50 13 00')
        ->and($p->emails)->toBe(['service.clients@group-activa.com'])
        ->and($p->verification_status)->toBe('VERIFIED')
        ->and($p->sources)->toHaveCount(2)
        ->and($p->regulatory_reference_note)->toBeNull();

    expect(InstitutionOffice::where('carrier_id', $activa->id)->count())->toBe(8)
        ->and(InstitutionOffice::where('carrier_id', $activa->id)->where('office_type', 'HEAD_OFFICE')->count())->toBe(1)
        ->and(DB::table('party_addresses')->where('party_id', $activa->party_id)->where('type', 'HEAD_OFFICE')->value('city'))->toBe('Douala');

    $axa = InstitutionProfile::where('directory_id', 'CM-INS-IARD-006')->firstOrFail();
    expect($axa->website)->toBeNull()->and($axa->verification_status)->toBe('PARTIALLY_VERIFIED');

    $afri = InstitutionProfile::where('directory_id', 'CM-INS-IARD-003')->firstOrFail();
    expect($afri->po_box)->toBeNull()
        ->and($afri->regulatory_reference_note)->toBe('Agrément 0000627/MINFI du 11/08/2025')
        ->and($afri->regulatory_reference_status)->toBe('PENDING_EVIDENCE_REVIEW');

    expect(InstitutionOffice::count())->toBe(63)
        ->and(DB::table('party_contacts')->count())->toBe(0); // contacts never enter the customer identity table
});

it('is idempotent', function () {
    seedDirectory();
    $counts = [InstitutionProfile::count(), InstitutionOffice::count(), DB::table('party_addresses')->count()];

    test()->artisan('opesinsure:seed-insurer-directory')->assertSuccessful();

    expect([InstitutionProfile::count(), InstitutionOffice::count(), DB::table('party_addresses')->count()])->toBe($counts);
});

it('exposes contacts, website, HQ, branches and verification on the public institutions API', function () {
    seedDirectory();

    $list = $this->getJson('/api/v1/public/institutions?type=insurer')->assertOk();
    expect($list->json('data'))->toHaveCount(29);
    $row = $list->json('data.0');
    expect($row['directory_id'])->toBe('CM-INS-IARD-001')
        ->and($row['city'])->toBe('Douala')
        ->and($row['phone'])->toBe('+237 233 50 13 00')
        ->and($row['email'])->toBe('service.clients@group-activa.com')
        ->and($row['website'])->toBe('https://cameroun.group-activa.com/')
        ->and($row['contacts'])->toMatchArray(['po_box' => '12970 Douala', 'website' => 'https://cameroun.group-activa.com/'])
        ->and($row['head_office'])->toBe(['city' => 'Douala', 'address' => 'Rue Prince de Galles, Akwa, Douala', 'po_box' => '12970 Douala'])
        ->and($row['branch_count'])->toBe(8)
        ->and($row['branches'][0])->toMatchArray(['name' => 'Siège Social Douala', 'type' => 'HEAD_OFFICE', 'city' => 'Douala'])
        ->and($row['verification_status'])->toBe('VERIFIED')
        ->and($row['verified_at'])->toBe('2026-09-25')
        ->and($row['sources'])->toHaveCount(2)
        ->and($row)->not->toHaveKey('regulatory_reference_note');

    $axa = Carrier::where('canonical_id', 'CM-INS-IARD-006')->firstOrFail();
    $this->getJson("/api/v1/public/institutions/{$axa->id}")->assertOk()
        ->assertJsonPath('data.website', null)
        ->assertJsonPath('data.branches', [])
        ->assertJsonPath('data.branch_count', 0)
        ->assertJsonPath('data.verification_status', 'PARTIALLY_VERIFIED')
        ->assertJsonPath('data.head_office.city', 'Douala');
});

it('shows directory details on the website providers page', function () {
    seedDirectory();
    Cache::flush();

    $this->get('/providers?type=insurer')->assertOk()
        ->assertSee('cameroun.group-activa.com')
        ->assertSee('+237 233 50 13 00')
        ->assertSee('8 offices')
        ->assertSee('Head office: Douala')
        ->assertSee('Verified');
});

// Owner decision: admin-controlled verification status and EN/FR labels.
it('returns the admin-controlled verification label and seeds default EN/FR labels', function () {
    seedDirectory();

    $belife = Carrier::where('canonical_id', 'CM-INS-LIFE-004')->firstOrFail();
    $this->getJson("/api/v1/public/institutions/{$belife->id}")->assertOk()
        ->assertJsonPath('data.verification_status', 'VERIFIED_NETWORK_SHARED_WITH_GROUP')
        ->assertJsonPath('data.verification_label', ['en' => 'Verified (group network)', 'fr' => 'Vérifié (réseau du groupe)']);

    $admin = directoryAdmin();
    app(App\Application\Directory\InstitutionDirectoryService::class)->updateLabel('VERIFIED_NETWORK_SHARED_WITH_GROUP', 'Group-verified', 'Vérifié groupe', $admin);
    test()->artisan('opesinsure:seed-insurer-directory')->assertSuccessful(); // never overwrites the admin label

    $this->getJson("/api/v1/public/institutions/{$belife->id}")->assertJsonPath('data.verification_label.en', 'Group-verified');
    expect(DB::table('audit_log')->where('action', 'institution_directory.label_updated')->count())->toBe(1);
    $this->get('/providers?type=insurer')->assertSee('Group-verified');
});

it('lets an admin change status, contacts, HQ and branches, audited, and the seeder keeps the edit', function () {
    seedDirectory();
    $axa = Carrier::where('canonical_id', 'CM-INS-IARD-006')->firstOrFail();
    $service = app(App\Application\Directory\InstitutionDirectoryService::class);

    $service->update($axa, [
        'verification_status' => 'VERIFIED', 'website' => 'https://www.axa.cm/', 'phones' => ['+237 233 42 31 71'], 'emails' => ['axa.cameroun@axa.cm'],
        'hq_city' => 'Douala', 'hq_address' => '309 Rue Bebey Eyidi',
        'branches' => [['name' => 'Siège', 'type' => 'HEAD_OFFICE', 'city' => 'Douala'], ['name' => 'Yaoundé', 'type' => 'DIRECT_BRANCH', 'city' => 'Yaoundé']],
    ], directoryAdmin(), 'Confirmed by phone with AXA');

    test()->artisan('opesinsure:seed-insurer-directory')->assertSuccessful();

    $p = InstitutionProfile::where('carrier_id', $axa->id)->firstOrFail();
    expect($p->verification_status)->toBe('VERIFIED')->and($p->website)->toBe('https://www.axa.cm/')
        ->and($p->sources)->not->toBeEmpty()->and($p->admin_edited_at)->not->toBeNull()
        ->and(InstitutionOffice::where('carrier_id', $axa->id)->count())->toBe(2);
    $audit = DB::table('audit_log')->where('action', 'institution_directory.updated')->where('subject_id', $axa->id)->first();
    expect($audit)->not->toBeNull()->and(json_decode($audit->metadata, true)['reason'])->toBe('Confirmed by phone with AXA')->and(json_decode($audit->metadata, true)['sources'])->not->toBeEmpty();

    expect(fn () => $service->update($axa, ['verification_status' => 'MADE_UP'], directoryAdmin('+237670009902'), 'x'))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('opens the admin directory screens for platform admins only', function () {
    seedDirectory();
    $this->actingAs(directoryAdmin(), 'web');
    $this->get('/admin/institution-directory/institution-profiles')->assertOk()->assertSee('CM-INS-IARD-001');
    $this->get('/admin/institution-directory/institution-verification-labels')->assertOk()->assertSee('Verified (group network)');
});

function directoryAdmin(string $phone = '+237670009901'): App\Models\User
{
    $tenant = App\Models\Tenant::firstOrCreate(['legal_name' => 'Directory Admin Tenant'], ['type' => 'CARRIER', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'fr']);

    return makeMobileTenantStaffUser($tenant, $phone, 'PLATFORM_ADMIN');
}
