<?php

declare(strict_types=1);

use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\Carrier;
use App\Models\CustomerAttribution;
use App\Models\Partner;
use App\Models\Party;
use App\Models\PolicyIssuanceRequest;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

const B87_ALL = ['policies.portfolio_transfer.request', 'policies.portfolio_transfer.approve', 'policies.portfolio_transfer.read', 'policies.portability.export'];

function b87H(Tenant $t): array
{
    return ['X-Tenant-Id' => $t->id, 'Idempotency-Key' => (string) Str::uuid()];
}

function b87Staff(Tenant $t, string $phone, array $perms = B87_ALL): User
{
    $u = User::create(['full_name' => 'Portfolio Ops', 'phone_e164' => $phone, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'PF_OPS', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'PF_OPS_'.Str::random(4), 'permissions' => $perms, 'is_system' => false])->id);

    return $u;
}

function b87Partner(Tenant $t, string $status = 'ACTIVE'): Partner
{
    return Partner::create(['tenant_id' => $t->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Broker '.Str::random(4), 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => $status, 'compliance' => []]);
}

/** Three in-force policies of customers attributed to one broker. */
function b87Book(Tenant $t, Partner $from, User $recorder): array
{
    $out = [];
    foreach ([1, 2, 3] as $i) {
        $c = makeMobileFinanceProposalChain($t);
        CustomerAttribution::create(['party_id' => $c['party']->id, 'partner_id' => $from->id, 'origin_type' => 'BROKER', 'terms_version' => 't1', 'effective_from' => now()->subYear(), 'status' => 'ACTIVE', 'recorded_by' => $recorder->id]);
        $out[$i] = makeMobileTestPolicy($c['proposal'], $t, $c['carrier']->id, $c['party']->id, ['policy_number' => 'POL-B87-'.$i.'-'.Str::random(4), 'status' => 'ACTIVE', 'issued_at' => now()->subMonth()]);
    }

    return $out;
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-POL-009 transfers servicing of an intermediary book with maker-checker, notice, history and audit', function () {
    $a = makeMobileAgentFixture('+237680087001');
    $t = $a['tenant'];
    $from = b87Partner($t);
    $to = b87Partner($t);
    $maker = b87Staff($t, '+237670087001');
    $checker = b87Staff($t, '+237670087002');
    $book = b87Book($t, $from, $maker);
    $originalCarrier = $book[1]->carrier_id;

    Passport::actingAs($maker);
    $sel = ['scope' => 'INTERMEDIARY', 'from_id' => $from->id, 'to_id' => $to->id];
    $preview = $this->postJson('/api/v1/policy-portfolio-transfers/preview', $sel, b87H($t))->assertOk()->json('data');
    expect($preview['policy_count'])->toBe(3);

    $body = $sel + ['preview_hash' => $preview['preview_hash'], 'reason_code' => 'PARTNER_EXIT', 'notice_mode' => 'NOTICE'];
    $this->postJson('/api/v1/policy-portfolio-transfers', ['preview_hash' => str_repeat('0', 64)] + $body, b87H($t))->assertStatus(422);
    $req = $this->postJson('/api/v1/policy-portfolio-transfers', $body, b87H($t))->assertStatus(201)->json('data');
    expect($req['status'])->toBe('PENDING_APPROVAL')->and($req['items'])->toHaveCount(3);

    // Nothing moved yet; the same policies cannot be requested twice.
    expect(DB::table('policies')->where('id', $book[1]->id)->value('servicing_partner_id'))->toBeNull();
    $again = $this->postJson('/api/v1/policy-portfolio-transfers/preview', $sel, b87H($t))->assertOk()->json('data');
    expect($again['policy_count'])->toBe(0)->and($again['excluded'])->toHaveCount(3);

    // Maker cannot approve their own request.
    $this->postJson("/api/v1/policy-portfolio-transfers/{$req['id']}/approve", [], b87H($t))->assertStatus(422);

    Passport::actingAs($checker);
    $done = $this->postJson("/api/v1/policy-portfolio-transfers/{$req['id']}/approve", ['notes' => 'ok'], b87H($t))->assertOk()->json('data');
    expect($done['status'])->toBe('COMPLETED')->and(collect($done['items'])->pluck('status')->unique()->all())->toBe(['APPLIED'])
        ->and(collect($done['items'])->every(fn ($i) => $i['notice_sent_at'] !== null))->toBeTrue();

    $pol = DB::table('policies')->find($book[1]->id);
    expect($pol->servicing_partner_id)->toBe($to->id)->and($pol->carrier_id)->toBe($originalCarrier);

    $hist = $this->getJson("/api/v1/policies/{$book[1]->id}/servicing-history", b87H($t))->assertOk()->json('data');
    expect($hist)->toHaveCount(1)->and($hist[0]['from_id'])->toBe($from->id)->and($hist[0]['to_id'])->toBe($to->id);

    expect(DB::table('outbox_messages')->where('event_name', 'policy.servicing.transferred')->count())->toBe(3)
        ->and(DB::table('outbox_messages')->where('event_name', 'policy.portfolio_transfer.approved')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'policy.portfolio_transfer.approved')->exists())->toBeTrue();

    // Servicing history is append-only.
    expect(fn () => DB::transaction(fn () => DB::table('policy_servicing_events')->where('policy_id', $book[1]->id)->delete()))->toThrow(\Illuminate\Database\QueryException::class);

    // The book now belongs to the receiving broker (explicit servicing wins over attribution).
    Passport::actingAs($maker);
    $back = $this->postJson('/api/v1/policy-portfolio-transfers/preview', ['scope' => 'INTERMEDIARY', 'from_id' => $to->id, 'to_id' => $from->id], b87H($t))->assertOk()->json('data');
    expect($back['policy_count'])->toBe(3);
});

