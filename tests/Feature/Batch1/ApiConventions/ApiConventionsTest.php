<?php

declare(strict_types=1);

use App\Interfaces\Http\Errors\ApiProblemException;
use App\Interfaces\Http\Middleware\AssignCorrelationId;
use App\Models\IdempotencyKey;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

// REQ-API-002 REQ-API-003 REQ-API-008 REQ-IDM-001 REQ-NFR-001
beforeEach(function () {
    $GLOBALS['b1d_calls'] = 0;

    Route::middleware(['api', 'auth:api'])->prefix('api/v1/_b1d')->group(function () {
        Route::post('counter', function (\Illuminate\Http\Request $r) {
            $GLOBALS['b1d_calls']++;

            return response()->json(['data' => ['n' => $GLOBALS['b1d_calls'], 'echo' => $r->input('v')]], 201);
        });
        Route::post('boom', function () {
            $GLOBALS['b1d_calls']++;
            abort(500);
        });
        Route::post('problem', fn () => throw ApiProblemException::paymentOkIssuanceFailed(null, ['payment_id' => 'p-1']));
        Route::post('authority', fn () => throw ApiProblemException::authorityExceeded());
        Route::post('validate', fn (\Illuminate\Http\Request $r) => $r->validate(['name' => 'required']));
        Route::get('tenants/{tenant}', fn (Tenant $tenant) => ['data' => ['id' => $tenant->id]]);
        Route::put('tenants/{tenant}', function (Tenant $tenant) {
            $GLOBALS['b1d_calls']++;
            $tenant->touch();

            return ['data' => ['id' => $tenant->id]];
        });
        Route::put('strict/{tenant}', fn (Tenant $tenant) => ['ok' => true])->middleware('if-match:required');
        Route::get('paged', fn () => \App\Models\Tenant::query()->paginate(1));
    });
});

it('REQ-API-003: validation errors keep message+errors and add code, RFC 9457 members and correlation id', function () {
    $f = makeMobileCustomerFixture();
    Passport::actingAs($f['user']);

    $r = $this->postJson('/api/v1/_b1d/validate', [], ['X-Correlation-ID' => 'corr-abc-12345']);

    $r->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('status', 422)
        ->assertJsonPath('title', 'Unprocessable Content')
        ->assertJsonPath('correlation_id', 'corr-abc-12345')
        ->assertJsonPath('type', 'urn:opesinsure:problem:validation-failed')
        ->assertHeader('X-Correlation-ID', 'corr-abc-12345');
    // Backward compatibility with mobile toError(): message + errors map unchanged.
    expect($r->json('message'))->toBeString()->and($r->json('detail'))->toBe($r->json('message'));
    expect($r->json('errors.name'))->toBeArray()->and($r->json('field_errors.name'))->toBe($r->json('errors.name'));
});

it('REQ-API-003: unauthenticated, unknown route and domain problems carry machine codes', function () {
    $this->postJson('/api/v1/_b1d/validate')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED')->assertJsonStructure(['message', 'correlation_id']);
    $this->getJson('/api/v1/_b1d/does-not-exist')->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');

    $f = makeMobileCustomerFixture();
    Passport::actingAs($f['user']);
    $this->postJson('/api/v1/_b1d/problem')->assertStatus(502)
        ->assertJsonPath('code', 'PAYMENT_OK_ISSUANCE_FAILED')->assertJsonPath('payment_id', 'p-1')->assertJsonStructure(['message', 'detail']);
    $this->postJson('/api/v1/_b1d/authority')->assertStatus(403)->assertJsonPath('code', 'AUTHORITY_EXCEEDED');
});

it('REQ-API-003: an explicit code from existing middleware is preserved (STEP_UP_REQUIRED contract)', function () {
    $r = ApiProblemException::duplicateSubmission();
    $response = \App\Interfaces\Http\Errors\ProblemDetails::decorate(response()->json(['message' => 'x', 'code' => 'STEP_UP_REQUIRED'], 401));
    expect($response->getData(true)['code'])->toBe('STEP_UP_REQUIRED')->and($r->errorCode)->toBe('DUPLICATE_SUBMISSION');
});

it('REQ-NFR-001: mints a correlation id when absent, rejects unsafe ones, and feeds it to the audit trail', function () {
    $f = makeMobileCustomerFixture();
    Passport::actingAs($f['user']);

    $r = $this->postJson('/api/v1/_b1d/counter', ['v' => 1]);
    expect($r->headers->get('X-Correlation-ID'))->toMatch('/^[0-9a-f-]{36}$/');

    $r2 = $this->postJson('/api/v1/_b1d/counter', ['v' => 1], ['X-Correlation-ID' => "bad id\n<script>"]);
    expect($r2->headers->get('X-Correlation-ID'))->toMatch('/^[0-9a-f-]{36}$/');

    $this->postJson('/api/v1/_b1d/counter', [], ['X-Request-Id' => 'legacy-request-id-1'])->assertHeader('X-Correlation-ID', 'legacy-request-id-1');
    expect(AssignCorrelationId::current())->toBe('legacy-request-id-1');
    expect(request()->header('X-Request-Id'))->toBe('legacy-request-id-1'); // AuditWriter source
});

