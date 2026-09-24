<?php

declare(strict_types=1);

use App\Models\PartnerPayoutRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

// POST /mobile/agent/withdrawals (MobileAgentPortalController::requestWithdrawal)
// takes {provider, amount_minor, destination_phone} and is gated by a
// COMMISSION_WITHDRAWAL step-up grant (X-Step-Up-Grant) obtained from
// /mobile/security/step-up/verify — the same mechanism as payment refunds
// and claim settlement decisions. Grants are single-use, so each request
// below mints its own.

function agentWithdrawalPayload(array $overrides = []): array
{
    return array_merge(['provider' => 'mtn_momo', 'amount_minor' => 20000, 'destination_phone' => '+237670000099'], $overrides);
}

function withdrawalHeaders(array $fixture): array
{
    return agentHeaders($fixture) + stepUpHeaderFor(issueMobileStepUpGrant($fixture['user'], $fixture['tenant'], 'COMMISSION_WITHDRAWAL')['token']);
}

it('requests a withdrawal against the agent\'s own latest published statement, with the destination encrypted at rest', function () {
    $fixture = makeMobileAgentFixture();
    $statement = makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 50000]);
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), withdrawalHeaders($fixture));

    $response->assertStatus(201);
    expect($response->json('data.status'))->toBe('REQUESTED')
        ->and($response->json('data.destination_phone'))->not->toContain('0000099'); // masked

    $payout = PartnerPayoutRequest::findOrFail($response->json('data.id'));
    expect($payout->partner_id)->toBe($fixture['partner']->id)
        ->and($payout->partner_statement_id)->toBe($statement->id)
        ->and($payout->amount_minor)->toBe(20000)
        ->and(\Illuminate\Support\Facades\Crypt::decryptString($payout->getRawOriginal('destination_encrypted')))->toBe('mtn_momo:+237670000099');
});

it('requires a step-up grant before any withdrawal', function () {
    $fixture = makeMobileAgentFixture();
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner']);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), agentHeaders($fixture))
        ->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    expect(PartnerPayoutRequest::count())->toBe(0);
});

it('rejects a step-up grant issued for a different purpose, and a reused grant', function () {
    $fixture = makeMobileAgentFixture();
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 90000]);
    Passport::actingAs($fixture['user']);

    $wrong = issueMobileStepUpGrant($fixture['user'], $fixture['tenant'], 'PAYMENT_REFUND_REQUEST');
    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), agentHeaders($fixture) + stepUpHeaderFor($wrong['token']))->assertStatus(401);

    $grant = issueMobileStepUpGrant($fixture['user'], $fixture['tenant'], 'COMMISSION_WITHDRAWAL');
    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), agentHeaders($fixture) + stepUpHeaderFor($grant['token']))->assertStatus(201);
    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), agentHeaders($fixture) + stepUpHeaderFor($grant['token']))->assertStatus(401);
    expect(PartnerPayoutRequest::count())->toBe(1);
});

it('refuses a withdrawal when there is no published statement yet', function () {
    $fixture = makeMobileAgentFixture();
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), withdrawalHeaders($fixture))->assertStatus(422);
    expect(PartnerPayoutRequest::count())->toBe(0);
});

it('refuses a withdrawal amount larger than the available balance', function () {
    $fixture = makeMobileAgentFixture();
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 5000]);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(['amount_minor' => 20000]), withdrawalHeaders($fixture))->assertStatus(422);
    expect(PartnerPayoutRequest::count())->toBe(0);
});

it('enforces one in-flight withdrawal at a time (velocity check)', function () {
    $fixture = makeMobileAgentFixture();
    $statement = makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 90000]);
    makeMobileAgentPayoutRequest($fixture['tenant'], $fixture['partner'], $statement, ['status' => 'REQUESTED', 'requested_by' => $fixture['user']->id]);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), withdrawalHeaders($fixture))->assertStatus(422);
    expect(PartnerPayoutRequest::count())->toBe(1);
});

it('blocks a suspended agent from requesting a withdrawal but still lets them read their history', function () {
    $fixture = makeMobileAgentFixture('+237680000020', ['status' => 'SUSPENDED']);
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 90000]);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), withdrawalHeaders($fixture))->assertStatus(422);
    $this->getJson('/api/v1/mobile/agent/withdrawals', tenantHeaderFor($fixture['tenant']))->assertStatus(200);
});

it('scopes withdrawal and commission listings to the caller\'s own agent partner', function () {
    $agentOne = makeMobileAgentFixture('+237680000021');
    $agentTwo = makeMobileAgentFixtureInTenant($agentOne['tenant'], '+237680000022');

    $statementOne = makeMobileAgentStatement($agentOne['tenant'], $agentOne['partner']);
    $mine = makeMobileAgentPayoutRequest($agentOne['tenant'], $agentOne['partner'], $statementOne);
    $statementTwo = makeMobileAgentStatement($agentTwo['tenant'], $agentTwo['partner']);
    makeMobileAgentPayoutRequest($agentTwo['tenant'], $agentTwo['partner'], $statementTwo);

    $chain = makeMobileFinanceProposalChain($agentOne['tenant']);
    $policy = makeMobileTestPolicy($chain['proposal'], $agentOne['tenant'], $chain['carrier']->id, $chain['party']->id);
    $myAccrual = makeMobileTestCommissionAccrual($agentOne['tenant'], $agentOne['partner'], $policy);
    makeMobileTestCommissionAccrual($agentOne['tenant'], $agentTwo['partner'], $policy);

    Passport::actingAs($agentOne['user']);
    $withdrawals = $this->getJson('/api/v1/mobile/agent/withdrawals', tenantHeaderFor($agentOne['tenant']));
    $withdrawals->assertStatus(200);
    expect($withdrawals->json('data'))->toHaveCount(1)
        ->and($withdrawals->json('data.0.id'))->toBe($mine->id);

    $commissions = $this->getJson('/api/v1/mobile/agent/commissions', tenantHeaderFor($agentOne['tenant']));
    $commissions->assertStatus(200);
    expect($commissions->json('data'))->toHaveCount(1)
        ->and($commissions->json('data.0.id'))->toBe($myAccrual->id);
});

it('requires an Idempotency-Key header on a withdrawal request', function () {
    $fixture = makeMobileAgentFixture();
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner']);
    Passport::actingAs($fixture['user']);
    $grant = issueMobileStepUpGrant($fixture['user'], $fixture['tenant'], 'COMMISSION_WITHDRAWAL');

    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), tenantHeaderFor($fixture['tenant']) + stepUpHeaderFor($grant['token']))->assertStatus(422);
    expect(PartnerPayoutRequest::count())->toBe(0);
});

it('403s a withdrawal request without the agent.withdrawals.request permission', function () {
    $fixture = makeMobileAgentFixture();
    $fixture['role']->update(['permissions' => ['agent.withdrawals.read']]);
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner']);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), withdrawalHeaders($fixture))->assertStatus(403);
});
