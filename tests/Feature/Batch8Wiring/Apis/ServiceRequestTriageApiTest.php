<?php

declare(strict_types=1);

use App\Application\Policies\Endorsements\ServiceRequestIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-DUP-014 lists customer service requests for staff and converts one through the servicing route', function () {
    $f = w1Policy();
    $t = $f['tenant'];
    $pid = $f['policy']->id;
    $requestId = app(ServiceRequestIntake::class)->submit($f['policy'], 'ENDORSEMENT', 'Change my address', $f['user'], null);

    Passport::actingAs(w1Staff($t, ['policies.service.approve']));
    $rows = $this->getJson('/api/v1/policy-service-requests', w1Headers($t))->assertOk()->json('data');
    expect(collect($rows)->pluck('id')->all())->toBe([$requestId]);

    $this->postJson("/api/v1/policies/{$pid}/transactions", [
        'type' => 'ENDORSEMENT', 'effective_at' => now()->addDay()->toDateString(), 'premium_delta_minor' => 0,
        'reason_code' => 'CUSTOMER_REQUEST', 'service_request_id' => $requestId,
    ], w1Headers($t))->assertCreated();
    expect(DB::table('policy_transactions')->where('id', $requestId)->value('status'))->toBe('CONVERTED')
        ->and($this->getJson('/api/v1/policy-service-requests', w1Headers($t))->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/policy-service-requests?status=CONVERTED', w1Headers($t))->json('data.0.id'))->toBe($requestId);
});

it('REQ-DUP-014 staff service-request list needs permission and is tenant-scoped', function () {
    $f = w1Policy();
    $t = $f['tenant'];
    app(ServiceRequestIntake::class)->submit($f['policy'], 'DOCUMENT_REISSUE', 'Lost card', $f['user'], null);

    Passport::actingAs(w1Staff($t, ['policies.read']));
    $this->getJson('/api/v1/policy-service-requests', w1Headers($t))->assertStatus(403);

    $other = w1Policy();
    Passport::actingAs(w1Staff($other['tenant'], ['policies.service.approve']));
    expect($this->getJson('/api/v1/policy-service-requests', w1Headers($other['tenant']))->assertOk()->json('data'))->toBe([]);
});
