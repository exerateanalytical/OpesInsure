<?php

declare(strict_types=1);

/*
 | "Claim this organisation": official-register insurers / brokers without an account. Code to the OFFICIAL contact on
 | record (never a claimant-typed one), authority documents through the scan queue, maker-checker approval linking the
 | institution to a new or existing tenant and inviting the claimant; claim lock, disputes, wrong code, no data leak.
 */

use App\Application\Partners\Onboarding\IntakeReview;
use App\Application\Partners\Onboarding\OrganisationClaimService;
use App\Domain\Tenancy\TenantContext;
use App\Mail\NotificationMail;
use App\Models\Carrier;
use App\Models\OrganisationClaim;
use App\Models\Partner;
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

function ocPdf(string $name = 'doc.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\n".Str::random(40)."\n%%EOF");
}

/** @return list<array{to: string, body: string}> */
function ocMails(): array
{
    $out = [];
    Mail::assertSent(NotificationMail::class, function (NotificationMail $m) use (&$out) {
        $out[] = ['to' => $m->to[0]['address'] ?? '', 'body' => (fn () => $this->renderedBody)->call($m)];

        return true;
    });

    return $out;
}

function ocForm(array $over = []): array
{
    return array_merge(['claimant_name' => 'Claire Claimant', 'claimant_position' => 'Directrice générale', 'claimant_email' => 'claire@personal.cm',
        'claimant_phone' => '+237699000111', 'consent' => '1', 'doc_authority' => ocPdf('board.pdf'), 'doc_id' => ocPdf('id.pdf')], $over);
}

function ocAdmin($test, User $u): void
{
    $test->actingAs($u);
    app(TenantContext::class)->set($test->platform->id);
}

function ocClean(OrganisationClaim $c): void
{
    DB::table('documents')->whereIn('id', array_column($c->documents, 'document_id'))->update(['scan_status' => 'CLEAN']);
}

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    Http::fake(['*' => Http::response(['message' => 'down'], 500)]);
    config(['partner_intake.sms' => false]);
    $this->withoutMiddleware([ThrottleRequests::class, \App\Interfaces\Http\Middleware\PerRouteThrottle::class]);
    $this->platform = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'OpesInsure OC', 'slug' => 'opes-oc-'.Str::lower(Str::random(6)), 'status' => 'ACTIVE',
        'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $this->reviewer = makeAuthTestUser($this->platform, ['tenant.manage', 'identity.invite'], 'PLATFORM_ADMIN');
    $this->checker = makeAuthTestUser($this->platform, ['tenant.manage', 'identity.invite'], 'PLATFORM_ADMIN');
    $this->review = app(IntakeReview::class);
    $this->service = app(OrganisationClaimService::class);

    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Atlas Assurances', 'status' => 'ACTIVE', 'legal_identity' => []]);
    $this->carrier = Carrier::create(['party_id' => $party->id, 'cima_code' => 'ATLAS-001', 'status' => 'ACTIVE', 'legal_name' => 'ATLAS ASSURANCES SA',
        'trade_name' => 'Atlas Assurances', 'is_official_register' => true]);
    DB::table('institution_profiles')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $this->carrier->id, 'directory_id' => 'CM-INS-T-001',
        'emails' => json_encode(['direction@atlas-assurances.cm']), 'phones' => json_encode(['+237233000000']), 'sources' => '[]',
        'dataset' => 'test', 'dataset_version' => '1', 'created_at' => now(), 'updated_at' => now()]);

    $bparty = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Boreal Courtage', 'status' => 'ACTIVE', 'legal_identity' => []]);
    $this->broker = Partner::create(['party_id' => $bparty->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => [], 'legal_name' => 'BOREAL COURTAGE',
        'trade_name' => 'Boreal Courtage', 'is_official_register' => true]);
});

