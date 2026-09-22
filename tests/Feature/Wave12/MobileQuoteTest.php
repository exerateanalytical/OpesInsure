<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

it('lists only the authenticated customer\'s own quotes, newest first', function () {
    $fixture = makeMobileCustomerFixture();
    $mine = makeMobileTestQuote($fixture['tenant'], $fixture['party']);

    $otherFixture = makeMobileCustomerFixture('+237670000092');
    makeMobileTestQuote($fixture['tenant'], $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson('/api/v1/mobile/quotes', tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    // The fixture itself creates one quote (feeding the proposal chain), plus the
    // one just made here — both belong to $fixture['party'], the other customer's does not.
    expect($response->json('data.data'))->toHaveCount(2);
    expect($response->json('data.data.*.id'))->toContain($mine->id, $fixture['quote']->id);
});

it('shows a single owned quote with its offers, and marks it not expired', function () {
    $fixture = makeMobileCustomerFixture();
    $quote = makeMobileTestQuote($fixture['tenant'], $fixture['party'], ['status' => 'OFFERED']);
    makeMobileTestQuoteOffer($quote, $fixture['carrier']->id, $fixture['product']->id, $fixture['tariff']->id);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson("/api/v1/mobile/quotes/{$quote->id}", tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.quote.id'))->toBe($quote->id);
    expect($response->json('data.quote.is_expired'))->toBeFalse();
    expect($response->json('data.offers'))->toHaveCount(1);
});

it('403s a show request for a quote belonging to another customer', function () {
    $fixture = makeMobileCustomerFixture();
    $otherFixture = makeMobileCustomerFixture('+237670000091');
    $theirs = makeMobileTestQuote($fixture['tenant'], $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $this->getJson("/api/v1/mobile/quotes/{$theirs->id}", tenantHeaderFor($fixture['tenant']))->assertStatus(403);
});

it('resumes a quote that is still in an active, unexpired status', function () {
    $fixture = makeMobileCustomerFixture();
    $quote = makeMobileTestQuote($fixture['tenant'], $fixture['party'], ['status' => 'SUBMITTED']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/quotes/{$quote->id}/resume", [], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.quote.status'))->toBe('SUBMITTED');
});

it('refuses to resume a quote that has already been accepted', function () {
    $fixture = makeMobileCustomerFixture();
    $quote = makeMobileTestQuote($fixture['tenant'], $fixture['party'], ['status' => 'ACCEPTED']);

    Passport::actingAs($fixture['user']);

    $this->postJson("/api/v1/mobile/quotes/{$quote->id}/resume", [], tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('refuses to resume an expired quote even while its status is still active', function () {
    $fixture = makeMobileCustomerFixture();
    $quote = makeMobileTestQuote($fixture['tenant'], $fixture['party'], ['status' => 'OFFERED', 'expires_at' => now()->subDay()]);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/quotes/{$quote->id}/resume", [], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(422);
    expect($response->json('errors.status.0'))->toBe(__('wave12.quote_expired'));
});

it('403s a resume attempt for a quote belonging to another customer', function () {
    $fixture = makeMobileCustomerFixture();
    $otherFixture = makeMobileCustomerFixture('+237670000090');
    $theirs = makeMobileTestQuote($fixture['tenant'], $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $this->postJson("/api/v1/mobile/quotes/{$theirs->id}/resume", [], tenantHeaderFor($fixture['tenant']))->assertStatus(403);
});

it('cancels an owned quote still in an active status', function () {
    $fixture = makeMobileCustomerFixture();
    $quote = makeMobileTestQuote($fixture['tenant'], $fixture['party'], ['status' => 'SUBMITTED']);

    Passport::actingAs($fixture['user']);

    $response = $this->deleteJson("/api/v1/mobile/quotes/{$quote->id}", [], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.status'))->toBe('CANCELLED');
    expect($quote->fresh()->status)->toBe('CANCELLED');
});

it('refuses to cancel a quote that has already been accepted', function () {
    $fixture = makeMobileCustomerFixture();
    $quote = makeMobileTestQuote($fixture['tenant'], $fixture['party'], ['status' => 'ACCEPTED']);

    Passport::actingAs($fixture['user']);

    $this->deleteJson("/api/v1/mobile/quotes/{$quote->id}", [], tenantHeaderFor($fixture['tenant']))->assertStatus(422);
    expect($quote->fresh()->status)->toBe('ACCEPTED');
});

it('403s a cancel attempt for a quote belonging to another customer', function () {
    $fixture = makeMobileCustomerFixture();
    $otherFixture = makeMobileCustomerFixture('+237670000089');
    $theirs = makeMobileTestQuote($fixture['tenant'], $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $this->deleteJson("/api/v1/mobile/quotes/{$theirs->id}", [], tenantHeaderFor($fixture['tenant']))->assertStatus(403);
    expect($theirs->fresh()->status)->not->toBe('CANCELLED');
});

it('rejects an unauthenticated request to list quotes', function () {
    $this->getJson('/api/v1/mobile/quotes')->assertStatus(401);
});
