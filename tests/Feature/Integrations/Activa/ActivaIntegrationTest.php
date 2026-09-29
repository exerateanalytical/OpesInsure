<?php

declare(strict_types=1);

/**
 * Activa Assurances Cameroun connector (docs/integrations/activa/operations_2026-09-29.json).
 * Http::fake with fixtures derived from Activa's own operation examples (tests/Fixtures/Activa). Fake keys only.
 */

use App\Application\Distribution\Execution\ExecutionContext;
use App\Application\Distribution\Execution\ExecutionOutcome;
use App\Application\Identity\RoleCatalogue;
use App\Application\Integrations\Activa\ActivaApi;
use App\Application\Integrations\Activa\ActivaConnections;
use App\Application\Integrations\Activa\ActivaException;
use App\Application\Integrations\Activa\ActivaGateway;
use App\Application\Integrations\Activa\ActivaPolicySync;
use App\Application\Integrations\Activa\ActivaReconciliation;
use App\Application\Integrations\Activa\ActivaReferenceDataSync;
use App\Application\Quotes\Adapters\QuoteProviderRegistry;
use App\Models\CarrierApiConnection;
use App\Models\CarrierApiSyncRecord;
use App\Models\Document;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const ACTIVA_GW = 'https://activaapimanagement.azure-api.net';

/** Fake secrets: every one of them must never appear in a log, a call row, the audit log or an error. */
const ACTIVA_FAKE_SECRETS = ['fake-sub-key-0001-XYZ', 'fake-travel-secret-0002', 'fake-tarif-pwd-0003', 'fake-souscr-pwd-0004', 'fake-docgen-pwd-0005',
    'eyFAKE.travel.token-0001', 'eyFAKE.souscription.token-0002', 'eyFAKE.tarifiktor.token-0003', 'eyFAKE.docgen.token-0004'];