it('happy path: insurer claim verified by a code sent to the official email, approved by a second admin, claimant invited', function () {
    $form = $this->get('/organisations/claim/insurer/'.$this->carrier->id)->assertOk()->assertSee('Atlas Assurances')
        ->assertSee('d•••@atlas-assurances.cm')->assertDontSee('direction@atlas-assurances.cm');
    $this->get('/organisations/claim/insurer/'.$this->carrier->id.'?lang=fr')->assertOk()->assertSee('Revendiquer');

    $res = $this->post('/organisations/claim/insurer/'.$this->carrier->id, ocForm());
    $token = Str::afterLast($res->headers->get('Location'), '/');
    $claim = OrganisationClaim::firstOrFail();
    expect($claim->status)->toBe('UNVERIFIED')->and($claim->verification_mode)->toBe('OFFICIAL_CONTACT');
    // The code went to the official address on record, never to the claimant.
    $codeMail = collect(ocMails())->first(fn ($m) => preg_match('/\b\d{6}\b/', $m['body']));
    expect($codeMail['to'])->toBe('direction@atlas-assurances.cm');
    preg_match('/\b(\d{6})\b/', $codeMail['body'], $m);

    $this->post("/organisations/claim/status/{$token}/verify", ['code' => $m[1]])->assertSessionHas('status_ok');
    $claim->refresh();
    expect($claim->status)->toBe('SUBMITTED');

    ocAdmin($this, $this->reviewer);
    $this->review->startReview($claim, $this->reviewer);
    $this->review->recommend($claim, $this->reviewer, 'APPROVE', 'Board letter signed by chairman');
    expect(fn () => $this->service->approve($claim, $this->checker))->toThrow(ValidationException::class); // documents held by the scanner
    ocClean($claim);
    expect(fn () => $this->service->approve($claim, $this->reviewer))->toThrow(ValidationException::class); // maker-checker

    $claim = $this->service->approve($claim, $this->checker);
    $tenant = Tenant::findOrFail($claim->linked_tenant_id);
    expect($claim->status)->toBe('APPROVED')->and($tenant->type)->toBe('CARRIER')->and($tenant->settings['carrier_id'])->toBe($this->carrier->id);
    $inv = TenantInvitation::findOrFail($claim->result['invitation_id']);
    expect($inv->role_code)->toBe('CARRIER_SUPER_ADMIN')->and($inv->carrier_id)->toBe($this->carrier->id)->and($inv->recipient_email)->toBe('claire@personal.cm')
        ->and(Partner::findOrFail($claim->result['partner_id'])->compliance['carrier_id'])->toBe($this->carrier->id)
        ->and(DB::table('audit_log')->where('subject_id', $claim->id)->pluck('action')->all())->toContain('organisation_claim.approved');

    // Claimed: the form now only offers a dispute.
    $this->get('/organisations/claim/insurer/'.$this->carrier->id)->assertOk()->assertSee(__('org_claim.locked_claimed'));
});

it('wrong code keeps the claim unverified and does not lock the institution for long', function () {
    $res = $this->post('/organisations/claim/insurer/'.$this->carrier->id, ocForm());
    $token = Str::afterLast($res->headers->get('Location'), '/');
    $this->post("/organisations/claim/status/{$token}/verify", ['code' => '000001'])->assertSessionHas('status_error');
    $claim = OrganisationClaim::firstOrFail();
    expect($claim->status)->toBe('UNVERIFIED')->and($claim->code_attempts)->toBe(1);
    $claim->forceFill(['code_expires_at' => now()->subMinute()])->save();
    expect($this->service->lockState($this->service->institution('insurer', $this->carrier->id)))->toBeNull();
});

