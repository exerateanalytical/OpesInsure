<?php

declare(strict_types=1);

// REQ-PAY-001 payment machine on the shared framework; REQ-PAY-003 bank transfer + card (WF-024).

use App\Application\Payments\Adapters\BankTransferAdapter;
use App\Application\Payments\Adapters\HostedCardCheckoutAdapter;
use App\Application\Payments\Adapters\PaymentAdapterRegistry;
use App\Application\Payments\PaymentInitiationService;
use App\Application\Payments\WebhookProcessingService;
use App\Domain\Payments\PaymentMachine;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Shared\StateMachine\StateMachineRegistry;
use App\Models\PaymentIntentRecord;
use App\Models\PaymentProviderConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b93Payment(string $provider, array $o = []): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING']);
    PaymentProviderConnection::create(['tenant_id' => $f['tenant']->id, 'provider' => $provider, 'status' => 'ACTIVE', 'credential_reference' => 'vault://x', 'created_by' => $f['user']->id]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], array_merge(['provider' => $provider, 'provider_reference' => null, 'status' => 'CREATED', 'requested_by' => $f['user']->id], $o));

    return $f;
}

function b93SignedWebhook($test, string $provider, array $payload, string $event, ?string $secret = 'b93-secret')
{
    config(["payments.providers.$provider.webhook_secret" => 'b93-secret']);
    $body = json_encode($payload);
    $ts = time();

    return $test->call('POST', "/api/v1/webhooks/payments/$provider", [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PROVIDER_EVENT_ID' => $event,
        'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $ts, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$body, (string) $secret),
    ], $body);
}

it('defines the payment machine on the shared framework with a blueprint mapping for every stored status', function () {
    $m = app(StateMachineRegistry::class)->get(PaymentMachine::NAME);
    expect($m->initialState())->toBe('CREATED');
    foreach (PaymentStatus::cases() as $s) {
        expect($m->hasState($s->value))->toBeTrue()
            ->and(PaymentMachine::BLUEPRINT)->toHaveKey($s->value);
    }
    $blueprint = array_unique(array_values(PaymentMachine::BLUEPRINT));
    foreach (['CREATED', 'INITIATED', 'PENDING', 'SUCCESSFUL', 'FAILED', 'EXPIRED', 'CANCELLED', 'REVERSED', 'REFUNDED'] as $b) {
        expect($blueprint)->toContain($b);
    }
    expect(PaymentMachine::blueprintState('SUCCEEDED', true))->toBe('RECONCILED')
        ->and(PaymentStatus::AwaitingTransfer->blueprint())->toBe('PENDING')
        ->and(PaymentStatus::Succeeded->canBecome(PaymentStatus::Reversed))->toBeTrue()
        ->and(PaymentStatus::Expired->canBecome(PaymentStatus::Succeeded))->toBeFalse();
    // Client/staff events are never applicable by a provider callback.
    expect(PaymentMachine::providerTransition('CREATED', 'CANCELLED'))->toBeNull()
        ->and(PaymentMachine::providerTransition('FAILED', 'PENDING_CUSTOMER'))->toBeNull()
        ->and(PaymentMachine::providerTransition('AWAITING_TRANSFER', 'SUCCEEDED')?->event)->toBe('succeed');
});

