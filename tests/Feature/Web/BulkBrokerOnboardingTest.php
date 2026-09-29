<?php

declare(strict_types=1);

/*
 | S9 — bulk broker onboarding for platform admins: template → upload CSV/XLSX → per-row validation and preview →
 | submit (maker) → approve (another admin) → BROKER tenant + party/partner + licence + branches + BROKER_ADMIN
 | invitation per valid row; idempotent re-upload; downloadable result report; API twin under /api/v1/platform/broker-onboarding.
 */

use App\Application\Identity\InvitationService;
use App\Application\Import\ImportPipeline;
use App\Application\Partners\BulkOnboarding\BrokerOnboardingService;
use App\Application\Partners\BulkOnboarding\BrokerOnboardingTarget;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\BrokerOnboarding\BrokerOnboardingResource;
use App\Filament\Admin\Resources\BrokerOnboarding\Pages\ListBrokerOnboardings;
use App\Mail\NotificationMail;
use App\Models\Import\ImportBatch;
use App\Models\Partner;
use App\Models\PartnerLicence;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantBranch;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function s9Platform(): Tenant
{
    return Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'OpesInsure S9', 'slug' => 'opes-s9-'.Str::lower(Str::random(6)), 'status' => 'ACTIVE',
        'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function s9Admin(Tenant $t, array $perms = ['tenant.manage', 'identity.invite'], string $role = 'PLATFORM_ADMIN'): User
{
    return makeAuthTestUser($t, $perms, $role);
}

function s9Csv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 's9').'.csv';
    $h = fopen($path, 'w');
    fputcsv($h, array_keys(BrokerOnboardingTarget::FIELDS), ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($h, array_map(fn ($k) => $r[$k] ?? '', array_keys(BrokerOnboardingTarget::FIELDS)), ',', '"', '');
    }
    fclose($h);

    return $path;
}

function s9Rows(): array
{
    $exp = now()->addYear()->toDateString();

    return [
        ['legal_name' => 'ALPHA COURTAGE SARL', 'trade_name' => 'Alpha Courtage', 'rccm' => 'RC/DLA/2019/B/1001', 'niu' => 'M012345678901A', 'licence_number' => 'MINFI/0001',
            'licence_expires_on' => $exp, 'city' => 'Douala', 'address' => 'Bonanjo', 'phone' => '+237233000001', 'email' => 'contact@alpha.cm',
            'admin_name' => 'Alice Admin', 'admin_phone' => '690000001', 'admin_email' => 'alice@alpha.cm', 'branches' => 'Akwa@Douala|Bastos@Yaoundé', 'locale' => 'fr'],
        ['legal_name' => 'BETA ASSURANCES CONSEIL', 'rccm' => 'RC/YAO/2020/B/2002', 'niu' => 'P098765432109Z', 'licence_number' => 'MINFI/0002',
            'licence_expires_on' => now()->addMonths(6)->format('d/m/Y'), 'city' => 'Yaoundé', 'phone' => '233000002', 'admin_name' => 'Bob Admin',
            'admin_email' => 'bob@beta.cm', 'locale' => 'en'],
        ['legal_name' => 'GAMMA', 'rccm' => 'RC/DLA/2021/B/3003', 'niu' => 'BADNIU', 'licence_number' => 'MINFI/0003', 'licence_expires_on' => '2020-01-01',
            'city' => 'Douala', 'phone' => '12345', 'email' => 'not-an-email', 'admin_name' => 'Gus'],
        ['legal_name' => 'DELTA COURTAGE', 'rccm' => 'RC/YAO/2018/B/0999', 'niu' => 'M111111111111D', 'licence_number' => 'MINFI/0004', 'licence_expires_on' => $exp,
            'city' => 'Yaoundé', 'phone' => '+237233000004', 'admin_name' => 'Dan', 'admin_phone' => '+237690000004'],
        ['legal_name' => 'EPSILON COURTAGE', 'trade_name' => 'Epsilon Courtage', 'rccm' => 'RC/DLA/2017/B/5005', 'niu' => 'M555555555555E', 'licence_number' => 'MINFI/0005',
            'licence_expires_on' => $exp, 'city' => 'Douala', 'phone' => '+237233000005', 'admin_name' => 'Eve', 'admin_phone' => '+237690000005'],
        ['legal_name' => 'ALPHA BIS', 'rccm' => 'RC/DLA/2019/B/1006', 'niu' => 'M012345678901A', 'licence_number' => 'MINFI/0006', 'licence_expires_on' => $exp,
            'city' => 'Douala', 'phone' => '+237233000006', 'admin_name' => 'Al', 'admin_phone' => '+237690000006'],
    ];
}