it('REQ-POL-009 carrier transfer in CONSENT mode applies only consented policies and excludes refusals', function () {
    $a = makeMobileAgentFixture('+237680087010');
    $t = $a['tenant'];
    $maker = b87Staff($t, '+237670087010');
    $checker = b87Staff($t, '+237670087011');
    $c1 = makeMobileFinanceProposalChain($t);
    $c2 = makeMobileFinanceProposalChain($t);
    $p1 = makeMobileTestPolicy($c1['proposal'], $t, $c1['carrier']->id, $c1['party']->id, ['policy_number' => 'POL-C-1', 'status' => 'ACTIVE']);
    $c2['proposal']->update(['party_id' => $c2['party']->id]);
    $p2 = makeMobileTestPolicy($c2['proposal'], $t, $c1['carrier']->id, $c2['party']->id, ['policy_number' => 'POL-C-2', 'status' => 'ACTIVE']);
    $toCarrier = $c2['carrier'];

    Passport::actingAs($maker);
    $sel = ['scope' => 'CARRIER', 'from_id' => $c1['carrier']->id, 'to_id' => $toCarrier->id];
    $preview = $this->postJson('/api/v1/policy-portfolio-transfers/preview', $sel, b87H($t))->assertOk()->json('data');
    expect($preview['policy_count'])->toBe(2);
    $req = $this->postJson('/api/v1/policy-portfolio-transfers', $sel + ['preview_hash' => $preview['preview_hash'], 'reason_code' => 'CARRIER_RUN_OFF', 'notice_mode' => 'CONSENT'], b87H($t))->assertStatus(201)->json('data');

    Passport::actingAs($checker);
    $ok = $this->postJson("/api/v1/policy-portfolio-transfers/{$req['id']}/approve", [], b87H($t))->assertOk()->json('data');
    expect($ok['status'])->toBe('AWAITING_CONSENT')->and(DB::table('policies')->find($p1->id)->servicing_carrier_id)->toBeNull()
        ->and(DB::table('outbox_messages')->where('event_name', 'policy.portfolio_transfer.consent_requested')->count())->toBe(2);

    $this->postJson("/api/v1/policy-portfolio-transfers/{$req['id']}/policies/{$p1->id}/consent", ['granted' => true, 'evidence' => 'signed letter ref 12'], b87H($t))->assertOk()->assertJsonPath('data.status', 'AWAITING_CONSENT');
    $final = $this->postJson("/api/v1/policy-portfolio-transfers/{$req['id']}/policies/{$p2->id}/consent", ['granted' => false, 'evidence' => 'call 2026-09-25'], b87H($t))->assertOk()->json('data');
    expect($final['status'])->toBe('COMPLETED');
    expect(DB::table('policies')->find($p1->id)->servicing_carrier_id)->toBe($toCarrier->id)
        ->and(DB::table('policies')->find($p1->id)->carrier_id)->toBe($c1['carrier']->id)
        ->and(DB::table('policies')->find($p2->id)->servicing_carrier_id)->toBeNull();
    $this->postJson("/api/v1/policy-portfolio-transfers/{$req['id']}/policies/{$p2->id}/consent", ['granted' => true, 'evidence' => 'late'], b87H($t))->assertStatus(422);
});

