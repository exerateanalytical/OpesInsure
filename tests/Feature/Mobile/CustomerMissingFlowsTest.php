<?php

declare(strict_types=1);

use App\Application\Documents\Signatures\SignatureService;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\Notifications\LaunchNotificationRouter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Document;
use App\Models\PaymentIntentRecord;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/** A customer policy with a two-line instalment schedule, each line carrying its INSTALMENT obligation. */
function cmfPolicyWithInstalments(string $phone): array
{
    $f = makeMobileCustomerFixture($phone);
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'POL-CMF-'.Str::random(6), 'currency' => 'XAF']);
    app(TenantContext::class)->set($f['tenant']->id);
    $rows = [];
    foreach ([1 => [now()->subDays(3), 'OVERDUE'], 2 => [now()->addMonth(), 'DUE']] as $seq => [$due, $status]) {
        $o = app(ObligationService::class)->create([
            'tenant_id' => $f['tenant']->id, 'kind' => 'RECEIVABLE', 'currency' => 'XAF', 'policy_id' => $policy->id, 'debtor_type' => 'party', 'debtor_id' => $f['party']->id,
            'type' => 'INSTALMENT', 'source_type' => 'policy', 'source_id' => $policy->id, 'source_reference' => 'INSTALMENT:'.$seq, 'amount_minor' => 50000, 'due_at' => $due,
            'description' => 'Instalment '.$seq,
        ]);
        $id = (string) Str::uuid();
        DB::table('policy_premium_instalments')->insert(['id' => $id, 'tenant_id' => $f['tenant']->id, 'policy_id' => $policy->id, 'sequence' => $seq, 'due_date' => $due->toDateString(),
            'amount_minor' => 50000, 'currency' => 'XAF', 'status' => $status, 'financial_obligation_id' => $o->id, 'created_at' => now(), 'updated_at' => now()]);
        $rows[$seq] = ['id' => $id, 'obligation' => $o->id];
    }

    return $f + ['policy' => $policy, 'instalments' => $rows];
}

/** A claim whose ACCEPTED settlement has a discharge out for the customer's signature. */
function cmfAcceptedSettlement(string $phone): array
{
    $f = makeMobileCustomerFixture($phone);
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party'], ['status' => 'APPROVED', 'approved_amount_minor' => 300000]);
    app(TenantContext::class)->set($f['tenant']->id);
    [$maker, $checker] = [User::factory()->create(), User::factory()->create()];
    $decision = (string) Str::uuid();
    DB::table('claim_decisions')->insert(['id' => $decision, 'claim_id' => $claim->id, 'decision' => 'APPROVE', 'approved_amount_minor' => 300000, 'currency' => 'XAF',
        'reason_code' => 'OK', 'rationale' => 'Covered.', 'status' => 'APPROVED', 'proposed_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $doc = Document::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'policy_id' => $policy->id, 'claim_id' => $claim->id, 'category' => 'ENGINE_DISCHARGE',
        'storage_key' => 'documents/test/discharge.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => hash('sha256', 'discharge'), 'scan_status' => 'CLEAN',
        'document_type_code' => 'DISCHARGE', 'status' => 'PENDING_SIGNATURE', 'title' => 'Discharge receipt']);
    $req = app(SignatureService::class)->request($f['tenant']->id, ['document_id' => $doc->id, 'consent_text' => 'I accept 280000 XAF in full and final settlement.',
        'signers' => [['party_id' => $f['party']->id, 'name' => 'Customer', 'role' => 'PAYEE']]], $checker);
    $settlement = (string) Str::uuid();
    DB::table('claim_settlements')->insert(['id' => $settlement, 'tenant_id' => $f['tenant']->id, 'claim_id' => $claim->id, 'claim_decision_id' => $decision, 'payee_party_id' => $f['party']->id,
        'reference' => 'STL-'.strtoupper(Str::random(12)), 'status' => 'ACCEPTED', 'currency' => 'XAF', 'covered_minor' => 300000, 'deductible_minor' => 20000, 'gross_minor' => 280000, 'amount_minor' => 280000,
        'breakdown' => json_encode(['lines' => []]), 'calculated_by' => $maker->id, 'offered_by' => $checker->id, 'offered_at' => now(), 'accepted_at' => now(),
        'discharge_document_id' => $doc->id, 'signature_request_id' => $req['id'], 'created_at' => now(), 'updated_at' => now()]);

    return $f + ['claim' => $claim, 'settlement' => $settlement, 'request' => $req['id'], 'document' => $doc->id];
}

it('lists the own policy instalment schedule and 404s for another customer', function () {
    $w = cmfPolicyWithInstalments('+237671200001');
    Passport::actingAs($w['user']);

    $res = $this->getJson("/api/v1/mobile/policies/{$w['policy']->id}/instalments", tenantHeaderFor($w['tenant']))->assertOk();
    expect($res->json('data'))->toHaveCount(2)
        ->and($res->json('data.0'))->toMatchArray(['number' => 1, 'amount_minor' => 50000, 'outstanding_minor' => 50000, 'status' => 'OVERDUE', 'overdue' => true, 'payable' => true, 'paid_at' => null])
        ->and($res->json('meta.outstanding_minor'))->toBe(100000);

    $other = makeMobileCustomerFixture('+237671200002');
    DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $w['tenant']->id, 'user_id' => $other['user']->id, 'role_code' => 'CUSTOMER', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    Passport::actingAs($other['user']);
    $status = $this->getJson("/api/v1/mobile/policies/{$w['policy']->id}/instalments", tenantHeaderFor($w['tenant']))->status();
    expect($status)->toBeIn([403, 404]);
});