beforeEach(function () {
    Mail::fake();
    Http::fake(['*' => Http::response(['message' => 'down'], 500)]); // no SMS provider accepts: SMS delivery is FAILED
    $this->platform = s9Platform();
    app(TenantContext::class)->set($this->platform->id);
    // Existing broker tenant (duplicate by RCCM) and an official-register broker without a tenant (linked, not duplicated).
    Tenant::create(['type' => 'BROKER', 'legal_name' => 'Delta Old Name', 'slug' => 'delta-old', 'registration_number' => 'RC/YAO/2018/B/0999', 'status' => 'ACTIVE',
        'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'fr', 'settings' => []]);
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Epsilon Courtage', 'status' => 'ACTIVE', 'legal_identity' => ['country' => 'CM']]);
    $this->register = Partner::create(['party_id' => $party->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => [], 'legal_name' => 'EPSILON COURTAGE',
        'trade_name' => 'Epsilon Courtage', 'is_official_register' => true, 'canonical_id' => 'CM-BRK-2026-999']);
});

it('validates every row, previews, and on maker-checker approval onboards each valid brokerage through the existing services', function () {
    $maker = s9Admin($this->platform);
    $checker = s9Admin($this->platform);
    $svc = app(BrokerOnboardingService::class);

    $batch = $svc->upload(s9Csv(s9Rows()), 'brokers.csv', $maker);
    expect($batch->status)->toBe('VALIDATED') // partial target: invalid rows do not block the valid ones
        ->and($batch->report['valid'])->toBe(3)->and(count($batch->report['errors']))->toBe(2)->and(count($batch->report['duplicates']))->toBe(1);

    $rows = collect($svc->rows($batch))->keyBy('row');
    expect($rows[1]['status'])->toBe('NEW')->and($rows[2]['status'])->toBe('NEW')->and($rows[5]['status'])->toBe('NEW')
        ->and($rows[3]['status'])->toBe('ERROR')->and($rows[4]['status'])->toBe('DUPLICATE')->and($rows[6]['status'])->toBe('ERROR')
        ->and($rows[3]['reason'])->toContain('NIU')->toContain('+237')->toContain('expired')
        ->and($rows[6]['reason'])->toContain('repeated')->and($rows[4]['reason'])->toContain('Delta Old Name');

    // Nothing is created before approval.
    expect(Tenant::where('registration_number', 'RC/DLA/2019/B/1001')->exists())->toBeFalse();

    $batch = app(ImportPipeline::class)->submit($batch, $maker, 'Launch brokers');
    expect($batch->status)->toBe('PENDING_APPROVAL');
    expect(fn () => app(ImportPipeline::class)->approve($batch, $maker))->toThrow(ValidationException::class); // maker ≠ checker

    $batch = app(ImportPipeline::class)->approve($batch->fresh(), $checker, 'checked against the MINFI list');
    expect($batch->status)->toBe('IMPORTED')->and($batch->imported_count)->toBe(3)->and($batch->approved_by)->toBe($checker->id)
        ->and(count($batch->result['skipped']))->toBe(3);

    // Row 1: tenant, party + identifiers, partner, licence, HQ + 2 branches, invitation (SMS failed, email sent).
    $alpha = Tenant::where('tax_number', 'M012345678901A')->firstOrFail();
    expect($alpha->type)->toBe('BROKER')->and($alpha->status)->toBe('ACTIVE')->and($alpha->registration_number)->toBe('RC/DLA/2019/B/1001')
        ->and($alpha->primary_locale)->toBe('fr')->and($alpha->timezone)->toBe('Africa/Douala');
    $partner = Partner::where('tenant_id', $alpha->id)->firstOrFail();
    expect($partner->type)->toBe('BROKER')->and($partner->status)->toBe('PENDING')
        ->and(DB::table('party_identifiers')->where('party_id', $partner->party_id)->pluck('type')->sort()->values()->all())->toBe(['NIU', 'RCCM'])
        ->and(DB::table('party_contacts')->where('party_id', $partner->party_id)->pluck('normalized_value')->all())->toContain('+237233000001', 'contact@alpha.cm');
    $licence = PartnerLicence::where('partner_id', $partner->id)->firstOrFail();
    expect($licence->licence_number)->toBe('MINFI/0001')->and($licence->status)->toBe('PENDING_VERIFICATION')->and($licence->authority)->toBe('MINFI');
    expect(TenantBranch::where('tenant_id', $alpha->id)->orderBy('code')->pluck('name', 'code')->all())->toBe(['BR01' => 'Akwa', 'BR02' => 'Bastos', 'HQ' => 'Siège']);
    $invite = TenantInvitation::where('tenant_id', $alpha->id)->firstOrFail();
    expect($invite->role_code)->toBe('BROKER_ADMIN')->and($invite->recipient_phone_e164)->toBe('+237690000001')->and($invite->status)->toBe('PENDING');
    Mail::assertSent(NotificationMail::class, 2); // Alpha + Beta administrators

    // Row 5 is linked to the official register partner instead of creating a second broker.
    expect($this->register->fresh()->tenant_id)->toBe(Tenant::where('tax_number', 'M555555555555E')->value('id'))
        ->and(Partner::where('type', 'BROKER')->whereRaw('lower(legal_name) = ?', ['epsilon courtage'])->count())->toBe(1);

    // Audit trail of the batch and of each creation.
    expect(DB::table('audit_log')->where('subject_type', 'import_batch')->where('subject_id', $batch->id)->pluck('action')->all())
        ->toContain('import.uploaded', 'import.validated', 'import.submitted', 'import.imported')
        ->and(DB::table('audit_log')->where('action', 'partner.bulk_onboarded')->count())->toBe(3);

    // Result report: per row; the SMS-only admin (Eve) gets her undelivered code, which really accepts the invitation.
    $report = collect(array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", ltrim($svc->reportCsv($batch->fresh()), "\xEF\xBB\xBF")))));
    $head = $report->shift();
    $lines = $report->map(fn ($l) => array_combine($head, $l))->keyBy('row');
    expect($lines['1']['status'])->toBe('CREATED')->and($lines['1']['delivery'])->toBe('SMS:FAILED EMAIL:SENT')->and($lines['1']['invitation_code'])->toBe('')
        ->and($lines['4']['status'])->toBe('SKIPPED_DUPLICATE')->and($lines['3']['status'])->toBe('SKIPPED_ERROR')
        ->and($lines['5']['delivery'])->toBe('SMS:FAILED')->and(strlen($lines['5']['invitation_code']))->toBe(64);
    $eve = User::create(['full_name' => 'Eve', 'phone_e164' => '+237690000005', 'password' => 'x', 'locale' => 'fr', 'status' => 'ACTIVE']);
    $eve->forceFill(['phone_verified_at' => now()])->save();
    $membership = app(InvitationService::class)->accept($lines['5']['invitation_code'], $eve);
    expect($membership->role_code)->toBe('BROKER_ADMIN')->and($membership->tenant_id)->toBe($this->register->fresh()->tenant_id);
})->group('S9');

it('is idempotent: re-uploading the same file finds every brokerage already on the platform', function () {
    $maker = s9Admin($this->platform);
    $checker = s9Admin($this->platform);
    $p = app(ImportPipeline::class);
    $svc = app(BrokerOnboardingService::class);
    $p->approve($p->submit($svc->upload(s9Csv(s9Rows()), 'brokers.csv', $maker), $maker)->fresh(), $checker);
    $tenants = Tenant::count();

    $again = $svc->upload(s9Csv(s9Rows()), 'brokers-again.csv', $maker);
    expect($again->report['valid'])->toBe(0)->and(count($again->report['duplicates']))->toBe(5)
        ->and(collect($svc->rows($again))->where('status', 'DUPLICATE')->pluck('row')->all())->toBe([1, 2, 4, 5, 6]);
    expect(fn () => $p->submit($again, $maker))->toThrow(ValidationException::class);
    expect(Tenant::count())->toBe($tenants);
})->group('S9');

it('records a row that fails at creation as FAILED and rolls that row back without stopping the batch', function () {
    $maker = s9Admin($this->platform);
    // A checker who may approve but is not a platform admin cannot grant BROKER_ADMIN in another tenant: every row fails.
    $checker = s9Admin($this->platform, ['tenant.manage', 'identity.invite'], 'OPERATIONS_MANAGER');
    $p = app(ImportPipeline::class);
    $batch = $p->approve($p->submit(app(BrokerOnboardingService::class)->upload(s9Csv(array_slice(s9Rows(), 0, 2)), 'b.csv', $maker), $maker)->fresh(), $checker);

    expect($batch->status)->toBe('IMPORTED')->and($batch->imported_count)->toBe(0)->and(count($batch->result['failed']))->toBe(2)
        ->and(Tenant::where('tax_number', 'M012345678901A')->exists())->toBeFalse()
        ->and(DB::table('party_identifiers')->where('type', 'NIU')->count())->toBe(0);
    expect(collect(app(BrokerOnboardingService::class)->rows($batch))->pluck('status')->all())->toBe(['FAILED', 'FAILED']);
})->group('S9');

it('normalises Cameroon phones, dates and branches', function () {
    expect(BrokerOnboardingTarget::phone('690 00 00 01'))->toBe('+237690000001')
        ->and(BrokerOnboardingTarget::phone('00237 233 00 00 01'))->toBe('+237233000001')
        ->and(BrokerOnboardingTarget::phone('237690000001'))->toBe('+237690000001')
        ->and(BrokerOnboardingTarget::phone('+33612345678'))->toBeNull()
        ->and(BrokerOnboardingTarget::phone('+237590000001'))->toBeNull()
        ->and(BrokerOnboardingTarget::branches('Akwa@Douala | Bastos'))->toBe([['name' => 'Akwa', 'city' => 'Douala'], ['name' => 'Bastos', 'city' => null]]);
    [$n, $e] = BrokerOnboardingTarget::normalise(['legal_name' => 'X', 'rccm' => 'rc/dla/2019/b/1', 'niu' => 'm 0123-45678901a', 'licence_number' => 'minfi/1',
        'licence_expires_on' => now()->addDay()->format('d/m/Y'), 'city' => 'Douala', 'phone' => '690000009', 'admin_name' => 'Y', 'admin_email' => 'Y@X.CM']);
    expect($e)->toBe([])->and($n['niu'])->toBe('M012345678901A')->and($n['rccm'])->toBe('RC/DLA/2019/B/1')->and($n['admin_email'])->toBe('y@x.cm')
        ->and($n['licence_expires_on'])->toBe(now()->addDay()->toDateString());
    [, $e] = BrokerOnboardingTarget::normalise(['legal_name' => 'X', 'rccm' => 'RC/DLA/2019/B/1', 'niu' => 'M012345678901A', 'licence_number' => 'MINFI/1',
        'licence_expires_on' => now()->addDay()->toDateString(), 'city' => 'D', 'phone' => '690000009', 'admin_name' => 'Y']);
    expect(implode(' ', $e))->toContain('phone or an email');
})->group('S9');

it('exposes the same flow over the API, platform tenant and permissions only', function () {
    $maker = s9Admin($this->platform);
    $checker = s9Admin($this->platform);
    $h = tenantHeader($this->platform);
    Passport::actingAs($maker);

    $tpl = $this->get('/api/v1/platform/broker-onboarding/template', $h)->assertOk();
    expect($tpl->getContent())->toContain('legal_name,trade_name,rccm,niu,licence_number,licence_expires_on');
    $this->get('/api/v1/platform/broker-onboarding/template?format=xlsx', $h)->assertOk();

    $file = UploadedFile::fake()->createWithContent('brokers.csv', file_get_contents(s9Csv(array_slice(s9Rows(), 0, 3))));
    $res = $this->postJson('/api/v1/platform/broker-onboarding/batches', ['file' => $file], $h)->assertCreated()
        ->assertJsonPath('data.status', 'VALIDATED')->assertJsonPath('data.summary.new', 2)->assertJsonPath('data.summary.errors', 1)
        ->assertJsonPath('data.rows.2.status', 'ERROR');
    $id = $res->json('data.id');
    $this->postJson("/api/v1/platform/broker-onboarding/batches/$id/submit", [], $h)->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');
    $this->postJson("/api/v1/platform/broker-onboarding/batches/$id/approve", [], $h)->assertStatus(422);

    Passport::actingAs($checker);
    $this->postJson("/api/v1/platform/broker-onboarding/batches/$id/approve", ['note' => 'ok'], $h)->assertOk()
        ->assertJsonPath('data.status', 'IMPORTED')->assertJsonPath('data.summary.created', 2)->assertJsonPath('data.rows.0.status', 'CREATED');
    $report = $this->get("/api/v1/platform/broker-onboarding/batches/$id/report", $h)->assertOk();
    expect($report->getContent())->toContain('CREATED')->toContain('SKIPPED_ERROR');
    $this->getJson('/api/v1/platform/broker-onboarding/batches', $h)->assertOk()->assertJsonPath('data.0.id', $id);

    // Missing identity.invite → 403; a non-platform tenant → 403; another tenant cannot see the batch.
    Passport::actingAs(s9Admin($this->platform, ['tenant.manage']));
    $this->getJson('/api/v1/platform/broker-onboarding/batches', $h)->assertForbidden();
    $broker = makeAuthTestTenant('Broker');
    Passport::actingAs(makeAuthTestUser($broker, ['tenant.manage', 'identity.invite']));
    $this->getJson('/api/v1/platform/broker-onboarding/batches', tenantHeader($broker))->assertForbidden();
})->group('S9');

it('drives the /admin screen: gated to platform admins, preview, submit, approve by another admin, report and template downloads', function () {
    $maker = s9Admin($this->platform);
    $checker = s9Admin($this->platform);
    $batch = app(BrokerOnboardingService::class)->upload(s9Csv(array_slice(s9Rows(), 0, 3)), 'brokers.csv', $maker);
    // An unrelated import of another target is not listed here.
    $this->actingAs($maker);
    expect(BrokerOnboardingResource::canViewAny())->toBeTrue()->and(BrokerOnboardingResource::canCreate())->toBeFalse();

    Livewire\Livewire::test(ListBrokerOnboardings::class)->assertOk()->assertCanSeeTableRecords([$batch])
        ->mountTableAction('preview', $batch)->assertOk();
    Livewire\Livewire::test(ListBrokerOnboardings::class)->callTableAction('templateCsv')->assertFileDownloaded('broker-onboarding-template.csv');
    Livewire\Livewire::test(ListBrokerOnboardings::class)->callTableAction('submit', $batch, ['reason' => 'launch'])->assertHasNoTableActionErrors()
        ->assertTableActionHidden('approve', $batch->fresh());
    expect($batch->fresh()->status)->toBe('PENDING_APPROVAL');

    $this->actingAs($checker);
    Livewire\Livewire::test(ListBrokerOnboardings::class)->callTableAction('approve', $batch->fresh(), ['note' => 'ok'])->assertHasNoTableActionErrors();
    expect($batch->fresh()->status)->toBe('IMPORTED')->and(Tenant::where('type', 'BROKER')->where('tax_number', 'P098765432109Z')->exists())->toBeTrue();
    Livewire\Livewire::test(ListBrokerOnboardings::class)->callTableAction('report', $batch->fresh())->assertFileDownloaded();

    // Outside the platform tenant, or without identity.invite, the screen is hidden.
    $this->actingAs(s9Admin($this->platform, ['tenant.manage']));
    expect(BrokerOnboardingResource::canViewAny())->toBeFalse();
    $other = makeAuthTestTenant('Carrier');
    app(TenantContext::class)->set($other->id);
    $this->actingAs(makeAuthTestUser($other, ['tenant.manage', 'identity.invite']));
    expect(BrokerOnboardingResource::canViewAny())->toBeFalse();
})->group('S9');

it('ships the bulk_onboarding language group in English and French with the same keys', function () {
    $flat = fn (array $a) => array_keys(Illuminate\Support\Arr::dot($a));
    $en = require base_path('resources/lang/en/bulk_onboarding.php');
    $fr = require base_path('resources/lang/fr/bulk_onboarding.php');
    expect($flat($fr))->toBe($flat($en));
    app()->setLocale('fr');
    expect(__('bulk_onboarding.nav'))->toBe('Intégration des courtiers');
})->group('S9');
