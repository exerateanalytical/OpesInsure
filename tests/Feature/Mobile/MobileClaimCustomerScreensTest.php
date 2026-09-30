<?php

declare(strict_types=1);

use App\Application\Claims\Settlement\MobileClaimSettlementView;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/** A customer claim with an approved decision and a settlement row in $status. */
function mcsClaimWithSettlement(string $status, string $phone): array
{
    $f = makeMobileCustomerFixture($phone);
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party'], ['status' => 'APPROVED', 'approved_amount_minor' => 300000]);
    [$maker, $checker] = [User::factory()->create(), User::factory()->create()];
    $decision = (string) Str::uuid();
    DB::table('claim_decisions')->insert(['id' => $decision, 'claim_id' => $claim->id, 'decision' => 'APPROVE', 'approved_amount_minor' => 300000, 'currency' => 'XAF',
        'reason_code' => 'OK', 'rationale' => 'Covered.', 'status' => 'APPROVED', 'proposed_by' => $maker->id, 'approved_by' => $checker->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $settlement = (string) Str::uuid();
    DB::table('claim_settlements')->insert(['id' => $settlement, 'tenant_id' => $f['tenant']->id, 'claim_id' => $claim->id, 'claim_decision_id' => $decision, 'payee_party_id' => $f['party']->id,
        'reference' => 'STL-'.strtoupper(Str::random(12)), 'status' => $status, 'currency' => 'XAF', 'covered_minor' => 300000, 'deductible_minor' => 20000, 'gross_minor' => 280000, 'amount_minor' => 280000,
        'breakdown' => json_encode(['lines' => []]), 'calculated_by' => $maker->id, 'offered_by' => $status === 'CALCULATED' ? null : $checker->id, 'offered_at' => $status === 'CALCULATED' ? null : now(),
        'created_at' => now(), 'updated_at' => now()]);
    app(TenantContext::class)->set($f['tenant']->id);

    return $f + ['claim' => $claim, 'settlement' => $settlement];
}

it('never shows the customer a settlement before it is offered', function () {
    $w = mcsClaimWithSettlement('CALCULATED', '+237671100001');
    $view = app(MobileClaimSettlementView::class);

    expect($view->present($w['claim']))->toMatchArray(['id' => null, 'status' => 'PENDING', 'net_minor' => null, 'can_decide' => false])
        ->and(MobileClaimSettlementView::hasOpenOffer($w['claim']))->toBeFalse();
    expect(fn () => $view->decide($w['claim'], 'ACCEPT', null, $w['user']))->toThrow(ValidationException::class);
    expect(DB::table('claim_settlements')->where('id', $w['settlement'])->value('status'))->toBe('CALCULATED');
});

it('rejecting an offer disputes the settlement through ClaimSettlementService', function () {
    $w = mcsClaimWithSettlement('OFFERED', '+237671100002');
    $view = app(MobileClaimSettlementView::class);

    expect($view->present($w['claim']))->toMatchArray(['status' => 'OFFERED', 'offered_minor' => 300000, 'deductible_minor' => 20000, 'net_minor' => 280000, 'can_decide' => true, 'terms' => 'Covered.']);

    $view->decide($w['claim'], 'REJECT', 'The amount does not cover the repair.', $w['user']);

    $row = DB::table('claim_settlements')->where('id', $w['settlement'])->first();
    expect($row->status)->toBe('DISPUTED')
        ->and($row->dispute_reason)->toBe('The amount does not cover the repair.')
        ->and(DB::table('claim_settlement_events')->where('claim_settlement_id', $w['settlement'])->where('to_status', 'DISPUTED')->exists())->toBeTrue()
        ->and($view->present($w['claim'])['can_decide'])->toBeFalse();
});

it('reports an unscheduled inspection without inventing an appointment', function () {
    $f = makeMobileCustomerFixture('+237671100003');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party'], ['status' => 'ASSESSMENT']);
    Passport::actingAs($f['user']);

    $this->getJson("/api/v1/mobile/claims/{$claim->id}/inspection", tenantHeaderFor($f['tenant']))->assertOk()
        ->assertJsonPath('data.status', 'NOT_SCHEDULED')
        ->assertJsonPath('data.appointment_at', null)
        ->assertJsonPath('data.location', null);
});

it('adds a witness with consented contact details and lists parties as a page', function () {
    $f = makeMobileCustomerFixture('+237671100004');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party']);
    Passport::actingAs($f['user']);
    $url = "/api/v1/mobile/claims/{$claim->id}/parties";

    // The body the app sends (display_name / contact_phone / consent_given).
    $this->postJson($url, ['role' => 'WITNESS', 'display_name' => 'Paul Mbarga', 'contact_phone' => '+237670001122', 'consent_given' => false], tenantHeaderFor($f['tenant']))->assertStatus(422);
    $this->postJson($url, ['role' => 'WITNESS', 'display_name' => 'Paul Mbarga', 'contact_phone' => '+237670001122', 'consent_given' => true], tenantHeaderFor($f['tenant']))->assertCreated();

    $page = $this->getJson($url, tenantHeaderFor($f['tenant']))->assertOk()->json('data');
    expect($page['data'][0]['display_name'])->toBe('Paul Mbarga')
        ->and($page['data'][0]['contact_phone'])->toBe('+237670001122')
        ->and($page)->toHaveKey('current_page');
});