if (! function_exists('activaFixture')) {
    function activaFixture(string $name): mixed
    {
        $raw = file_get_contents(__DIR__.'/../../../Fixtures/Activa/'.$name);

        return str_ends_with($name, '.json') ? json_decode($raw, true) : $raw;
    }

    function activaAdmin(): User
    {
        return User::create(['full_name' => 'Activa Admin '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    }

    /** A carrier + connection with fake credentials for every service. */
    function activaConnection(string $carrierId, array $settings = []): CarrierApiConnection
    {
        return app(ActivaConnections::class)->save([
            'carrier_id' => $carrierId, 'environment' => 'SANDBOX', 'subscription_key' => 'fake-sub-key-0001-XYZ',
            'credentials' => [
                'travel' => ['client_id' => 'opes-client', 'client_secret' => 'fake-travel-secret-0002'],
                'pricing' => ['email' => 'api@opesinsure.test', 'password' => 'fake-tarif-pwd-0003'],
                'subscription' => ['user_id' => 'opes-souscription', 'password' => 'fake-souscr-pwd-0004'],
                'documents' => ['user_id' => 'opes-docgen', 'password' => 'fake-docgen-pwd-0005'],
            ],
            'settings' => $settings + [
                'intermediary' => ['code_intermediaire' => 'INT-OPES', 'codeinte' => '1234', 'bureau' => 'DLA'],
                'categories' => ['AUTO' => '400', 'MRH' => '100'],
                'attestation' => ['codtypdocument' => 'ATT'],
                'payment' => ['modes' => ['mtn_momo' => 'MOMO'], 'default_mode' => 'ESP'],
                'travel' => ['agent_scope' => 'OPES-AGENT'],
            ],
        ], activaAdmin());
    }

    /** Fresh fake HTTP factory (Http::fake stubs accumulate and the first match wins, so re-faking needs a new factory). */
    function activaHttp(?array $stubs = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        $stubs === null ? Http::fake() : Http::fake($stubs);
    }

    /** Http::fake of all four services; $over: url pattern => response (or sequence) overrides. */
    function activaFake(array $over = []): void
    {
        $pdf = activaFixture('certificate.pdf');
        activaHttp($over + [
            ACTIVA_GW.'/cmr-travel/token' => Http::response(activaFixture('travel_token.json')),
            ACTIVA_GW.'/cmr-travel/travel/quotes_requests' => Http::response(activaFixture('travel_quote_response.json')),
            ACTIVA_GW.'/cmr-travel/travel/policies/155596/certificate' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf']),
            ACTIVA_GW.'/cmr-travel/travel/policies/155596/cancel' => Http::response(activaFixture('travel_policy_cancelled.json')),
            ACTIVA_GW.'/cmr-travel/travel/policies/155596' => Http::response(activaFixture('travel_policy_updated.json')),
            ACTIVA_GW.'/cmr-travel/travel/policies' => Http::response(activaFixture('travel_policy_created.json'), 201),
            ACTIVA_GW.'/tarifiktor-cmr-test/api/Account/Authentication' => Http::response(activaFixture('tarifiktor_token.json')),
            ACTIVA_GW.'/tarifiktor-cmr-test/api/v1/Tarification/tarifpolice' => Http::response(activaFixture('tarifpolice_response.json')),
            ACTIVA_GW.'/souscription-cmr-test/api/v1/Authentication/Authenticate' => Http::response(activaFixture('souscription_token.json')),
            ACTIVA_GW.'/souscription-cmr-test/api/v1/SouscriptionCMR/NewContract*' => Http::response(activaFixture('new_contract_response.json')),
            ACTIVA_GW.'/souscription-cmr-test/api/v1/RenouvellementCMR/*' => Http::response(['idctr' => 'CTR-2027-0000999', 'numepolice' => '4001-2027-000999']),
            ACTIVA_GW.'/souscription-cmr-test/api/v1/SouscriptionCMR/AttestationCMR/*' => Http::response(activaFixture('attestation_response.json')),
            ACTIVA_GW.'/souscription-cmr-test/api/v1/SouscriptionCMR/ReferentialData' => Http::response(activaFixture('referential_data.json')),
            ACTIVA_GW.'/souscription-cmr-test/api/Documents/contrat/*/download/*' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf']),
            ACTIVA_GW.'/souscription-cmr-test/api/Documents/contrat/*' => Http::response(activaFixture('contract_documents.json')),
            ACTIVA_GW.'/souscription-cmr-test/api/v1/EncaissementCMR/Encaissement' => Http::response(activaFixture('encaissement_response.json')),
            ACTIVA_GW.'/docgenerator-cmr-test/api/Authentication/Authenticate' => Http::response(activaFixture('docgenerator_token.json')),
        ]);
    }

    /** An issued policy of $line on the fixture carrier. @return array{policy: Policy, f: array} */
    function activaPolicy(string $line, array $facts, array $overrides = []): array
    {
        $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
        $f['party']->update(['display_name' => 'Kamga Jean', 'legal_identity' => ['first_name' => 'Jean', 'last_name' => 'Kamga', 'date_of_birth' => '1980-05-12', 'gender' => 'M']]);
        DB::table('party_contacts')->insert(['id' => (string) Str::uuid(), 'party_id' => $f['party']->id, 'type' => 'EMAIL', 'normalized_value' => Str::random(6).'@example.test', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $f['quote']->update(['line_code' => $line, 'risk_facts' => $facts]);
        $policy = Policy::create($overrides + [
            'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
            'policy_number' => 'POL-ACT-'.Str::upper(Str::random(6)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->addDay()->startOfDay(), 'coverage_ends_at' => now()->addDays(15)->startOfDay(),
            'terms_snapshot' => ['premium_minor' => 45000, 'tax_minor' => 8325, 'fee_minor' => 5000, 'total_minor' => 58325, 'currency' => 'XAF'], 'version' => 1, 'currency' => 'XAF',
            'premium_minor' => 58325, 'issued_at' => now(),
        ]);

        return ['policy' => $policy, 'f' => $f];
    }

    function activaPayment(Policy $policy, int $amount = 58325): PaymentIntentRecord
    {
        return PaymentIntentRecord::create(['tenant_id' => $policy->tenant_id, 'proposal_id' => $policy->proposal_id, 'provider' => 'mtn_momo', 'provider_reference' => 'MOMO-'.Str::random(8),
            'payer_phone_e164' => '+237670000001', 'amount_minor' => $amount, 'currency' => 'XAF', 'status' => 'SUCCEEDED', 'idempotency_key' => (string) Str::uuid(), 'reconciled_at' => now()]);
    }

    /** Keys we send must be keys of Activa's documented request (recursively for objects and first list item). */
    function activaUnknownKeys(array $ours, array $documented, string $prefix = ''): array
    {
        $bad = [];
        foreach ($ours as $k => $v) {
            if (array_is_list($ours)) {
                return is_array($v) && isset($documented[0]) && is_array($documented[0]) ? activaUnknownKeys($v, $documented[0], $prefix.'[]') : [];
            }
            if (! array_key_exists($k, $documented)) {
                $bad[] = $prefix.$k;
            } elseif (is_array($v) && is_array($documented[$k]) && $v !== []) {
                $bad = [...$bad, ...activaUnknownKeys($v, $documented[$k], $prefix.$k.'.')];
            }
        }

        return $bad;
    }

    function activaTravelFacts(): array
    {
        return ['travel' => ['destination_area' => 'Zone 1 : Schengen, Afrique, Moyen Orient', 'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(15)->toDateString(),
            'destination_country' => 'SCHENGEN', 'nationality' => 'CAMEROUNAISE', 'passport_number' => 'N2222222']];
    }

    function activaMotorFacts(): array
    {
        return ['registration_number' => 'LT-123-AB', 'make' => 'TOYOTA', 'model' => 'Corolla', 'vin' => 'VF1TESTVIN0000002', 'fiscal_power' => 7, 'seats' => 5,
            'vehicle_value' => 5000000, 'first_registration_date' => '2019-03-01', 'usage' => 'PRIVATE'];
    }
}

beforeEach(function () {
    config(['activa.http.retry_base_ms' => 0, 'activa.circuit_breaker.failure_threshold' => 5]);
});

it('stays CONFIG_REQUIRED and sends nothing until credentials exist', function () {
    activaHttp();
    $x = activaPolicy('TRAVEL', activaTravelFacts());
    $c = app(ActivaConnections::class)->save(['carrier_id' => $x['policy']->carrier_id, 'environment' => 'SANDBOX'], activaAdmin());

    expect($c->status)->toBe('CONFIG_REQUIRED')
        ->and(app(ActivaPolicySync::class)->syncPolicy($x['policy']))->toBe(['CONTRACT' => 'CONFIG_REQUIRED']);
    $results = app(ActivaConnections::class)->test($c);
    expect(collect($results)->pluck('state')->unique()->all())->toBe(['CONFIG_REQUIRED']);
    Http::assertNothingSent();

    // REMOTE_API quotation adapter answers INTEGRATION_UNAVAILABLE / CONFIG_REQUIRED (fallback applies).
    $c->forceFill(['status' => 'CONFIG_REQUIRED'])->save();
    $out = app(QuoteProviderRegistry::class)->forMode('REMOTE_API')->execute(new ExecutionContext('quote', $x['f']['quote']->id, $x['policy']->carrier_id));
    expect($out->status)->toBe(ExecutionOutcome::INTEGRATION_UNAVAILABLE)->and($out->errorCode)->toBe('CONFIG_REQUIRED');
});

it('reports 401 per service on Test connection and marks the connection AUTH_FAILED with a clear error', function () {
    $x = activaPolicy('TRAVEL', activaTravelFacts());
    $c = activaConnection($x['policy']->carrier_id);
    expect($c->status)->toBe('PENDING_VERIFICATION');
    activaHttp([ACTIVA_GW.'/*' => Http::response(activaFixture('apim_401.json'), 401)]);

    $r = app(ActivaConnections::class)->test($c);
    expect(collect($r)->pluck('state')->unique()->all())->toBe(['SUBSCRIPTION_KEY_REJECTED'])
        ->and(collect($r)->pluck('http_status')->unique()->all())->toBe([401])
        ->and($r['travel']['message'])->toContain('subscription key');
    expect($c->refresh()->status)->toBe('AUTH_FAILED')->and($c->service_health['subscription']['state'])->toBe('SUBSCRIPTION_KEY_REJECTED');

    // A sync attempt waits on configuration (not a data failure, no exception queue row).
    expect(app(ActivaPolicySync::class)->syncPolicy($x['policy']))->toBe(['CONTRACT' => 'CONFIG_REQUIRED']);
    expect(CarrierApiSyncRecord::first()->last_error_code)->toBe('SUBSCRIPTION_KEY_REJECTED')
        ->and(DB::table('issuance_exceptions')->count())->toBe(0);

    // Wrong login (401 without the APIM subscription message) → AUTH_FAILED.
    activaHttp([ACTIVA_GW.'/*' => Http::response(['message' => 'Invalid credentials'], 401)]);
    expect(app(ActivaConnections::class)->test($c)['pricing']['state'])->toBe('AUTH_FAILED');
});

it('caches the token per service, refreshes it before expiry and re-authenticates once on a 401', function () {
    $x = activaPolicy('TRAVEL', activaTravelFacts());
    $c = activaConnection($x['policy']->carrier_id);
    activaFake();
    $api = app(ActivaApi::class);
    $body = activaFixture('travel_quote_request.json');

    $api->travelQuote($c, $body);
    $api->travelQuote($c, $body);
    Http::assertSentCount(3); // 1 token + 2 quotes
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/travel/quotes_requests') && $r->hasHeader('Authorization', 'Bearer eyFAKE.travel.token-0001')
        && $r->hasHeader('Ocp-Apim-Subscription-Key', 'fake-sub-key-0001-XYZ') && $r->hasHeader('x-correlation-id'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/token') && $r['grant_type'] === 'client_credentials' && $r['client_id'] === 'opes-client');

    // expires_in 3600 − skew 120: at +3500s the token is refreshed.
    Carbon::setTestNow(now()->addSeconds(3500));
    $api->travelQuote($c, $body);
    expect(collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/token'))->count())->toBe(2);
    Carbon::setTestNow();

    // Revoked token: data call 401 → token dropped, re-acquired once, call replayed.
    activaHttp([
        ACTIVA_GW.'/cmr-travel/token' => Http::response(activaFixture('travel_token.json')),
        ACTIVA_GW.'/cmr-travel/travel/quotes_requests' => Http::sequence()->push(['message' => 'token expired'], 401)->push(activaFixture('travel_quote_response.json')),
    ]);
    expect($api->travelQuote($c, $body)['quote_code'])->toBe('QT-2026-000123');
    Http::assertSentCount(3);
});

it('retries 5xx and timeouts with backoff, never 4xx, and opens the circuit breaker', function () {
    $x = activaPolicy('TRAVEL', activaTravelFacts());
    $c = activaConnection($x['policy']->carrier_id);
    $body = activaFixture('travel_quote_request.json');
    activaHttp([
        ACTIVA_GW.'/cmr-travel/token' => Http::response(activaFixture('travel_token.json')),
        ACTIVA_GW.'/cmr-travel/travel/quotes_requests' => Http::sequence()->push('', 503)->push('', 502)->push(activaFixture('travel_quote_response.json')),
    ]);
    expect(app(ActivaApi::class)->travelQuote($c, $body)['quote_code'])->toBe('QT-2026-000123');
    $row = DB::table('carrier_api_calls')->where('operation', 'getTravelQuote')->first();
    expect($row->attempts)->toBe(3)->and($row->outcome)->toBe('OK');

    activaHttp([ACTIVA_GW.'/cmr-travel/token' => Http::response(activaFixture('travel_token.json')), ACTIVA_GW.'/cmr-travel/travel/quotes_requests' => Http::response(['errors' => ['travel.start_date' => ['bad']]], 400)]);
    expect(fn () => app(ActivaApi::class)->travelQuote($c, $body))->toThrow(fn (ActivaException $e) => expect($e->errorCode)->toBe('REJECTED')->and($e->getMessage())->toContain('travel.start_date'));
    expect(collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), 'quotes_requests'))->count())->toBe(1); // 4xx: no retry

    config(['activa.circuit_breaker.failure_threshold' => 2, 'activa.http.retries' => 1]);
    activaHttp([ACTIVA_GW.'/cmr-travel/token' => Http::response(activaFixture('travel_token.json')), ACTIVA_GW.'/cmr-travel/travel/quotes_requests' => Http::response('', 500)]);
    foreach ([1, 2] as $_) {
        expect(fn () => app(ActivaApi::class)->travelQuote($c, $body))->toThrow(fn (ActivaException $e) => expect($e->errorCode)->toBe('UNAVAILABLE'));
    }
    $sent = count(Http::recorded());
    expect(fn () => app(ActivaApi::class)->travelQuote($c, $body))->toThrow(fn (ActivaException $e) => expect($e->errorCode)->toBe('CIRCUIT_OPEN'));
    expect(count(Http::recorded()))->toBe($sent); // failed fast, nothing sent
});

it('travel: quote → subscribe → certificate stored as the carrier original, then update and cancel', function () {
    $x = activaPolicy('TRAVEL', activaTravelFacts());
    $c = activaConnection($x['policy']->carrier_id);
    activaFake();

    $steps = app(ActivaPolicySync::class)->syncPolicy($x['policy']);
    expect($steps)->toBe(['CONTRACT' => 'SYNCED', 'DOCUMENT' => 'SYNCED']);

    $contract = CarrierApiSyncRecord::where(['subject_id' => $x['policy']->id, 'operation' => 'CONTRACT'])->first();
    expect($contract->external_reference)->toBe('155596')->and($contract->external_policy_number)->toBe('ACT-VOY-2026-155596')
        ->and($x['policy']->refresh()->carrier_contract_reference)->toBe('ACT-VOY-2026-155596')
        ->and($x['f']['proposal']->offer->refresh()->external_reference)->toBe('QT-2026-000123');

    $doc = Document::find(CarrierApiSyncRecord::where(['subject_id' => $x['policy']->id, 'operation' => 'DOCUMENT'])->value('document_id'));
    expect($doc->is_carrier_original)->toBeTrue()->and($doc->document_type_code)->toBe('TRAVEL_INSURANCE_CERTIFICATE')
        ->and($doc->sha256)->toBe(hash('sha256', activaFixture('certificate.pdf')))->and($doc->generation_trigger)->toBe('CARRIER_API')
        ->and($doc->uploaded_by)->toBeNull();

    // Request bodies use only Activa's documented keys; payment is managed by the partner.
    $sentQuote = collect(Http::recorded())->first(fn ($p) => str_ends_with($p[0]->url(), 'quotes_requests'))[0]->data();
    $sentPolicy = collect(Http::recorded())->first(fn ($p) => str_ends_with($p[0]->url(), '/travel/policies'))[0]->data();
    expect(activaUnknownKeys($sentQuote, activaFixture('travel_quote_request.json')))->toBe([])
        ->and(activaUnknownKeys($sentPolicy, activaFixture('travel_policy_request.json')))->toBe([])
        ->and($sentPolicy['quote_code'])->toBe('QT-2026-000123')->and($sentPolicy['payment'])->toBe(['type' => 'MANAGED_BY_PARTNER'])
        ->and($sentPolicy['policy_holder'][0]['last_name'])->toBe('Kamga')->and($sentQuote['travel']['travelers']['oldest_traveler_age'])->toBeGreaterThan(40);

    // Idempotent: a second run sends nothing.
    $count = count(Http::recorded());
    app(ActivaPolicySync::class)->syncPolicy($x['policy']);
    expect(count(Http::recorded()))->toBe($count);

    // Endorsement (version bump) → PATCH; cancellation → cancel. Both found by the reconciliation.
    $c->forceFill(['status' => 'ACTIVE'])->save();
    $x['policy']->update(['version' => 2, 'coverage_ends_at' => now()->addDays(20)]);
    expect(app(ActivaReconciliation::class)->run()['updated'])->toBe(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/travel/policies/155596'));
    $x['policy']->update(['status' => 'CANCELLED']);
    expect(app(ActivaReconciliation::class)->run()['cancelled'])->toBe(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/155596/cancel') && isset($r['cancellation_reason']));
});

it('motor: new contract + attestation + contract document, then the collected payment via EncaissementCMR', function () {
    $x = activaPolicy('MOTOR', activaMotorFacts());
    activaConnection($x['policy']->carrier_id);
    activaFake();
    $payment = activaPayment($x['policy']);

    $steps = app(ActivaPolicySync::class)->syncPolicy($x['policy']);
    expect($steps)->toMatchArray(['CONTRACT' => 'SYNCED', 'ATTESTATION' => 'SYNCED', 'DOCUMENT' => 'SYNCED', 'PAYMENT:'.$payment->id => 'SYNCED']);

    $sent = fn (string $end) => collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), $end))[0];
    $contract = $sent('/SouscriptionCMR/NewContractCMR')->data();
    expect(activaUnknownKeys($contract, activaFixture('new_contract_request.json')))->toBe([])
        ->and($contract['codecate'])->toBe(400)->and($contract['primtota'])->toBe(58325)->and($contract['primnett'])->toBe(45000)
        ->and($contract['detailproduction'][0]['numeimma'])->toBe('LT-123-AB')->and($contract['vassure']['raissoci'])->toBe('Kamga')
        ->and($contract['refeinte'])->toBe($x['policy']->policy_number)->and($contract['code_intermediaire'])->toBe('INT-OPES');
    expect($sent('/AttestationCMR/CTR-2026-0000456')->data())->toMatchArray(['codeinte' => 1234, 'codtypdocument' => 'ATT']);
    expect($sent('/download/')->url())->toContain('ATTESTATION_AUTO.pdf'); // motor: the attestation is the document kept

    $enc = $sent('/EncaissementCMR/Encaissement')->data();
    expect(activaUnknownKeys($enc, activaFixture('encaissement_request.json')))->toBe([])
        ->and($enc['encaissement'][0])->toMatchArray(['idctr' => 'CTR-2026-0000456', 'montenca' => 58325, 'modepaie' => 'MOMO', 'refeenca' => $payment->provider_reference]);

    expect($x['policy']->refresh()->carrier_contract_reference)->toBe('4001-2026-000456')
        ->and(CarrierApiSyncRecord::where(['subject_id' => $payment->id, 'operation' => 'PAYMENT'])->value('external_reference'))->toBe('ENC-2026-0001')
        ->and(Document::where('policy_id', $x['policy']->id)->where('is_carrier_original', true)->value('document_type_code'))->toBe('MOTOR_INSURANCE_CERTIFICATE');

    // A later instalment: the payment hook records it (sync queue runs after commit).
    $second = activaPayment($x['policy'], 10000);
    app(\App\Application\Integrations\Activa\ActivaHooks::class)->paymentSucceeded($second);
    expect(CarrierApiSyncRecord::where(['subject_id' => $second->id, 'operation' => 'PAYMENT'])->value('status'))->toBe('SYNCED');
});

it('renewal: a policy renewing an Activa contract goes through RenouvellementCMR with the previous idctr', function () {
    $prev = activaPolicy('MOTOR', activaMotorFacts());
    activaConnection($prev['policy']->carrier_id);
    activaFake();
    app(ActivaPolicySync::class)->syncPolicy($prev['policy']);

    $renewal = Policy::create([...collect($prev['policy']->getAttributes())->except(['id', 'policy_number', 'carrier_contract_reference', 'created_at', 'updated_at'])->all(),
        'policy_number' => 'POL-ACT-REN-'.Str::random(4), 'previous_policy_id' => $prev['policy']->id, 'terms_snapshot' => $prev['policy']->terms_snapshot]);
    $steps = app(ActivaPolicySync::class)->syncPolicy($renewal);
    expect($steps['CONTRACT'])->toBe('SYNCED');

    $body = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), '/RenouvellementCMR/RenewContractCMR'))[0]->data();
    expect($body['idctr'])->toBe('CTR-2026-0000456')->and($body['affaireNouvelleID'])->toBe('CTR-2026-0000456')
        ->and(activaUnknownKeys($body, activaFixture('renew_contract_request.json')))->toBe([])
        ->and(CarrierApiSyncRecord::where(['subject_id' => $renewal->id, 'operation' => 'CONTRACT'])->value('external_reference'))->toBe('CTR-2027-0000999');
});