it('issues a bank transfer reference and settles it only through a signed callback', function () {
    config(['payments.providers.bank_transfer' => ['bank_name' => 'Test Bank', 'account_name' => 'OpesInsure', 'account_number' => 'CM21 0001', 'swift' => 'TESTCMCX', 'validity_days' => 5]]);
    $f = b93Payment('bank_transfer');

    $p = app(PaymentInitiationService::class)->initiate($f['payment']);
    expect($p->status)->toBe('AWAITING_TRANSFER')
        ->and($p->provider_reference)->toBe(BankTransferAdapter::referenceFor($p))
        ->and(PaymentMachine::blueprintState($p->status))->toBe('PENDING');
    $attempt = $p->attempts()->first();
    expect($attempt->response_snapshot['instructions']['reference'])->toBe($p->provider_reference)
        ->and($attempt->response_snapshot['instructions']['amount_minor'])->toBe(100000);

    $payload = ['payment_reference' => $p->provider_reference, 'status' => 'SUCCEEDED', 'amount_minor' => 100000, 'currency' => 'XAF'];
    // Unsigned / wrongly-signed callbacks are rejected and change nothing.
    b93SignedWebhook($this, 'bank_transfer', $payload, 'bt-bad', 'wrong')->assertStatus(422);
    expect($p->refresh()->status)->toBe('AWAITING_TRANSFER');
    // A short transfer is not settled.
    b93SignedWebhook($this, 'bank_transfer', ['amount_minor' => 50000] + $payload, 'bt-short')->assertStatus(202);
    expect($p->refresh()->status)->toBe('AWAITING_TRANSFER');

    b93SignedWebhook($this, 'bank_transfer', $payload, 'bt-ok')->assertStatus(202);
    $p->refresh();
    expect($p->status)->toBe('SUCCEEDED')->and($p->reconciled_at)->not->toBeNull()
        ->and(PaymentMachine::blueprintState($p->status, true))->toBe('RECONCILED');
    $outbox = DB::table('outbox_messages')->where(['event_name' => 'payment.status.changed', 'aggregate_id' => $p->id])->first();
    expect(json_decode($outbox->payload, true)['blueprint_state'])->toBe('RECONCILED');
});

it('refuses bank transfer initiation when no account is configured', function () {
    config(['payments.providers.bank_transfer' => ['bank_name' => '', 'account_number' => '']]);
    $f = b93Payment('bank_transfer');
    expect(fn () => app(PaymentInitiationService::class)->initiate($f['payment']))->toThrow(DomainException::class);
    // Initiation runs in one transaction, so a refused initiation leaves the intent untouched and retryable.
    expect($f['payment']->refresh()->status)->toBe('CREATED');
});

it('opens a sandbox hosted card checkout and only a signed callback confirms it', function () {
    $f = b93Payment('card_sandbox');
    $adapter = app(PaymentAdapterRegistry::class)->for('card_sandbox');
    expect($adapter)->toBeInstanceOf(HostedCardCheckoutAdapter::class);

    $p = app(PaymentInitiationService::class)->initiate($f['payment']);
    expect($p->status)->toBe('PENDING_CUSTOMER')->and($p->provider_reference)->toStartWith('CARD-SBX-')
        ->and($p->attempts()->first()->response_snapshot['checkout_url'])->toBe($adapter->checkoutUrl($p->provider_reference));

    $payload = ['payment_reference' => $p->provider_reference, 'status' => 'SUCCEEDED', 'amount_minor' => 100000, 'currency' => 'XAF'];
    b93SignedWebhook($this, 'card_sandbox', $payload, 'card-ok')->assertStatus(202);
    expect($p->refresh()->status)->toBe('SUCCEEDED');

    // Reversal is provider-driven and terminal.
    b93SignedWebhook($this, 'card_sandbox', ['status' => 'REVERSED'] + $payload, 'card-rev')->assertStatus(202);
    expect($p->refresh()->status)->toBe('REVERSED');
    b93SignedWebhook($this, 'card_sandbox', ['status' => 'SUCCEEDED'] + $payload, 'card-again')->assertStatus(202);
    expect($p->refresh()->status)->toBe('REVERSED')
        ->and(DB::table('webhook_inbox')->where('external_event_id', 'card-again')->value('failure_reason'))->toBe('INVALID_STATUS_TRANSITION');
});

it('keeps the existing webhook transitions and rejects ones the machine forbids', function () {
    $f = b93Payment('fake', ['status' => 'PENDING_CUSTOMER', 'provider_reference' => (string) Str::uuid()]);
    $send = fn (string $s) => app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $f['payment']->provider_reference, 'amount_minor' => 100000, 'currency' => 'XAF', 'status' => $s], 'sig');
    $send('PROCESSING');
    expect($f['payment']->refresh()->status)->toBe('PROCESSING');
    $send('REFUNDED'); // not from PROCESSING
    expect($f['payment']->refresh()->status)->toBe('PROCESSING');
    $send('FAILED');
    expect($f['payment']->refresh()->status)->toBe('FAILED');
    $send('SUCCEEDED'); // FAILED only leaves through a new attempt, never a callback
    expect($f['payment']->refresh()->status)->toBe('FAILED');
});
