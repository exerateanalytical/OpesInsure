<?php

declare(strict_types=1);

/*
 | Partner self-service application (/partners/apply): public form (EN/FR) → one-time code (email when no SMS provider)
 | → admin review with maker-checker → approval provisions BROKER / CARRIER / AGENT through the existing onboarding
 | code and invites the applicant. Duplicates, wrong codes, honeypot, rate limit and data-leak checks.
 */

use App\Application\Partners\Onboarding\IntakeReview;
use App\Application\Partners\Onboarding\PartnerApplicationService;
use App\Domain\Tenancy\TenantContext;
use App\Mail\NotificationMail;
use App\Models\Carrier;
use App\Models\Partner;
use App\Models\PartnerApplication;
use App\Models\PartnerLicence;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function paPdf(string $name = 'doc.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\n".Str::random(40)."\n%%EOF");
}

/** Every NotificationMail body sent so far. @return list<string> */
function paMailBodies(): array
{
    $bodies = [];
    Mail::assertSent(NotificationMail::class, function (NotificationMail $m) use (&$bodies) {
        $bodies[] = (fn () => $this->renderedBody)->call($m);

        return true;
    });

    return $bodies;
}

function paLastCode(): string
{
    preg_match_all('/\b(\d{6})\b/', implode("\n", paMailBodies()), $m);

    return (string) end($m[1]);
}

function paForm(array $over = []): array
{
    return array_merge([
        'type' => 'BROKER', 'legal_name' => 'ZETA COURTAGE SARL', 'trade_name' => 'Zeta Courtage', 'rccm' => 'RC/DLA/2020/B/7777', 'niu' => 'M777777777777Z',
        'licence_number' => 'MINFI/7777', 'licence_expires_on' => now()->addYear()->toDateString(), 'city' => 'Douala', 'address' => 'Akwa',
        'org_phone' => '+237233777777', 'org_email' => 'contact@zeta.cm', 'applicant_name' => 'Zoe Zeta', 'applicant_email' => 'zoe@zeta.cm',
        'applicant_phone' => '+237677777777', 'consent' => '1',
        'doc_licence' => paPdf('licence.pdf'), 'doc_rccm' => paPdf('rccm.pdf'), 'doc_id' => paPdf('id.pdf'),
    ], $over);
}

/** Submits + verifies; returns [application, token]. */
function paSubmitted($test, array $over = []): array
{
    $res = $test->post('/partners/apply', paForm($over));
    $res->assertRedirect();
    $token = Str::afterLast($res->headers->get('Location'), '/');
    $test->post("/partners/apply/status/{$token}/verify", ['code' => paLastCode()])->assertRedirect();
    $a = PartnerApplication::where('status_token_hash', hash('sha256', $token))->firstOrFail();
    expect($a->status)->toBe('SUBMITTED');

    return [$a, $token];
}

function paClean(PartnerApplication $a): void
{
    DB::table('documents')->whereIn('id', array_column($a->documents, 'document_id'))->update(['scan_status' => 'CLEAN']);
}

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    Http::fake(['*' => Http::response(['message' => 'down'], 500)]);
    // No SMS provider configured: codes go by email.
    config(['partner_intake.sms' => false]);
    $this->withoutMiddleware([ThrottleRequests::class, \App\Interfaces\Http\Middleware\PerRouteThrottle::class]);
    $this->platform = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'OpesInsure PA', 'slug' => 'opes-pa-'.Str::lower(Str::random(6)), 'status' => 'ACTIVE',
        'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $this->reviewer = makeAuthTestUser($this->platform, ['tenant.manage', 'identity.invite'], 'PLATFORM_ADMIN');
    $this->checker = makeAuthTestUser($this->platform, ['tenant.manage', 'identity.invite'], 'PLATFORM_ADMIN');
    $this->review = app(IntakeReview::class);
    $this->service = app(PartnerApplicationService::class);
});

function paAdmin($test, User $u): void
{
    $test->actingAs($u);
    app(TenantContext::class)->set($test->platform->id);
}