it('claim lock: a pending claim blocks further claims; a second claimant can only file a dispute, which is never approvable', function () {
    $this->post('/organisations/claim/broker/'.$this->broker->id, ocForm())->assertRedirect();
    $first = OrganisationClaim::firstOrFail();
    // No official contact on record: manual verification only, submitted straight away.
    expect($first->verification_mode)->toBe('MANUAL')->and($first->status)->toBe('SUBMITTED');

    $this->post('/organisations/claim/broker/'.$this->broker->id, ocForm(['claimant_email' => 'other@x.cm']))
        ->assertSessionHasErrors(['claimant_name' => __('org_claim.errors.locked')]);
    expect(OrganisationClaim::count())->toBe(1);

    $this->post('/organisations/claim/broker/'.$this->broker->id, ocForm(['claimant_email' => 'other@x.cm', 'dispute' => '1']))->assertRedirect();
    $dispute = OrganisationClaim::where('is_dispute', true)->firstOrFail();
    expect($dispute->status)->toBe('DISPUTED');

    // The DB lock holds even if the service check were bypassed.
    expect(fn () => DB::transaction(fn () => OrganisationClaim::create(array_merge($first->only(['institution_type', 'partner_id', 'institution_key', 'institution_name', 'claimant_name', 'claimant_position', 'claimant_email', 'verification_mode']),
        ['reference' => 'OC-X', 'status' => 'SUBMITTED', 'status_token_hash' => Str::random(64)]))))->toThrow(\Illuminate\Database\QueryException::class);

    ocAdmin($this, $this->reviewer);
    expect(fn () => $this->service->approve($dispute, $this->checker))->toThrow(ValidationException::class);
    $this->review->reject($dispute, $this->reviewer, 'Original claimant verified with the board.');
    expect($dispute->refresh()->status)->toBe('REJECTED');
});

it('approves a register broker claim onto an existing BROKER tenant and invites the claimant as BROKER_ADMIN', function () {
    $this->post('/organisations/claim/broker/'.$this->broker->id, ocForm())->assertRedirect();
    $claim = OrganisationClaim::firstOrFail();
    $existing = Tenant::create(['type' => 'BROKER', 'legal_name' => 'Boreal Courtage SARL', 'slug' => 'boreal', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'fr', 'settings' => []]);
    ocClean($claim);
    ocAdmin($this, $this->checker);
    $this->review->startReview($claim, $this->checker);
    $this->review->recommend($claim, $this->checker, 'APPROVE', null);
    $claim = $this->service->approve($claim, $this->reviewer, $existing->id);
    expect($claim->linked_tenant_id)->toBe($existing->id)->and($this->broker->refresh()->tenant_id)->toBe($existing->id);
    $inv = TenantInvitation::findOrFail($claim->result['invitation_id']);
    expect($inv->role_code)->toBe('BROKER_ADMIN')->and($inv->tenant_id)->toBe($existing->id);
    // Linked: no further claim possible.
    expect($this->service->lockState($this->service->institution('broker', $this->broker->id)))->toBe('CLAIMED');
});

it('no data leak: unknown / non-register institutions 404, secret links only, platform admins only', function () {
    $this->get('/organisations/claim/insurer/'.Str::uuid())->assertNotFound();
    $this->get('/organisations/claim/broker/'.$this->carrier->id)->assertNotFound(); // wrong kind
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Private Co', 'status' => 'ACTIVE', 'legal_identity' => []]);
    $private = Carrier::create(['party_id' => $party->id, 'cima_code' => 'PRIV-1', 'status' => 'ACTIVE', 'legal_name' => 'PRIVATE', 'is_official_register' => false]);
    $this->get('/organisations/claim/insurer/'.$private->id)->assertNotFound();
    $this->get('/organisations/claim/status/'.Str::random(48))->assertNotFound();

    $this->post('/organisations/claim/insurer/'.$this->carrier->id, ocForm(['website' => 'spam']))->assertRedirect();
    expect(OrganisationClaim::count())->toBe(0);

    $this->post('/organisations/claim/broker/'.$this->broker->id, ocForm())->assertRedirect();
    $claim = OrganisationClaim::firstOrFail();
    expect($claim->toArray())->not->toHaveKey('status_token_hash');
    $outsider = makeAuthTestUser(makeAuthTestTenant(), ['tenant.manage', 'identity.invite'], 'CARRIER_ADMIN');
    $this->actingAs($outsider);
    app(TenantContext::class)->set(TenantMembership::where('user_id', $outsider->id)->value('tenant_id'));
    expect(fn () => $this->review->startReview($claim, $outsider))->toThrow(HttpException::class)
        ->and(\App\Filament\Admin\Resources\OrganisationClaims\OrganisationClaimResource::canViewAny())->toBeFalse();
});