it('REQ-POL-009 rejection moves nothing; permissions and tenant isolation are enforced', function () {
    $a = makeMobileAgentFixture('+237680087020');
    $t = $a['tenant'];
    $from = b87Partner($t);
    $to = b87Partner($t);
    $inactive = b87Partner($t, 'SUSPENDED');
    $maker = b87Staff($t, '+237670087020');
    $checker = b87Staff($t, '+237670087021');
    $reader = b87Staff($t, '+237670087022', ['policies.portfolio_transfer.read']);
    $book = b87Book($t, $from, $maker);

    Passport::actingAs($maker);
    $this->postJson('/api/v1/policy-portfolio-transfers/preview', ['scope' => 'INTERMEDIARY', 'from_id' => $from->id, 'to_id' => $inactive->id], b87H($t))->assertStatus(422);
    $sel = ['scope' => 'INTERMEDIARY', 'from_id' => $from->id, 'to_id' => $to->id, 'policy_ids' => [$book[1]->id]];
    $p = $this->postJson('/api/v1/policy-portfolio-transfers/preview', $sel, b87H($t))->json('data');
    expect($p['policy_count'])->toBe(1);
    $req = $this->postJson('/api/v1/policy-portfolio-transfers', $sel + ['preview_hash' => $p['preview_hash'], 'reason_code' => 'OTHER', 'notice_mode' => 'NOTICE'], b87H($t))->assertStatus(201)->json('data');

    Passport::actingAs($reader);
    $this->getJson("/api/v1/policy-portfolio-transfers/{$req['id']}", b87H($t))->assertOk();
    $this->postJson("/api/v1/policy-portfolio-transfers/{$req['id']}/approve", [], b87H($t))->assertStatus(403);

    Passport::actingAs($checker);
    $this->postJson("/api/v1/policy-portfolio-transfers/{$req['id']}/reject", ['notes' => 'wrong book'], b87H($t))->assertOk()->assertJsonPath('data.status', 'REJECTED');
    $this->postJson("/api/v1/policy-portfolio-transfers/{$req['id']}/approve", [], b87H($t))->assertStatus(422);
    expect(DB::table('policies')->find($book[1]->id)->servicing_partner_id)->toBeNull();

    $other = makeMobileAgentFixture('+237680087029');
    $foreign = b87Staff($other['tenant'], '+237670087029');
    Passport::actingAs($foreign);
    $this->getJson("/api/v1/policy-portfolio-transfers/{$req['id']}", b87H($other['tenant']))->assertStatus(404);
});