it('renders the form in EN and FR and is linked from /signup and /partners', function () {
    foreach (['en' => 'Apply to become a partner', 'fr' => 'Devenir partenaire'] as $lang => $title) {
        $this->get('/partners/apply?lang='.$lang)->assertOk()->assertSee($title)->assertSee('name="website"', false);
    }
    $this->get('/signup')->assertOk()->assertSee('href="/partners/apply"', false);
    $this->get('/partners')->assertOk()->assertSee('href="/partners/apply"', false);
});

it('happy path: broker applies, verifies by email code, reviewer recommends, a different admin approves through bulk onboarding code', function () {
    [$a, $token] = paSubmitted($this);
    expect($a->verification_channel)->toBe('EMAIL')->and(count($a->documents))->toBe(3)
        ->and(DB::table('documents')->whereIn('id', array_column($a->documents, 'document_id'))->pluck('scan_status')->unique()->all())->not->toContain('CLEAN')
        ->and(DB::table('document_scan_queue')->whereIn('document_id', array_column($a->documents, 'document_id'))->count())->toBe(3);
    $this->get("/partners/apply/status/{$token}")->assertOk()->assertSee($a->reference)->assertHeader('Referrer-Policy', 'no-referrer');

    paAdmin($this, $this->reviewer);
    $this->review->startReview($a, $this->reviewer);
    $this->review->recommend($a, $this->reviewer, 'APPROVE', 'Licence checked');

    // Documents still held by the scanner: approval refused.
    expect(fn () => $this->service->approve($a, $this->checker))->toThrow(ValidationException::class);
    paClean($a);
    // Maker-checker: the reviewer cannot decide.
    expect(fn () => $this->service->approve($a, $this->reviewer))->toThrow(ValidationException::class);

    $a = $this->service->approve($a, $this->checker, 'OK');
    expect($a->status)->toBe('APPROVED')->and($a->decided_by)->toBe($this->checker->id);
    $tenant = Tenant::findOrFail($a->result['tenant_id']);
    expect($tenant->type)->toBe('BROKER')->and($tenant->status)->toBe('ACTIVE')->and($tenant->registration_number)->toBe('RC/DLA/2020/B/7777')
        ->and($tenant->settings['onboarding']['source'])->toBe('partner_application');
    $partner = Partner::findOrFail($a->result['partner_id']);
    expect($partner->type)->toBe('BROKER')->and($partner->tenant_id)->toBe($tenant->id)
        ->and(PartnerLicence::where('partner_id', $partner->id)->value('licence_number'))->toBe('MINFI/7777');
    $inv = TenantInvitation::findOrFail($a->result['invitation_id']);
    expect($inv->role_code)->toBe('BROKER_ADMIN')->and($inv->tenant_id)->toBe($tenant->id)->and($inv->recipient_phone_e164)->toBe('+237677777777');
    // Evidence re-homed to the new tenant; audit trail written.
    expect(DB::table('documents')->whereIn('id', array_column($a->documents, 'document_id'))->pluck('tenant_id')->unique()->all())->toBe([$tenant->id])
        ->and(DB::table('audit_log')->where('subject_id', $a->id)->pluck('action')->all())->toContain('partner_application.submitted', 'partner_application.recommended', 'partner_application.approved');
    expect(implode("\n", paMailBodies()))->toContain($a->reference);
    $this->get("/partners/apply/status/{$token}")->assertOk()->assertSee('Approved');
});