it('stops with MAPPING_REQUIRED (never a guessed code) and surfaces it in the issuance-exception and operations queues', function () {
    $x = activaPolicy('MOTOR', activaMotorFacts());
    activaConnection($x['policy']->carrier_id, ['categories' => []]);
    CarrierApiConnection::first()->forceFill(['settings' => array_merge(CarrierApiConnection::first()->settings, ['categories' => []])])->save();
    activaFake();
    activaPayment($x['policy']);

    expect(app(ActivaPolicySync::class)->syncPolicy($x['policy'])['CONTRACT'])->toBe('MAPPING_REQUIRED');
    $rec = CarrierApiSyncRecord::first();
    expect($rec->last_error)->toContain('codecate');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'NewContract'));

    $ex = DB::table('issuance_exceptions')->where('proposal_id', $x['policy']->proposal_id)->first();
    expect($ex->reason_code)->toBe('CARRIER_API_MAPPING_REQUIRED')->and($ex->status)->toBe('OPEN');
    $ops = app(\App\Application\Operations\OperationalExceptionQueue::class)->summary($x['policy']->tenant_id, false, null, ['carrier_api_sync_failures']);
    expect($ops['sources']['carrier_api_sync_failures']['count'])->toBe(1);

    // Fixed mapping + retry → synced, exception closed.
    $c = CarrierApiConnection::first();
    $c->forceFill(['settings' => array_merge($c->settings, ['categories' => ['AUTO' => '400']])])->save();
    expect(app(ActivaPolicySync::class)->syncPolicy($x['policy'], true)['CONTRACT'])->toBe('SYNCED')
        ->and(DB::table('issuance_exceptions')->where('id', $ex->id)->value('status'))->toBe('RESOLVED');
});

