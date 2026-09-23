<?php

declare(strict_types=1);

use App\Models\PartnerPayoutRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

const AGENT_MFA_SECRET = 'JBSWY3DPEHPK3PXP';

function agentWithdrawalPayload(array $overrides = []): array
{
    return array_merge([
        'amount_minor' => 20000,
        'destination_type' => 'MOBILE_MONEY',
        'destination' => '+237670000099',
        'step_up_code' => totpCodeFor(AGENT_MFA_SECRET),
    ], $overrides);
}

it('requests a withdrawal against the agent\'s own latest published statement, with the destination encrypted at rest', function () {
    $fixture = makeMobileAgentFixture();
    makeMobileAgentMfaMethod($fixture['user'], AGENT_MFA_SECRET);
    $statement = makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 50000]);
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), agentHeaders($fixture));

    $response->assertStatus(201);
    expect($response->json('data.status'))->toBe('REQUESTED');
    expect($response->json('data.destination_encrypted'))->toBeNull(); // hidden on the model

    $payout = PartnerPayoutRequest::findOrFail($response->json('data.id'));
    expect($payout->partner_id)->toBe($fixture['partner']->id)
        ->and($payout->partner_statement_id)->toBe($statement->id)
        ->and($payout->amount_minor)->toBe(20000)
        ->and(\Illuminate\Support\Facades\Crypt::decryptString($payout->getRawOriginal('destination_encrypted')))->toBe('+237670000099');
});

it('requires an enrolled and verified TOTP method before any withdrawal', function () {
    $fixture = makeMobileAgentFixture();
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner']);
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), agentHeaders($fixture));

    $response->assertStatus(422);
    expect($response->json('errors.step_up_code'))->not->toBeNull();
    expect(PartnerPayoutRequest::count())->toBe(0);
});

it('rejects an incorrect step-up code', function () {
    $fixture = makeMobileAgentFixture();
    makeMobileAgentMfaMethod($fixture['user'], AGENT_MFA_SECRET);
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner']);
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(['step_up_code' => '000000']), agentHeaders($fixture));

    $response->assertStatus(422);
    expect(PartnerPayoutRequest::count())->toBe(0);
});

it('refuses a withdrawal when there is no published statement with a balance yet', function () {
    $fixture = makeMobileAgentFixture();
    makeMobileAgentMfaMethod($fixture['user'], AGENT_MFA_SECRET);
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), agentHeaders($fixture));

    $response->assertStatus(422);
    expect($response->json('errors.withdrawal'))->not->toBeNull();
});

it('refuses a withdrawal amount larger than the published closing balance', function () {
    $fixture = makeMobileAgentFixture();
    makeMobileAgentMfaMethod($fixture['user'], AGENT_MFA_SECRET);
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 5000]);
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(['amount_minor' => 20000]), agentHeaders($fixture));

    $response->assertStatus(422);
    expect(PartnerPayoutRequest::count())->toBe(0);
});

it('enforces one in-flight withdrawal at a time (velocity check)', function () {
    $fixture = makeMobileAgentFixture();
    makeMobileAgentMfaMethod($fixture['user'], AGENT_MFA_SECRET);
    $statement = makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 90000]);
    makeMobileAgentPayoutRequest($fixture['tenant'], $fixture['partner'], $statement, ['status' => 'REQUESTED', 'requested_by' => $fixture['user']->id]);
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), agentHeaders($fixture));

    $response->assertStatus(422);
    expect($response->json('errors.withdrawal'))->not->toBeNull();
    expect(PartnerPayoutRequest::count())->toBe(1);
});

it('blocks a suspended agent from requesting a withdrawal but still lets them read their history', function () {
    $fixture = makeMobileAgentFixture('+237680000020', ['status' => 'SUSPENDED']);
    makeMobileAgentMfaMethod($fixture['user'], AGENT_MFA_SECRET);
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner'], ['closing_balance_minor' => 90000]);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), agentHeaders($fixture))->assertStatus(422);
    $this->getJson('/api/v1/mobile/agent/withdrawals', tenantHeaderFor($fixture['tenant']))->assertStatus(200);
});

it('scopes withdrawal and commission listings to the caller\'s own agent partner', function () {
    $agentOne = makeMobileAgentFixture('+237680000021');
    $agentTwo = makeMobileAgentFixtureInTenant($agentOne['tenant'], '+237680000022');

    $statementOne = makeMobileAgentStatement($agentOne['tenant'], $agentOne['partner']);
    makeMobileAgentPayoutRequest($agentOne['tenant'], $agentOne['partner'], $statementOne);
    makeMobileAgentStatement($agentTwo['tenant'], $agentTwo['partner']);

    Passport::actingAs($agentOne['user']);
    $withdrawals = $this->getJson('/api/v1/mobile/agent/withdrawals', tenantHeaderFor($agentOne['tenant']));
    $withdrawals->assertStatus(200);
    expect($withdrawals->json('data.data'))->toHaveCount(1);

    $commissions = $this->getJson('/api/v1/mobile/agent/commissions', tenantHeaderFor($agentOne['tenant']));
    $commissions->assertStatus(200);
    expect($commissions->json('data.data'))->toHaveCount(1);
    expect($commissions->json('data.data.0.id'))->toBe($statementOne->id);
});

it('requires an Idempotency-Key header on a withdrawal request', function () {
    $fixture = makeMobileAgentFixture();
    makeMobileAgentMfaMethod($fixture['user'], AGENT_MFA_SECRET);
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner']);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('403s a withdrawal request without the agent.withdrawals.request permission', function () {
    $fixture = makeMobileAgentFixture();
    $fixture['role']->update(['permissions' => ['agent.withdrawals.read']]);
    makeMobileAgentMfaMethod($fixture['user'], AGENT_MFA_SECRET);
    makeMobileAgentStatement($fixture['tenant'], $fixture['partner']);
    Passport::actingAs($fixture['user']);

    $this->postJson('/api/v1/mobile/agent/withdrawals', agentWithdrawalPayload(), agentHeaders($fixture))->assertStatus(403);
});