it('approves an insurer as a CARRIER tenant with its carrier linked and a CARRIER_SUPER_ADMIN invitation', function () {
    [$a] = paSubmitted($this, ['type' => 'INSURER', 'legal_name' => 'NOVA ASSURANCES SA', 'trade_name' => 'Nova', 'rccm' => 'RC/YAO/2021/B/8888', 'niu' => 'M888888888888N',
        'licence_number' => '', 'licence_expires_on' => '', 'doc_licence' => null, 'applicant_email' => 'ceo@nova.cm', 'org_phone' => '+237233888888', 'org_email' => 'info@nova.cm']);
    paClean($a);
    paAdmin($this, $this->reviewer);
    $this->review->startReview($a, $this->reviewer);
    $this->review->recommend($a, $this->reviewer, 'APPROVE', null);
    $a = $this->service->approve($a, $this->checker);

    $tenant = Tenant::findOrFail($a->result['tenant_id']);
    $carrier = Carrier::findOrFail($a->result['carrier_id']);
    expect($tenant->type)->toBe('CARRIER')->and($tenant->settings['carrier_id'])->toBe($carrier->id)->and($carrier->is_official_register)->toBeFalse();
    $inv = TenantInvitation::findOrFail($a->result['invitation_id']);
    expect($inv->role_code)->toBe('CARRIER_SUPER_ADMIN')->and($inv->carrier_id)->toBe($carrier->id)->and($inv->recipient_email)->toBe('ceo@nova.cm')
        ->and(Partner::findOrFail($a->result['partner_id'])->compliance['carrier_id'])->toBe($carrier->id);
});

it('agent under a brokerage needs the brokerage confirmation before approval; independent agent gets an own agency', function () {
    $brokerage = Tenant::create(['type' => 'BROKER', 'legal_name' => 'Omega Courtage', 'slug' => 'omega-c', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'fr', 'settings' => []]);
    $admin = makeAuthTestUser($brokerage, ['*'], 'BROKER_ADMIN');
    TenantMembership::where('user_id', $admin->id)->update(['role_code' => 'BROKER_ADMIN']);

    [$a] = paSubmitted($this, ['type' => 'AGENT', 'legal_name' => 'Paul Agent', 'trade_name' => '', 'rccm' => '', 'niu' => 'P123456789012A', 'licence_number' => 'MINFI/AG/0042',
        'brokerage_tenant_id' => $brokerage->id, 'applicant_name' => 'Paul Agent', 'applicant_email' => 'paul@agent.cm', 'applicant_phone' => '+237655554444',
        'org_phone' => '', 'org_email' => '', 'doc_rccm' => null]);
    expect($a->brokerage_tenant_id)->toBe($brokerage->id);
    $link = collect(paMailBodies())->first(fn ($b) => str_contains($b, '/partners/apply/brokerage/'));
    expect($link)->not->toBeNull();
    $brokerToken = Str::before(Str::after($link, '/partners/apply/brokerage/'), "\n");

    paClean($a);
    paAdmin($this, $this->reviewer);
    $this->review->startReview($a, $this->reviewer);
    $this->review->recommend($a, $this->reviewer, 'APPROVE', null);
    expect(fn () => $this->service->approve($a, $this->checker))->toThrow(ValidationException::class); // brokerage not confirmed

    auth()->logout();
    $this->get('/partners/apply/brokerage/'.$brokerToken)->assertOk()->assertSee('Paul Agent');
    $this->post('/partners/apply/brokerage/'.$brokerToken, ['decision' => 'confirm'])->assertRedirect();
    $this->post('/partners/apply/brokerage/'.$brokerToken, ['decision' => 'confirm'])->assertNotFound(); // single use

    paAdmin($this, $this->checker);
    $a = $this->service->approve($a->refresh(), $this->checker);
    $partner = Partner::findOrFail($a->result['partner_id']);
    expect($partner->type)->toBe('AGENT')->and($partner->tenant_id)->toBe($brokerage->id)->and($partner->getAttribute('agent_type'))->toBe('EMPLOYEE');
    $inv = TenantInvitation::findOrFail($a->result['invitation_id']);
    expect($inv->role_code)->toBe('AGENT')->and($inv->tenant_id)->toBe($brokerage->id)->and($inv->recipient_phone_e164)->toBe('+237655554444');

    // Independent agent.
    auth()->logout();
    [$b] = paSubmitted($this, ['type' => 'AGENT', 'legal_name' => 'Ines Independent', 'trade_name' => '', 'rccm' => '', 'niu' => 'P999999999999I', 'licence_number' => 'MINFI/AG/0099',
        'independent' => '1', 'applicant_name' => 'Ines Independent', 'applicant_email' => 'ines@agent.cm', 'applicant_phone' => '+237655559999',
        'org_phone' => '', 'org_email' => '', 'doc_rccm' => null]);
    paClean($b);
    paAdmin($this, $this->checker);
    $this->review->startReview($b, $this->checker);
    $this->review->recommend($b, $this->checker, 'APPROVE', null);
    $b = $this->service->approve($b, $this->reviewer); // roles swapped: still two different people
    expect(Tenant::findOrFail($b->result['tenant_id'])->type)->toBe('AGENCY')->and($b->result['independent'])->toBeTrue()
        ->and(Partner::findOrFail($b->result['partner_id'])->getAttribute('agent_type'))->toBe('INDEPENDENT');
});