it('reconciliation resumes everything once Activa approves the subscription, and retries due transient failures', function () {
    $x = activaPolicy('TRAVEL', activaTravelFacts());
    $c = activaConnection($x['policy']->carrier_id);

    // Today: keys return 401. The issuance hook queues nothing harmful; the record waits on configuration.
    activaHttp([ACTIVA_GW.'/*' => Http::response(activaFixture('apim_401.json'), 401)]);
    app(ActivaPolicySync::class)->syncPolicy($x['policy']);
    expect(app(ActivaReconciliation::class)->run()['probed'])->toBe(1);
    expect($c->refresh()->status)->toBe('AUTH_FAILED')->and(CarrierApiSyncRecord::first()->status)->toBe('CONFIG_REQUIRED');

    // Activa approves: next pass probes OK → ACTIVE → the backlog is synced without anyone touching anything.
    activaFake();
    $s = app(ActivaReconciliation::class)->run();
    expect($s['probed'])->toBe(1)->and($s['policies'])->toBe(1)->and($c->refresh()->status)->toBe('ACTIVE')
        ->and(CarrierApiSyncRecord::where('operation', 'CONTRACT')->value('status'))->toBe('SYNCED');

    // A transient failure → RETRY_PENDING with backoff; reconciliation retries it once due.
    $y = activaPolicy('TRAVEL', activaTravelFacts());
    CarrierApiConnection::first()->update(['carrier_id' => $c->carrier_id]);
    DB::table('policies')->where('id', $y['policy']->id)->update(['carrier_id' => $c->carrier_id]);
    $y['policy']->refresh();
    config(['activa.http.retries' => 0, 'activa.circuit_breaker.failure_threshold' => 50]);
    activaFake([ACTIVA_GW.'/cmr-travel/travel/policies' => Http::response('', 503)]);
    expect(app(ActivaPolicySync::class)->syncPolicy($y['policy'])['CONTRACT'])->toBe('RETRY_PENDING');
    $rec = CarrierApiSyncRecord::where(['subject_id' => $y['policy']->id, 'operation' => 'CONTRACT'])->first();
    expect($rec->next_attempt_at->isFuture())->toBeTrue()->and($rec->attempts)->toBe(1);

    activaFake();
    expect(app(ActivaReconciliation::class)->run()['policies'])->toBe(0); // not due yet
    Carbon::setTestNow(now()->addMinutes(5));
    app(ActivaReconciliation::class)->run();
    Carbon::setTestNow();
    expect($rec->refresh()->status)->toBe('SYNCED');
});