it('pays one instalment through a payment intent keyed to its obligation and never charges twice', function () {
    $w = cmfPolicyWithInstalments('+237671200003');
    Passport::actingAs($w['user']);
    $inst = $w['instalments'][1];
    $url = "/api/v1/mobile/policies/{$w['policy']->id}/instalments/{$inst['id']}/pay";
    $body = ['provider' => 'fake', 'payer_phone_e164' => '+237671200003'];
    $key = (string) Str::uuid();

    $this->postJson($url, $body, tenantHeaderFor($w['tenant']))->assertStatus(422);
    $first = $this->postJson($url, $body, tenantHeaderFor($w['tenant']) + ['Idempotency-Key' => $key])->assertCreated();
    $intent = PaymentIntentRecord::findOrFail($first->json('data.id'));
    expect($intent->financial_obligation_id)->toBe($inst['obligation'])
        ->and($intent->proposal_id)->toBe($w['proposal']->id)
        ->and((int) $intent->amount_minor)->toBe(50000);

    // Same key → same intent; a new key while it is with the operator → 409 PAYMENT_IN_PROGRESS.
    $this->postJson($url, $body, tenantHeaderFor($w['tenant']) + ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.id', $intent->id);
    $intent->update(['status' => 'PROCESSING']);
    $this->postJson($url, $body, tenantHeaderFor($w['tenant']) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'PAYMENT_IN_PROGRESS');
    $intent->update(['status' => 'SUCCEEDED']);
    $this->postJson($url, $body, tenantHeaderFor($w['tenant']) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'PAYMENT_ALREADY_MADE');
    DB::table('policy_premium_instalments')->where('id', $w['instalments'][2]['id'])->update(['status' => 'PAID']);
    $this->postJson("/api/v1/mobile/policies/{$w['policy']->id}/instalments/{$w['instalments'][2]['id']}/pay", $body, tenantHeaderFor($w['tenant']) + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(409)->assertJsonPath('code', 'PAYMENT_ALREADY_MADE');

    $list = $this->getJson("/api/v1/mobile/policies/{$w['policy']->id}/instalments", tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect($list[0]['payable'])->toBeFalse()->and($list[0]['payment_id'])->toBe($intent->id);
});

it('shows the refunds of an own payment and resolves a refund to its payment', function () {
    $f = makeMobileCustomerFixture('+237671200004');
    app(TenantContext::class)->set($f['tenant']->id);
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant']);
    $refund = (string) Str::uuid();
    DB::table('refunds')->insert(['id' => $refund, 'tenant_id' => $f['tenant']->id, 'payment_intent_id' => $payment->id, 'refund_number' => 'RF-CMF-1', 'amount_minor' => 20000,
        'currency' => 'XAF', 'status' => 'APPROVED', 'reason_code' => 'CANCELLATION', 'requested_by' => $f['user']->id, 'created_at' => now(), 'updated_at' => now()]);
    Passport::actingAs($f['user']);

    $this->getJson("/api/v1/mobile/payments/{$payment->id}/refunds", tenantHeaderFor($f['tenant']))->assertOk()
        ->assertJsonPath('data.0.refund_number', 'RF-CMF-1')->assertJsonPath('data.0.status', 'APPROVED')->assertJsonPath('data.0.amount_minor', 20000);
    $this->getJson("/api/v1/mobile/refunds/{$refund}", tenantHeaderFor($f['tenant']))->assertOk()->assertJsonPath('data.payment_id', $payment->id);
    $this->getJson('/api/v1/mobile/refunds/'.Str::uuid(), tenantHeaderFor($f['tenant']))->assertNotFound();
});

it('exposes the discharge to its signer and signing moves the settlement to DISCHARGE_SIGNED', function () {
    $w = cmfAcceptedSettlement('+237671200005');
    Passport::actingAs($w['user']);
    $h = tenantHeaderFor($w['tenant']);

    $view = $this->getJson("/api/v1/mobile/claims/{$w['claim']->id}/settlement", $h)->assertOk();
    expect($view->json('data.allowed_actions'))->toContain('sign_discharge')
        ->and($view->json('data.discharge'))->toMatchArray(['signature_request_id' => $w['request'], 'document_id' => $w['document'], 'status' => 'PENDING', 'signer_status' => 'PENDING'])
        ->and($view->json('data.discharge.consent_text'))->toContain('full and final settlement');

    // Step-up is required, like the settlement decision.
    $this->postJson("/api/v1/mobile/claims/{$w['claim']->id}/settlement/discharge/sign", ['consent_accepted' => true], $h)->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    // A grant is single use: each attempt needs its own.
    $grant = issueMobileStepUpGrant($w['user'], $w['tenant'], 'CLAIM_SETTLEMENT_DECISION');
    $this->postJson("/api/v1/mobile/claims/{$w['claim']->id}/settlement/discharge/sign", ['consent_accepted' => false], $h + stepUpHeaderFor($grant['token']))->assertStatus(422);
    $grant = issueMobileStepUpGrant($w['user'], $w['tenant'], 'CLAIM_SETTLEMENT_DECISION');
    $signed = $this->postJson("/api/v1/mobile/claims/{$w['claim']->id}/settlement/discharge/sign", ['consent_accepted' => true], $h + stepUpHeaderFor($grant['token']))->assertOk();

    expect($signed->json('data.status'))->toBe('DISCHARGE_SIGNED')
        ->and($signed->json('data.discharge.status'))->toBe('COMPLETED')
        ->and($signed->json('data.allowed_actions'))->not->toContain('sign_discharge')
        ->and(DB::table('claim_settlements')->where('id', $w['settlement'])->value('status'))->toBe('DISCHARGE_SIGNED');
});

it('lets the signer decline the discharge with a reason and rejects strangers', function () {
    $w = cmfAcceptedSettlement('+237671200006');
    Passport::actingAs($w['user']);
    $h = tenantHeaderFor($w['tenant']);

    $this->postJson("/api/v1/mobile/claims/{$w['claim']->id}/settlement/discharge/decline", ['reason' => 'no'], $h)->assertStatus(422);
    $this->postJson("/api/v1/mobile/claims/{$w['claim']->id}/settlement/discharge/decline", ['reason' => 'The amount is not what was agreed.'], $h)->assertOk()
        ->assertJsonPath('data.discharge.status', 'DECLINED')->assertJsonPath('data.status', 'ACCEPTED');

    $other = makeMobileCustomerFixture('+237671200007');
    DB::table('tenant_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $w['tenant']->id, 'user_id' => $other['user']->id, 'role_code' => 'CUSTOMER', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    Passport::actingAs($other['user']);
    expect($this->postJson("/api/v1/mobile/claims/{$w['claim']->id}/settlement/discharge/decline", ['reason' => 'Not my claim at all.'], $h)->status())->toBeIn([403, 404]);
});

it('stores a masked payout destination with step-up and a security alert, locked once payment is requested', function () {
    $w = cmfAcceptedSettlement('+237671200008');
    Passport::actingAs($w['user']);
    $h = tenantHeaderFor($w['tenant']);
    $url = "/api/v1/mobile/claims/{$w['claim']->id}/settlement/payout";
    $body = ['method' => 'MOBILE_MONEY', 'operator' => 'MTN', 'msisdn' => '+237671234567'];

    // PAYOUT_DESTINATION_CHANGE is on the step-up rollout hold (config mobile_runtime.step_up.not_enforced_yet):
    // without a grant header the write passes; a presented grant is still checked.
    $this->putJson($url, $body, $h + stepUpHeaderFor('not-a-real-grant'))->assertStatus(401);
    $this->putJson($url, ['method' => 'MOBILE_MONEY', 'operator' => 'MTN'], $h)->assertStatus(422);
    $grant = issueMobileStepUpGrant($w['user'], $w['tenant'], 'PAYOUT_DESTINATION_CHANGE');
    $res = $this->putJson($url, $body, $h + stepUpHeaderFor($grant['token']))->assertOk();
    $hs = $h;
    expect($res->json('data.payout'))->toMatchArray(['method' => 'MOBILE_MONEY', 'operator' => 'MTN'])
        ->and($res->json('data.payout.msisdn_masked'))->toEndWith('4567')->not->toContain('67123')
        ->and($w['claim']->refresh()->loss_details['payout']['msisdn'])->toBe('+237671234567')
        ->and(UserNotification::where('user_id', $w['user']->id)->where('type', 'SECURITY')->exists())->toBeTrue();

    DB::table('claim_settlements')->where('id', $w['settlement'])->update(['status' => 'PAYMENT_PENDING']);
    $this->putJson($url, ['method' => 'BANK_TRANSFER', 'bank_name' => 'Afriland', 'account_name' => 'Test', 'account_number' => 'CM2100010001234567'], $hs)->assertStatus(422);
});

it('deep-links cancellation notices to the policy', function () {
    $src = file_get_contents(app_path('Application/Policies/Cancellation/CancellationService.php'));
    expect(substr_count($src, 'path: "/policy/{$policy->id}"'))->toBe(2);
});

it('routes complaint notifications to /complaints/{id}', function () {
    $f = makeMobileCustomerFixture('+237671200009');
    $id = (string) Str::uuid();
    app(LaunchNotificationRouter::class)->complaintStatus((object) ['id' => $id, 'tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'complaint_number' => 'CPL-CMF-1'], 'ACKNOWLEDGED');
    expect(UserNotification::where('user_id', $f['user']->id)->where('type', 'COMPLAINT')->value('path'))->toBe("/complaints/{$id}");
});