it('detects duplicates against tenants, identifiers, the official register and open applications without revealing the match', function () {
    Tenant::create(['type' => 'BROKER', 'legal_name' => 'Secret Existing Broker', 'slug' => 'secret-b', 'registration_number' => 'RC/DLA/2020/B/7777', 'status' => 'ACTIVE',
        'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'fr', 'settings' => []]);
    $res = $this->post('/partners/apply', paForm());
    $res->assertSessionHasErrors('legal_name');
    expect(session('errors')->first('legal_name'))->toBe(__('partner_apply.errors.duplicate'))->not->toContain('Secret');
    expect(PartnerApplication::count())->toBe(0);

    // Official register insurer: must be claimed instead.
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Register Assurances', 'status' => 'ACTIVE', 'legal_identity' => []]);
    Carrier::create(['party_id' => $party->id, 'cima_code' => 'REG-001', 'status' => 'ACTIVE', 'legal_name' => 'REGISTER ASSURANCES SA', 'trade_name' => 'Register Assurances', 'is_official_register' => true]);
    $this->post('/partners/apply', paForm(['type' => 'INSURER', 'legal_name' => 'Register Assurances', 'rccm' => 'RC/DLA/2020/B/1111', 'niu' => 'M111111111111R']))
        ->assertSessionHasErrors(['legal_name' => __('partner_apply.errors.in_register')]);

    // Same NIU as an application in progress.
    paSubmitted($this, ['rccm' => 'RC/DLA/2020/B/2222', 'legal_name' => 'FIRST CO', 'trade_name' => '']);
    $this->post('/partners/apply', paForm(['rccm' => 'RC/DLA/2020/B/3333', 'legal_name' => 'SECOND CO', 'trade_name' => '', 'applicant_email' => 'other@x.cm']))
        ->assertSessionHasErrors(['legal_name' => __('partner_apply.errors.pending')]);

    // Format errors (shared patterns with bulk onboarding).
    $this->post('/partners/apply', paForm(['niu' => 'BAD', 'rccm' => 'XX', 'legal_name' => 'Third']))->assertSessionHasErrors(['niu', 'rccm']);
});

it('rejects wrong codes, locks after too many attempts and never reveals the code', function () {
    $res = $this->post('/partners/apply', paForm());
    $token = Str::afterLast($res->headers->get('Location'), '/');
    $code = paLastCode();
    $wrong = $code === '000000' ? '111111' : '000000';
    $this->post("/partners/apply/status/{$token}/verify", ['code' => $wrong])->assertSessionHas('status_error');
    $a = PartnerApplication::firstOrFail();
    expect($a->status)->toBe('UNVERIFIED')->and($a->code_attempts)->toBe(1)->and($a->code_hash)->not->toBe($code)->and($a->toArray())->not->toHaveKey('code_hash');
    for ($i = 0; $i < 4; $i++) {
        $this->post("/partners/apply/status/{$token}/verify", ['code' => $wrong]);
    }
    $this->post("/partners/apply/status/{$token}/verify", ['code' => $code])->assertSessionHas('status_error');
    expect($a->refresh()->status)->toBe('UNVERIFIED');
    $this->get("/partners/apply/status/{$token}")->assertOk()->assertDontSee($code);
    // Unverified applications never reach the admin queue.
    paAdmin($this, $this->reviewer);
    expect(\App\Filament\Admin\Resources\PartnerApplications\PartnerApplicationResource::getEloquentQuery()->count())->toBe(0);
});

it('honeypot stores nothing; the submit route is rate limited', function () {
    $this->post('/partners/apply', paForm(['website' => 'http://spam']))->assertRedirect('/partners/apply');
    expect(PartnerApplication::count())->toBe(0)->and(DB::table('documents')->count())->toBe(0);

    $this->app->offsetUnset(ThrottleRequests::class);
    $this->app->offsetUnset(\App\Interfaces\Http\Middleware\PerRouteThrottle::class);
    $codes = [];
    for ($i = 0; $i < 6; $i++) {
        $codes[] = $this->post('/partners/apply', ['type' => 'BROKER'])->getStatusCode();
    }
    expect($codes)->toContain(429);
});

it('information request round trip through the private link; only authorised platform admins can review; no data leak', function () {
    [$a, $token] = paSubmitted($this);
    // Unknown token: 404. Status page never shows reviewer notes or duplicate signals.
    $this->get('/partners/apply/status/'.Str::random(48))->assertNotFound();

    // A broker admin (not platform) cannot act.
    $outsider = makeAuthTestUser(makeAuthTestTenant(), ['tenant.manage', 'identity.invite'], 'CARRIER_ADMIN');
    $this->actingAs($outsider);
    app(TenantContext::class)->set(TenantMembership::where('user_id', $outsider->id)->value('tenant_id'));
    expect(fn () => $this->review->startReview($a, $outsider))->toThrow(HttpException::class);
    expect(\App\Filament\Admin\Resources\PartnerApplications\PartnerApplicationResource::canViewAny())->toBeFalse();

    paAdmin($this, $this->reviewer);
    expect(\App\Filament\Admin\Resources\PartnerApplications\PartnerApplicationResource::canViewAny())->toBeTrue();
    $this->review->requestInfo($a, $this->reviewer, 'Please send the 2026 licence renewal.');
    // INFO_REQUESTED cannot be put under review before the applicant answers.
    expect(fn () => $this->review->startReview($a->refresh(), $this->reviewer))->toThrow(ValidationException::class);
});

it('applicant answers an information request and the application returns to the queue', function () {
    [$a, $token] = paSubmitted($this);
    paAdmin($this, $this->reviewer);
    $this->review->startReview($a, $this->reviewer);
    $this->review->recommend($a, $this->reviewer, 'APPROVE', 'internal reviewer note XYZ');
    $this->review->requestInfo($a, $this->reviewer, 'Please send the 2026 licence renewal.');
    auth()->logout();
    $this->get("/partners/apply/status/{$token}")->assertOk()->assertSee('Please send the 2026 licence renewal.')->assertDontSee('internal reviewer note XYZ');
    $this->post("/partners/apply/status/{$token}/respond", ['response' => 'Renewal attached.', 'doc_extra' => paPdf('renewal.pdf')])->assertSessionHas('status_ok');
    $a->refresh();
    expect($a->status)->toBe('SUBMITTED')->and(count($a->documents))->toBe(4)->and($a->recommendation)->toBeNull()->and($a->applicant_response)->toContain('Renewal attached.');

    // A rejection also needs a second admin.
    paAdmin($this, $this->reviewer);
    $this->review->startReview($a, $this->reviewer);
    $this->review->recommend($a, $this->reviewer, 'REJECT', null);
    expect(fn () => $this->review->reject($a, $this->reviewer, 'No'))->toThrow(ValidationException::class);
    expect(fn () => $this->service->approve($a, $this->checker))->toThrow(ValidationException::class); // recommended REJECT
    $this->review->reject($a, $this->checker, 'Licence not found at MINFI.');
    expect($a->refresh()->status)->toBe('REJECTED');
    expect(implode("\n", paMailBodies()))->toContain('Licence not found at MINFI.');
});