it('reference data sync is idempotent, retires vanished items and links exact matches to master data', function () {
    $x = activaPolicy('MOTOR', activaMotorFacts());
    $c = activaConnection($x['policy']->carrier_id);
    $domain = (string) Str::uuid();
    DB::table('master_data_domains')->insert(['id' => $domain, 'code' => 'activa_test', 'label_en' => 'T', 'label_fr' => 'T', 'created_at' => now(), 'updated_at' => now()]);
    $list = (string) Str::uuid();
    DB::table('master_data_lists')->insert(['id' => $list, 'domain_id' => $domain, 'domain_code' => 'activa_test', 'code' => 'activa_test_brand', 'label_en' => 'Brand', 'label_fr' => 'Marque', 'created_at' => now(), 'updated_at' => now()]);
    $value = (string) Str::uuid();
    DB::table('master_data_values')->insert(['id' => $value, 'list_id' => $list, 'domain_code' => 'activa_test', 'list_code' => 'activa_test_brand', 'code' => 'TOYOTA_X', 'label_en' => 'Toyota', 'label_fr' => 'Toyota', 'created_at' => now(), 'updated_at' => now()]);
    config(['activa.reference_domains' => ['marques' => 'activa_test_brand']]);
    activaFake();

    $first = app(ActivaReferenceDataSync::class)->run();
    expect($first)->toMatchArray(['received' => 3, 'created' => 3, 'updated' => 0, 'unchanged' => 0, 'mapped' => 1]);
    $second = app(ActivaReferenceDataSync::class)->run();
    expect($second)->toMatchArray(['received' => 3, 'created' => 0, 'updated' => 0, 'unchanged' => 3, 'retired' => 0, 'mapped' => 0]);
    expect(DB::table('carrier_reference_data')->count())->toBe(3)
        ->and(DB::table('carrier_master_data_mappings')->where(['carrier_id' => $c->carrier_id, 'value_id' => $value, 'target' => 'ACTIVA'])->value('external_code'))->toBe('TOY');

    activaHttp([ACTIVA_GW.'/souscription-cmr-test/api/v1/Authentication/Authenticate' => Http::response(activaFixture('souscription_token.json')),
        ACTIVA_GW.'/souscription-cmr-test/api/v1/SouscriptionCMR/ReferentialData' => Http::response(['marques' => [['codemarq' => 'TOY', 'libemarq' => 'TOYOTA MOTORS']]])]);
    $third = app(ActivaReferenceDataSync::class)->run();
    expect($third)->toMatchArray(['received' => 1, 'created' => 0, 'updated' => 1, 'retired' => 2])
        ->and($c->refresh()->last_reference_sync_at)->not->toBeNull();
});

