<?php

declare(strict_types=1);

use App\Application\Notifications\NotificationDispatchService;
use App\Application\Privacy\ConsentService;
use App\Application\Privacy\DataSubjectRequestService;
use App\Application\Security\Purpose\PurposeOfUseDenied;
use App\Application\Security\Purpose\PurposeOfUseGuard;
use App\Models\NotificationTemplate;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave8/Concerns/notification_helpers.php';

it('REQ-SEC-003 guard: unknown purpose and missing consent deny, consent/legal basis allow, every decision is logged', function () {
    $party = Party::create(['type' => 'PERSON', 'display_name' => 'P', 'status' => 'ACTIVE']);
    $g = app(PurposeOfUseGuard::class);

    expect($g->check('NOPE', 'x', $party->id)->refusalCode)->toBe('UNKNOWN_PURPOSE');
    expect($g->check('PORTABILITY_INTERMEDIARY_TRANSFER', 'policy.portability.export', $party->id)->refusalCode)->toBe('NO_CONSENT');
    expect($g->check('PORTABILITY_CUSTOMER_REQUEST', 'policy.portability.export', $party->id)->allowed)->toBeTrue();
    expect(fn () => $g->enforce('MARKETING', 'x', $party->id))->toThrow(PurposeOfUseDenied::class);

    $consent = app(ConsentService::class)->grant($party, null, 'DATA_SHARING', 'n-1', 'WEB', ['affirmed' => true], null);
    $ok = $g->check('PORTABILITY_CARRIER_TRANSFER', 'policy.portability.export', $party->id);
    expect($ok->allowed)->toBeTrue()->and($ok->consentId)->toBe($consent->id);

    app(ConsentService::class)->withdraw($consent, 'CUSTOMER_REQUEST', ['notes' => 'withdrawn'], null);
    expect($g->check('PORTABILITY_CARRIER_TRANSFER', 'policy.portability.export', $party->id)->allowed)->toBeFalse();

    expect(DB::table('purpose_of_use_checks')->where('party_id', $party->id)->count())->toBe(6)
        ->and(DB::table('purpose_of_use_checks')->where('decision', 'DENY')->count())->toBe(4);
});

it('REQ-SEC-003 purposes catalogue is readable and admin-confirmable; consent capture accepts catalogue purposes', function () {
    $tenant = makeAuthTestTenant();
    $admin = makeAuthTestUser($tenant, ['privacy.purposes.read', 'privacy.purposes.manage']);
    Passport::actingAs($admin);
    $codes = collect($this->getJson('/api/v1/privacy/purposes', tenantHeader($tenant))->assertOk()->json('data'))->pluck('basis_status', 'code');
    expect($codes['MARKETING'])->toBe('PLATFORM_PROVISIONAL')->and($codes->keys())->toContain('DSR_FULFILMENT');

    $this->patchJson('/api/v1/privacy/purposes/ANALYTICS', ['basis_status' => 'OWNER_CONFIRMED'], tenantHeader($tenant))->assertOk()->assertJsonPath('data.basis_status', 'OWNER_CONFIRMED');
    expect(DB::table('audit_log')->where('action', 'privacy.purpose.updated')->exists())->toBeTrue();

    Passport::actingAs(makeAuthTestUser($tenant, ['privacy.purposes.read']));
    $this->patchJson('/api/v1/privacy/purposes/ANALYTICS', ['is_active' => false], tenantHeader($tenant))->assertForbidden();
});

it('REQ-SEC-003 DSR export fulfilment is purpose-guarded and logged', function () {
    $tenant = makeAuthTestTenant();
    $maker = makeAuthTestUser($tenant, []);
    $checker = makeAuthTestUser($tenant, []);
    $party = Party::create(['type' => 'PERSON', 'display_name' => 'Subject', 'status' => 'ACTIVE']);
    $svc = app(DataSubjectRequestService::class);
    $x = $svc->receive($tenant->id, ['party_id' => $party->id, 'type' => 'PORTABILITY', 'due_on' => now()->addDays(30)->toDateString()], $maker);
    $x = $svc->verify($x, 'passport-check', $maker);
    expect($svc->resolve($x, 'FULFIL', 'exported', $checker)->status)->toBe('COMPLETED');
    $log = DB::table('purpose_of_use_checks')->where('reference_id', $x->id)->first();
    expect($log->purpose_code)->toBe('DSR_FULFILMENT')->and($log->decision)->toBe('ALLOW')->and($log->lawful_basis)->toBe('LEGAL_OBLIGATION');

    // Deactivating the purpose blocks the export.
    DB::table('processing_purposes')->where('code', 'DSR_FULFILMENT')->update(['is_active' => false]);
    $y = $svc->verify($svc->receive($tenant->id, ['party_id' => $party->id, 'type' => 'ACCESS', 'due_on' => now()->addDays(30)->toDateString()], $maker), 'id', $maker);
    expect(fn () => $svc->resolve($y, 'FULFIL', 'exported', $checker))->toThrow(PurposeOfUseDenied::class);
});

it('REQ-SEC-003 marketing notifications are not sent without MARKETING consent; transactional ones are unaffected', function () {
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);
    $delivery = makeNotificationTestDelivery('SMS', '+237670000001');
    NotificationTemplate::whereKey($delivery->template_id)->update(['purpose' => 'MARKETING']);

    $result = app(NotificationDispatchService::class)->dispatch($delivery);
    expect($result->status)->not->toBe('SENT');
    Http::assertNothingSent();
    expect(DB::table('purpose_of_use_checks')->where('reference_id', $delivery->id)->value('refusal_code'))->toBe('NO_CONSENT');

    $consented = makeNotificationTestDelivery('SMS', '+237670000002');
    NotificationTemplate::whereKey($consented->template_id)->update(['purpose' => 'MARKETING']);
    app(ConsentService::class)->grant(Party::find($consented->party_id), null, 'MARKETING', 'n-1', 'WEB', ['affirmed' => true], null);
    expect(app(NotificationDispatchService::class)->dispatch($consented)->status)->toBe('SENT');

    expect(app(NotificationDispatchService::class)->dispatch(makeNotificationTestDelivery('SMS', '+237670000003'))->status)->toBe('SENT');
});