it('REQ-IDM-001: any /api/v1 mutation sending Idempotency-Key is de-duplicated without route opt-in', function () {
    $f = makeMobileCustomerFixture();
    Passport::actingAs($f['user']);
    $h = ['Idempotency-Key' => 'b1d-key-1'];

    $a = $this->postJson('/api/v1/_b1d/counter', ['v' => 7], $h)->assertStatus(201);
    $b = $this->postJson('/api/v1/_b1d/counter', ['v' => 7], $h)->assertStatus(201)->assertHeader('X-Idempotent-Replay', 'true');

    expect($GLOBALS['b1d_calls'])->toBe(1)->and($b->json('data'))->toBe($a->json('data'));
    expect(IdempotencyKey::first()->user_id)->toBe($f['user']->id); // auth resolved before the guard

    // Lenient auto-coverage: same key + different body = a new request (backward compatible).
    $this->postJson('/api/v1/_b1d/counter', ['v' => 8], $h)->assertStatus(201)->assertHeaderMissing('X-Idempotent-Replay');
    expect($GLOBALS['b1d_calls'])->toBe(2);
});

it('REQ-IDM-001: mutations without the header are unchanged (mobile compatibility)', function () {
    $f = makeMobileCustomerFixture();
    Passport::actingAs($f['user']);

    $this->postJson('/api/v1/_b1d/counter', ['v' => 1])->assertStatus(201);
    $this->postJson('/api/v1/_b1d/counter', ['v' => 1])->assertStatus(201);
    expect($GLOBALS['b1d_calls'])->toBe(2)->and(IdempotencyKey::count())->toBe(0);
});

it('REQ-IDM-001: a 5xx is not cached, so the same-key retry re-executes', function () {
    $f = makeMobileCustomerFixture();
    Passport::actingAs($f['user']);
    $h = ['Idempotency-Key' => 'b1d-boom'];

    $this->postJson('/api/v1/_b1d/boom', [], $h)->assertStatus(500)->assertJsonPath('code', 'SERVER_ERROR');
    $this->postJson('/api/v1/_b1d/boom', [], $h)->assertStatus(500);
    expect($GLOBALS['b1d_calls'])->toBe(2)->and(IdempotencyKey::count())->toBe(0);
});

it('REQ-API-002: GET exposes an ETag; a stale If-Match is rejected 412 STALE_RECORD without running the handler', function () {
    $f = makeMobileCustomerFixture();
    Passport::actingAs($f['user']);
    $tenant = $f['tenant'];

    $etag = $this->getJson("/api/v1/_b1d/tenants/{$tenant->id}")->assertOk()->headers->get('ETag');
    expect($etag)->not->toBeNull();

    $this->putJson("/api/v1/_b1d/tenants/{$tenant->id}", [], ['If-Match' => $etag])->assertOk()->assertHeader('ETag');
    expect($GLOBALS['b1d_calls'])->toBe(1);

    DB::table('tenants')->where('id', $tenant->id)->update(['updated_at' => now()->addMinutes(5)]);

    $r = $this->putJson("/api/v1/_b1d/tenants/{$tenant->id}", [], ['If-Match' => $etag]);
    $r->assertStatus(412)->assertJsonPath('code', 'STALE_RECORD')->assertJsonStructure(['message', 'errors' => ['version', 'current_version'], 'correlation_id']);
    expect($GLOBALS['b1d_calls'])->toBe(1);

    // No If-Match -> unchanged last-write-wins behaviour.
    $this->putJson("/api/v1/_b1d/tenants/{$tenant->id}")->assertOk();
    // Opt-in strict routes demand the header.
    $this->putJson("/api/v1/_b1d/strict/{$tenant->id}")->assertStatus(428)->assertJsonPath('code', 'PRECONDITION_REQUIRED');
});

it('REQ-API-003: the existing body-version 409 (EnforcesOptimisticConcurrency) is labelled STALE_RECORD', function () {
    $f = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $delivery = makeMobileTestDelivery($policy, $f['tenant'], ['status' => 'READY_FOR_PICKUP']);
    $stale = $delivery->updated_at->toIso8601String();
    DB::table('fulfilment_orders')->where('id', $delivery->id)->update(['updated_at' => now()->addMinute()]);
    Passport::actingAs($f['user']);

    $this->putJson("/api/v1/mobile/deliveries/{$delivery->id}/address", ['address' => ['city' => 'Bafoussam'], 'version' => $stale], tenantHeaderFor($f['tenant']))
        ->assertStatus(409)->assertJsonPath('code', 'STALE_RECORD')->assertJsonStructure(['errors' => ['version', 'current_version']]);
});

it('REQ-API-008: paginated lists gain a normalized pagination member and X-Total-Count', function () {
    $f = makeMobileCustomerFixture();
    Passport::actingAs($f['user']);

    $r = $this->getJson('/api/v1/_b1d/paged')->assertOk();
    expect($r->json('data'))->toBeArray()->and($r->json('current_page'))->toBe(1);
    expect($r->json('pagination'))->toMatchArray(['page' => 1, 'per_page' => 1]);
    expect($r->headers->get('X-Total-Count'))->toBe((string) Tenant::count());
});