it('REMOTE_API adapters: quotation through Activa (travel quote, motor Tarifiktor) and issuance through the policy sync', function () {
    $x = activaPolicy('TRAVEL', activaTravelFacts());
    $c = activaConnection($x['policy']->carrier_id);
    activaFake();
    $c->forceFill(['status' => 'ACTIVE'])->save();

    $preview = app(QuoteProviderRegistry::class)->forMode('REMOTE_API')->execute(new ExecutionContext('preview', null, $c->carrier_id));
    expect($preview->status)->toBe(ExecutionOutcome::CARRIER_API_READY);
    Http::assertNothingSent();

    $q = app(QuoteProviderRegistry::class)->forMode('REMOTE_API')->execute(new ExecutionContext('quote', $x['f']['quote']->id, $c->carrier_id));
    expect($q->status)->toBe(ExecutionOutcome::CARRIER_EXECUTED)->and($q->data['quote_code'])->toBe('QT-2026-000123');

    $issued = app(\App\Application\Policies\Adapters\PolicyIssuerRegistry::class)->forMode('REMOTE_API')->execute(new ExecutionContext('policy', $x['policy']->id, $c->carrier_id));
    expect($issued->status)->toBe(ExecutionOutcome::CARRIER_EXECUTED)->and($issued->data['contract_reference'])->toBe('155596')->and($issued->data['document_id'])->not->toBeNull();

    $m = activaPolicy('MOTOR', activaMotorFacts());
    DB::table('quotes')->where('id', $m['f']['quote']->id)->update(['tenant_id' => $x['policy']->tenant_id]);
    $mq = app(QuoteProviderRegistry::class)->forMode('REMOTE_API')->execute(new ExecutionContext('quote', $m['f']['quote']->id, $c->carrier_id));
    expect($mq->status)->toBe(ExecutionOutcome::CARRIER_EXECUTED)->and($mq->data['pricing']['primeTotale'])->toBe(58325);
    $tarif = collect(Http::recorded())->first(fn ($p) => str_contains($p[0]->url(), 'tarifpolice'))[0]->data();
    expect(activaUnknownKeys($tarif, activaFixture('tarifpolice_request.json')))->toBe([])->and($tarif['code_categorie'])->toBe(400);
});

