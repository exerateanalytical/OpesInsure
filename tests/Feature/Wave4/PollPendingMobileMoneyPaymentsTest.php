<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_money_helpers.php';

it('reconciles both an MTN and an Orange pending intent by re-querying each provider directly', function () {
    $mtnIntent = makeMobileMoneyTestIntent('mtn_momo', (string) Str::uuid());
    $orangeIntent = makeMobileMoneyTestIntent('orange_money', 'pay-token-xyz');

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL'], 200),
        '*/oauth/v3/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/orange-money-webpay/*/v1/transactionstatus' => Http::response(['status' => 'FAILED'], 200),
    ]);

    expect(Artisan::call('payments:poll-pending'))->toBe(0);
    expect(Artisan::output())->toContain('Polled: 2')->toContain('Transitioned: 2')->toContain('Failed: 0');

    expect($mtnIntent->refresh()->status)->toBe('SUCCEEDED');
    expect($orangeIntent->refresh()->status)->toBe('FAILED');
});

it('does not poll intents for other providers or intents already in a terminal state', function () {
    $fake = makeMobileMoneyTestIntent('fake', (string) Str::uuid());
    $succeeded = makeMobileMoneyTestIntent('mtn_momo', (string) Str::uuid(), ['status' => 'SUCCEEDED']);

    Http::fake();

    expect(Artisan::call('payments:poll-pending'))->toBe(0);
    expect(Artisan::output())->toContain('Polled: 0');

    Http::assertNothingSent();
    expect($fake->refresh()->status)->toBe('PENDING_CUSTOMER');
    expect($succeeded->refresh()->status)->toBe('SUCCEEDED');
});

it('does not poll an intent with no provider_reference yet', function () {
    makeMobileMoneyTestIntent('mtn_momo', (string) Str::uuid(), ['provider_reference' => null]);

    Http::fake();

    expect(Artisan::call('payments:poll-pending'))->toBe(0);
    expect(Artisan::output())->toContain('Polled: 0');

    Http::assertNothingSent();
});

it('continues past a failed provider query and reports it without crashing the run', function () {
    $healthy = makeMobileMoneyTestIntent('mtn_momo', (string) Str::uuid());
    $broken = makeMobileMoneyTestIntent('orange_money', 'pay-token-broken');

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL'], 200),
        '*/oauth/v3/token' => Http::response([], 500), // Orange auth fails outright
    ]);

    expect(Artisan::call('payments:poll-pending'))->toBe(0);
    expect(Artisan::output())->toContain('Polled: 2')->toContain('Failed: 1');

    expect($healthy->refresh()->status)->toBe('SUCCEEDED');
    expect($broken->refresh()->status)->toBe('PENDING_CUSTOMER');
});