function b87IssuedPolicy(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => [
        'offer_id' => null, 'quote_id' => $f['quote']->id, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF',
        'coverage_snapshot' => ['coverages' => [
            ['code' => 'RC', 'name' => ['en' => 'Liability'], 'mandatory' => true, 'optional' => false, 'limit_minor' => 50_000_000, 'deductible_minor' => null],
            ['code' => 'DOM', 'name' => ['en' => 'Own damage'], 'mandatory' => false, 'optional' => true, 'limit_minor' => 8_000_000, 'deductible_minor' => 100_000],
        ]],
    ]]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $f['payment']->provider_reference, 'amount_minor' => $f['payment']->amount_minor,
        'currency' => $f['payment']->currency, 'status' => 'SUCCEEDED',
    ], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();
    $approver = User::create(['full_name' => 'Carrier Desk', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $f['policy'] = app(PolicyIssuanceService::class)->approve($request, ['carrier_reference' => 'CR-87'], $approver);

    return $f;
}

it('REQ-POL-009 builds a hashed portability export pack (chronology, parties, risks, coverages, limits, documents) with JSON + PDF', function () {
    $f = b87IssuedPolicy();
    $t = $f['tenant'];
    $ops = b87Staff($t, '+237670087030');
    Passport::actingAs($ops);
    $url = "/api/v1/policies/{$f['policy']->id}/portability-exports";

    $this->postJson($url, ['purpose' => 'CUSTOMER_REQUEST'], b87H($t))->assertStatus(422); // consent reference required
    $e = $this->postJson($url, ['purpose' => 'CUSTOMER_REQUEST', 'consent_reference' => 'CONSENT-REQ-001', 'recipient' => 'New Broker SA'], b87H($t))->assertStatus(201)->json('data');

    $pack = $e['pack'];
    expect($pack['schema_version'])->toBe(1)->and($pack['policy']['id'])->toBe($f['policy']->id)
        ->and($pack['chronology'])->toHaveCount(1)
        ->and($pack['chronology'][0]['kind'])->toBe('ISSUANCE')
        ->and($pack['chronology'][0]['coverages'])->toHaveCount(2)
        ->and(collect($pack['chronology'][0]['parties'])->pluck('role')->all())->toContain('POLICYHOLDER')
        ->and($pack['chronology'][0]['risks'])->toHaveCount(1)
        ->and(collect($pack['chronology'][0]['limits'])->pluck('limit_type')->all())->toContain('DEDUCTIBLE');
    expect($e['pack_sha256'])->toBe(app(\App\Application\Shared\CanonicalJson::class)->hash($pack));

    $row = DB::table('policy_portability_exports')->find($e['id']);
    Storage::disk('local')->assertExists($row->pdf_path);
    expect(hash('sha256', Storage::disk('local')->get($row->pdf_path)))->toBe($row->pdf_sha256);
    $this->get("/api/v1/policy-portability-exports/{$e['id']}/pdf", b87H($t) + ['Accept' => 'application/json'])->assertOk()->assertHeader('X-Content-SHA256', $row->pdf_sha256);
    $this->getJson("/api/v1/policy-portability-exports/{$e['id']}", b87H($t))->assertOk()->assertJsonPath('data.pack_sha256', $e['pack_sha256']);
    $this->getJson($url, b87H($t))->assertOk()->assertJsonCount(1, 'data');

    expect(DB::table('outbox_messages')->where('event_name', 'policy.portability.exported')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'policy.portability.exported')->exists())->toBeTrue();
    expect(fn () => DB::transaction(fn () => DB::table('policy_portability_exports')->where('id', $e['id'])->update(['recipient' => 'x'])))->toThrow(\Illuminate\Database\QueryException::class);

    // Without the export permission: forbidden.
    Passport::actingAs(b87Staff($t, '+237670087031', ['policies.portfolio_transfer.read']));
    $this->postJson($url, ['purpose' => 'CUSTOMER_REQUEST', 'consent_reference' => 'X-1'], b87H($t))->assertStatus(403);
});