it('never writes a secret or a token to logs, call rows, the audit log, errors or plaintext columns', function () {
    $logged = [];
    Log::listen(function ($e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context);
    });
    $x = activaPolicy('MOTOR', activaMotorFacts());
    $c = activaConnection($x['policy']->carrier_id);
    activaFake();
    activaPayment($x['policy']);
    app(ActivaPolicySync::class)->syncPolicy($x['policy']);
    activaHttp([ACTIVA_GW.'/*' => Http::response(activaFixture('apim_401.json'), 401)]);
    app(ActivaConnections::class)->test($c);
    $y = activaPolicy('TRAVEL', activaTravelFacts());
    DB::table('policies')->where('id', $y['policy']->id)->update(['carrier_id' => $c->carrier_id]);
    app(ActivaPolicySync::class)->syncPolicy($y['policy']->refresh());

    $haystacks = [
        implode("\n", $logged),
        DB::table('carrier_api_calls')->get()->toJson(),
        DB::table('audit_log')->get(['action', 'metadata', 'reason_code', 'old_values', 'new_values'])->toJson(),
        DB::table('carrier_api_sync_records')->get()->toJson(),
        DB::table('carrier_api_connections')->get(['subscription_key', 'service_credentials', 'settings', 'service_health'])->toJson(),
        json_encode(app(ActivaConnections::class)->present($c->refresh())),
        json_encode($c->toArray()),
    ];
    foreach (ACTIVA_FAKE_SECRETS as $secret) {
        foreach ($haystacks as $i => $h) {
            expect(str_contains($h, $secret))->toBeFalse("secret {$secret} leaked in haystack #{$i}");
        }
    }
    expect(DB::table('carrier_api_calls')->count())->toBeGreaterThan(5)
        ->and(DB::table('audit_log')->where('action', 'integration.carrier_api.call')->count())->toBe(DB::table('carrier_api_calls')->count());
});

