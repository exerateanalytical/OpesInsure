<?php

declare(strict_types=1);

/**
 * Portal agent P4 (owner decision 2026-09-29: /insurer writable, D4 lifted; every screen gated by the API permission in
 * the portal tenant + own carrier — docs/spec/PORTAL_WRITE_RULES.md): reinsurance & co-insurance workbench, letterhead,
 * document register, staff invitations and branches, used in /insurer for the insurer's own records only.
 */

use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\RiskTransfer\Reinsurers;
use App\Filament\Admin\Pages\RiskTransfer\ReinsuranceTreaties;
use App\Filament\Admin\Resources\Invitations\Pages\CreateInvitation;
use App\Filament\Shared\Pages\InsurerLetterheadPage;
use App\Filament\Shared\Pages\PortalOrganisationSettings;
use App\Models\{Carrier, Document, Party, Role, Tenant, TenantInvitation, TenantMembership, User};
use App\Models\Letterhead\LetterheadAsset;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function p4User(Tenant $tenant, ?string $carrierId, array $permissions, string $role = 'REINSURANCE_OFFICER'): User
{
    $u = User::create(['full_name' => 'P4 '.Str::random(5), 'email' => Str::lower(Str::random(10)).'@p4.test', 'phone_e164' => '+2376'.random_int(10000000, 99999999),
        'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $m->roles()->attach(Role::create(['tenant_id' => $tenant->id, 'code' => 'P4-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function p4As(User $u, string $tenantId): void
{
    test()->actingAs($u);
    Filament::setCurrentPanel(Filament::getPanel('insurer'));
    app(TenantContext::class)->set($tenantId);
}

function p4Carrier(string $name): Carrier
{
    return Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => $name, 'status' => 'ACTIVE'])->id,
        'cima_code' => 'P4-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => []]);
}

beforeEach(function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $f['tenant'];
    $this->carrier = $f['carrier'];
    // Another insurer on its own tenant, with its own treaty.
    $this->otherTenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'P4 Other Insurer', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $this->otherCarrier = p4Carrier('P4 Other Assurances');
    app(\App\Application\Reinsurance\TreatyService::class)->createTreaty($this->otherTenant->id, ['code' => 'FOREIGN-QS', 'name' => 'Foreign quota share', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF']);
});

it('gates the reinsurance workbench in /insurer by the API permissions: none = 403, read-only = no write buttons', function () {
    $none = p4User($this->tenant, $this->carrier->id, ['carrier.dashboard.read'], 'CARRIER_STAFF');
    $this->actingAs($none)->get('/insurer/risk-transfer/treaties')->assertForbidden();
    $this->actingAs($none)->get('/insurer/risk-transfer/coinsurance')->assertForbidden();

    $this->flushSession();
    $reader = p4User($this->tenant, $this->carrier->id, ['reinsurance.treaties.view', 'coinsurance.view']);
    $this->actingAs($reader)->get('/insurer/risk-transfer/treaties')->assertOk()->assertDontSee('FOREIGN-QS');
    $this->actingAs($reader)->get('/insurer/risk-transfer/reinsurers')->assertOk();
    $this->actingAs($reader)->get('/insurer/risk-transfer/coinsurance')->assertOk();
    $this->actingAs($reader)->get('/insurer/risk-transfer/facultative')->assertForbidden(); // reinsurance.facultative.view not held

    p4As($reader, $this->tenant->id);
    Livewire::test(ReinsuranceTreaties::class)->assertOk()->assertActionHidden(TestAction::make('treatyCreate')->table())->assertActionHidden(TestAction::make('cessionCede')->table());
    Livewire::test(Reinsurers::class)->assertActionHidden(TestAction::make('reinsurerCreate')->table());
});

it('lets an insurer create and activate its own treaty in /insurer with four eyes, never seeing another insurer\'s', function () {
    $maker = p4User($this->tenant, $this->carrier->id, ['reinsurance.treaties.view', 'reinsurance.treaties.manage', 'reinsurance.reinsurers.manage', 'reinsurance.treaties.approve']);
    $checker = p4User($this->tenant, $this->carrier->id, ['reinsurance.treaties.view', 'reinsurance.treaties.approve', 'reinsurance.reinsurers.approve_security'], 'CARRIER_SUPER_ADMIN');

    p4As($maker, $this->tenant->id);
    Livewire::test(Reinsurers::class)->callAction(TestAction::make('reinsurerCreate')->table(), ['code' => 'SCOR', 'name' => 'Scor Re', 'role' => 'REINSURER'])
        ->assertNotified(__('risk_transfer_actions.reinsurerCreate.done'));
    $scor = DB::table('reinsurers')->where('tenant_id', $this->tenant->id)->where('code', 'SCOR')->first();

    p4As($checker, $this->tenant->id);
    Livewire::test(Reinsurers::class)->callAction(TestAction::make('reinsurerSecurity')->table($scor->id), ['approved_security_status' => 'TENANT_APPROVED', 'reason' => 'Board approved list'])
        ->assertNotified(__('risk_transfer_actions.reinsurerSecurity.done'));

    p4As($maker, $this->tenant->id);
    Livewire::test(ReinsuranceTreaties::class)->assertDontSee('FOREIGN-QS')
        ->callAction(TestAction::make('treatyCreate')->table(), ['code' => 'QS-P4', 'name' => 'Quota share P4', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF', 'underwriting_year' => 2026])
        ->assertNotified(__('risk_transfer_actions.treatyCreate.done'));
    $treaty = DB::table('reinsurance_treaties')->where('code', 'QS-P4')->first();
    expect($treaty->tenant_id)->toBe($this->tenant->id);
    Livewire::test(ReinsuranceTreaties::class)->callAction(TestAction::make('treatyAddVersion')->table($treaty->id), [
        'effective_from' => now()->toDateString(), 'cession_percent' => 40, 'participants' => [['reinsurer_id' => $scor->id, 'share_percent' => 100, 'is_lead' => true]],
    ])->assertNotified(__('risk_transfer_actions.treatyAddVersion.done'));
    $version = DB::table('reinsurance_treaty_versions')->where('treaty_id', $treaty->id)->first();

    // Four eyes: the maker holds the approve permission but cannot activate their own version.
    Livewire::test(ReinsuranceTreaties::class)->callAction(TestAction::make('treatyActivate')->table($treaty->id), ['version_id' => $version->id, 'reason' => 'Signed'])
        ->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('reinsurance_treaty_versions')->where('id', $version->id)->value('status'))->toBe('DRAFT');

    p4As($checker, $this->tenant->id);
    Livewire::test(ReinsuranceTreaties::class)->callAction(TestAction::make('treatyActivate')->table($treaty->id), ['version_id' => $version->id, 'reason' => 'Signed slip on file'])
        ->assertNotified(__('risk_transfer_actions.treatyActivate.done'));
    expect(DB::table('reinsurance_treaties')->where('id', $treaty->id)->value('status'))->toBe('ACTIVE');

    // The other insurer's treaty is untouched and never listed.
    expect(DB::table('reinsurance_treaties')->where('code', 'FOREIGN-QS')->value('status'))->toBe('DRAFT');
});

it('uploads the insurer\'s own letterhead in /insurer by permission (manage / approve), maker-checker, never another carrier\'s', function () {
    $staff = p4User($this->tenant, $this->carrier->id, ['carrier.dashboard.read'], 'CARRIER_ADMIN'); // admin role name alone is not enough
    $maker = p4User($this->tenant, $this->carrier->id, ['documents.letterheads.manage'], 'CARRIER_STAFF');
    $checker = p4User($this->tenant, $this->carrier->id, ['documents.letterheads.approve'], 'CARRIER_STAFF');

    p4As($staff, $this->tenant->id);
    expect(InsurerLetterheadPage::canAccess())->toBeFalse();

    p4As($maker, $this->tenant->id);
    expect(InsurerLetterheadPage::canAccess())->toBeTrue();
    $png = (function () {
        $img = imagecreatetruecolor(300, 120);
        imagefill($img, 0, 0, imagecolorallocate($img, 11, 42, 74));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    })();
    Livewire::test(InsurerLetterheadPage::class)->set('ownerId', $this->otherCarrier->id)
        ->set('data.logo', [UploadedFile::fake()->createWithContent('logo.png', $png)])->set('data.public_display', true)->set('data.brand_color', '#0B2A4A')
        ->set('data.authorized_by', 'DG P4')->set('data.authorized_on', now()->toDateString())->set('data.authorization_source', 'Board minute 2026/09')
        ->call('save')->assertHasNoErrors()
        ->assertActionHidden('approvePending'); // manage only: cannot approve
    $v = LetterheadAsset::where('owner_type', 'CARRIER')->sole();
    expect($v->carrier_id)->toBe($this->carrier->id)->and($v->status)->toBe('PENDING_APPROVAL');

    p4As($checker, $this->tenant->id);
    Livewire::test(InsurerLetterheadPage::class)->assertActionVisible('approvePending')->callAction('approvePending');
    expect($v->refresh()->status)->toBe('ACTIVE');
    expect(LetterheadAsset::where('carrier_id', $this->otherCarrier->id)->exists())->toBeFalse();
    // The checker cannot upload.
    Livewire::test(InsurerLetterheadPage::class)->call('save')->assertForbidden();
});

it('invites a staff member in /insurer into its own tenant and carrier, refusing escalation and other insurers\' invitations', function () {
    $reader = p4User($this->tenant, $this->carrier->id, ['carrier.dashboard.read'], 'CARRIER_ADMIN');
    $this->actingAs($reader)->get('/insurer/invitations')->assertForbidden();

    $this->flushSession();
    $admin = p4User($this->tenant, $this->carrier->id, [...\App\Application\Identity\RoleCatalogue::defaultPermissions('CARRIER_STAFF'), 'identity.invite'], 'CARRIER_ADMIN');
    $this->actingAs($admin)->get('/insurer/invitations')->assertOk();
    $foreign = TenantInvitation::create(['tenant_id' => $this->otherTenant->id, 'recipient_email' => 'x@other.test', 'role_code' => 'CARRIER_STAFF', 'token_hash' => hash('sha256', 'x'),
        'status' => 'PENDING', 'expires_at' => now()->addDay(), 'invited_by' => $admin->id]);
    expect($this->actingAs($admin)->get('/insurer/invitations/'.$foreign->id)->status())->toBeIn([403, 404]);

    p4As($admin, $this->tenant->id);
    Livewire::test(CreateInvitation::class)
        ->fillForm(['recipient_email' => 'new.staff@p4.test', 'role_code' => 'CARRIER_STAFF', 'ttl_hours' => 72])
        ->set('data.tenant_id', $this->otherTenant->id) // forged: ignored, the portal tenant is used
        ->call('create')->assertHasNoFormErrors();
    $inv = TenantInvitation::where('recipient_email', 'new.staff@p4.test')->sole();
    expect($inv->tenant_id)->toBe($this->tenant->id)->and($inv->carrier_id)->toBe($this->carrier->id)->and($inv->status)->toBe('PENDING');

    // Role escalation refused (InvitationService / PlatformAuthority::assertMayGrant): the inviter lacks the admin permissions.
    Livewire::test(CreateInvitation::class)->fillForm(['recipient_email' => 'boss@p4.test', 'role_code' => 'CARRIER_SUPER_ADMIN', 'ttl_hours' => 72])->call('create');
    expect(TenantInvitation::where('recipient_email', 'boss@p4.test')->exists())->toBeFalse();
});

it('adds a branch in /insurer only with tenant.manage', function () {
    $reader = p4User($this->tenant, $this->carrier->id, ['carrier.dashboard.read'], 'CARRIER_ADMIN');
    p4As($reader, $this->tenant->id);
    Livewire::test(PortalOrganisationSettings::class)->assertActionHidden('addBranch');

    $manager = p4User($this->tenant, $this->carrier->id, ['carrier.dashboard.read', 'tenant.manage'], 'CARRIER_STAFF');
    p4As($manager, $this->tenant->id);
    Livewire::test(PortalOrganisationSettings::class)->callAction('addBranch', ['code' => 'DLA-P4', 'name' => 'Douala P4'])
        ->assertHasNoActionErrors()->assertNotified(__('insurer_portal_ops.branches.added'));
    expect(DB::table('tenant_branches')->where('code', 'DLA-P4')->value('tenant_id'))->toBe($this->tenant->id);
});

it('lists only the insurer\'s own documents in the /insurer document register', function () {
    $doc = fn (string $tenant, string $carrier, string $number) => Document::create(['tenant_id' => $tenant, 'category' => 'POLICY', 'storage_key' => 'k/'.Str::random(8), 'mime_type' => 'application/pdf',
        'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'document_type_code' => 'ATT', 'document_origin' => 'INSURER', 'security_level' => 'CUSTOMER_PRIVATE',
        'issuer_carrier_id' => $carrier, 'document_number' => $number, 'status' => 'ISSUED', 'verification_code' => Str::random(10)]);
    $mine = $doc($this->tenant->id, $this->carrier->id, 'DOC-MINE-1');
    $doc($this->tenant->id, $this->otherCarrier->id, 'DOC-OTHER-CARRIER');
    $doc($this->otherTenant->id, $this->otherCarrier->id, 'DOC-OTHER-TENANT');

    $none = p4User($this->tenant, $this->carrier->id, ['carrier.dashboard.read']);
    $this->actingAs($none)->get('/insurer/document-engine/registry')->assertForbidden();

    $this->flushSession();
    $reader = p4User($this->tenant, $this->carrier->id, ['documents.carrier.upload']);
    $this->actingAs($reader)->get('/insurer/document-engine/registry')->assertOk()->assertSee('DOC-MINE-1')->assertDontSee('DOC-OTHER-CARRIER')->assertDontSee('DOC-OTHER-TENANT');
    $this->actingAs($reader)->get('/insurer/document-engine/registry/'.$mine->id)->assertOk();
});