it('admin page renders for integrations.manage (no secrets shown) and is hidden without it', function () {
    $tenant = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'Activa Page '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $make = function (string $role) use ($tenant) {
        $u = User::factory()->create(['status' => 'ACTIVE', 'locale' => 'en']);
        $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u->id, 'role_code' => $role, 'status' => 'ACTIVE']);
        $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
        $m->roles()->syncWithoutDetaching([$r->id]);

        return $u;
    };
    $x = activaPolicy('TRAVEL', activaTravelFacts());
    activaConnection($x['policy']->carrier_id);

    $html = $this->actingAs($make('SYSTEM_ADMIN'))->get('/admin/integrations/activa')->assertOk()->getContent();
    expect($html)->toContain('Activa Assurances')->toContain('cmr-travel');
    foreach (ACTIVA_FAKE_SECRETS as $secret) {
        expect(str_contains($html, $secret))->toBeFalse();
    }
    $this->flushSession();
    app()->forgetInstance(\Filament\Navigation\NavigationManager::class);
    expect($this->actingAs($make('CLAIMS_OFFICER'))->get('/admin/integrations/activa')->status())->toBe(403);
});

it('the issuance hook books the policy at Activa after the issuing transaction commits, and is a no-op for other carriers', function () {
    $x = activaPolicy('TRAVEL', activaTravelFacts());
    activaConnection($x['policy']->carrier_id);
    activaFake();
    DB::transaction(function () use ($x) {
        app(\App\Application\Integrations\Activa\ActivaHooks::class)->policyIssued($x['policy']);
        expect(CarrierApiSyncRecord::count())->toBe(0); // nothing before commit
    });
    expect(CarrierApiSyncRecord::where(['subject_id' => $x['policy']->id, 'operation' => 'CONTRACT'])->value('status'))->toBe('SYNCED');

    $other = activaPolicy('TRAVEL', activaTravelFacts());
    activaHttp();
    app(\App\Application\Integrations\Activa\ActivaHooks::class)->policyIssued($other['policy']);
    Http::assertNothingSent();
});
