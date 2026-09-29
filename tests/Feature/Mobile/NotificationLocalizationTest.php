<?php

declare(strict_types=1);

use App\Application\Notifications\CustomerNotifier;
use App\Application\Notifications\NotificationCatalog;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/*
 * Customer notifications follow the app language: rows keep the English text
 * plus a stable code + params; the inbox renders them per Accept-Language (or
 * users.locale), push/SMS per users.locale, and pre-code rows are recognised
 * from their English text.
 */

it('has French copy with the same placeholders for every English notification code', function () {
    $en = Lang::get('customer_notifications', [], 'en');
    $fr = Lang::get('customer_notifications', [], 'fr');
    $placeholders = fn (string $s) => collect(preg_match_all('/:([A-Za-z]\w*)/', $s, $m) ? $m[1] : [])->map(fn ($p) => lcfirst($p))->unique()->sort()->values()->all();

    expect(array_keys($fr))->toEqualCanonicalizing(array_keys($en));
    foreach ($en as $code => $copy) {
        if (str_starts_with($code, '_')) {
            expect(array_keys($fr[$code]))->toEqualCanonicalizing(array_keys($copy));

            continue;
        }
        foreach (['title', 'body'] as $part) {
            expect($fr[$code][$part])->not->toBe('')
                ->and($placeholders($fr[$code][$part]))->toBe($placeholders($copy[$part]), "{$code}.{$part}");
        }
    }
});

it('stores English text with a code and renders the inbox in the app language', function () {
    $f = makeMobileCustomerFixture('+237671220001');
    app(CustomerNotifier::class)->toUser($f['user'], $f['tenant']->id, 'PAYMENT',
        ...NotificationCatalog::message('payment_issuance_in_progress', ['product' => 'Motor Third Party']), severity: 'SUCCESS', path: '/payments/p-1');

    $row = UserNotification::where('user_id', $f['user']->id)->sole();
    expect($row->title)->toBe('Payment received — issuance in progress')
        ->and($row->body)->toContain('We received your payment for Motor Third Party.')
        ->and($row->code)->toBe('payment_issuance_in_progress')
        ->and($row->params)->toBe(['product' => 'Motor Third Party']);

    Passport::actingAs($f['user']);
    $fr = $this->getJson('/api/v1/mobile/notifications', ['Accept-Language' => 'fr'] + tenantHeaderFor($f['tenant']))->assertOk();
    expect($fr->json('data.0.title'))->toBe('Paiement reçu — émission en cours')
        ->and($fr->json('data.0.body'))->toContain('Nous avons reçu votre paiement pour Motor Third Party.')
        ->and($fr->json('data.0.code'))->toBe('payment_issuance_in_progress')
        ->and($fr->json('data.0.params.product'))->toBe('Motor Third Party');

    $en = $this->getJson('/api/v1/mobile/notifications', ['Accept-Language' => 'en-CM'] + tenantHeaderFor($f['tenant']))->assertOk();
    expect($en->json('data.0.title'))->toBe('Payment received — issuance in progress');

    // Show / mark-read answer in the same language.
    $this->getJson("/api/v1/mobile/notifications/{$row->id}", ['Accept-Language' => 'fr'] + tenantHeaderFor($f['tenant']))
        ->assertOk()->assertJsonPath('data.title', 'Paiement reçu — émission en cours');
    $this->postJson("/api/v1/mobile/notifications/{$row->id}/read", [], ['Accept-Language' => 'fr'] + tenantHeaderFor($f['tenant']))
        ->assertOk()->assertJsonPath('data.read', true)->assertJsonPath('data.title', 'Paiement reçu — émission en cours');
});

it('falls back to the saved user locale when the app sends no language', function () {
    $f = makeMobileCustomerFixture('+237671220002');
    $f['user']->forceFill(['locale' => 'fr'])->save();
    UserNotification::notify($f['user'], 'CLAIM', ...NotificationCatalog::message('claim_approved', ['claim' => 'CLM-42']), severity: 'SUCCESS', path: '/claim/x');
    Passport::actingAs($f['user']);

    // (The test client sends a default Accept-Language; an empty one is what a client without it looks like.)
    $this->getJson('/api/v1/mobile/notifications', ['Accept-Language' => ''] + tenantHeaderFor($f['tenant']))->assertOk()
        ->assertJsonPath('data.0.title', 'Sinistre approuvé')
        ->assertJsonPath('data.0.body', 'La déclaration CLM-42 a été approuvée. Le règlement est en préparation.');
});

it('translates rows written before codes existed by recognising their English text', function () {
    $f = makeMobileCustomerFixture('+237671220003');
    // Exactly what PolicyIssuanceService / NotifyPolicyExpiry wrote before this change.
    UserNotification::notify($f['user'], 'POLICY', "You're covered", 'Your policy policy POL-9 is active from 01 Oct 2026. Your certificate is in your wallet.', 'SUCCESS', '/policy/a');
    UserNotification::notify($f['user'], 'RENEWAL', 'Your cover ends in 7 days', 'Motor policy POL-7 expires in 7 days (08 Oct 2026). Renew now to stay covered.', 'WARNING', '/policy/b');
    UserNotification::notify($f['user'], 'INFO', 'Something bespoke', 'Written by hand, no template.', 'INFO', null);
    Passport::actingAs($f['user']);

    $rows = collect($this->getJson('/api/v1/mobile/notifications', ['Accept-Language' => 'fr'] + tenantHeaderFor($f['tenant']))->assertOk()->json('data'))->keyBy('path');

    expect($rows['/policy/a']['title'])->toBe('Vous êtes couvert')
        ->and($rows['/policy/a']['body'])->toBe('Votre police POL-9 (votre couverture) est active à partir du 1 octobre 2026. Votre attestation est dans votre portefeuille.')
        ->and($rows['/policy/a']['code'])->toBe('policy_issued')
        ->and($rows['/policy/b']['title'])->toBe('Votre couverture se termine dans 7 jours')
        ->and($rows['/policy/b']['body'])->toContain('Votre police POL-7 (Motor) expire dans 7 jours (8 octobre 2026)');
    $bespoke = collect($rows)->firstWhere('title', 'Something bespoke');
    expect($bespoke['body'])->toBe('Written by hand, no template.')
        ->and($bespoke['code'])->toBeNull();
});

it('sends push in the user language while the inbox row keeps English', function () {
    Http::preventStrayRequests();
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't-1']]])]);
    $f = makeMobileCustomerFixture('+237671220004');
    $f['user']->forceFill(['locale' => 'fr'])->save();
    DB::table('user_push_tokens')->insert(['id' => (string) Str::uuid(), 'user_id' => $f['user']->id, 'token' => 'ExponentPushToken[fr1]', 'platform' => 'android', 'provider' => 'expo', 'created_at' => now(), 'updated_at' => now()]);

    app(CustomerNotifier::class)->toUser($f['user'], $f['tenant']->id, 'RENEWAL',
        ...NotificationCatalog::message('renewal_ends_tomorrow', ['product' => 'Motor', 'policy' => 'POL-1', 'ends' => '2026-10-02']), severity: 'WARNING', path: '/policy/c');

    expect(UserNotification::where('user_id', $f['user']->id)->value('title'))->toBe('Your cover ends tomorrow');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'exp.host') && $r[0]['title'] === 'Votre couverture se termine demain'
        && str_contains($r[0]['body'], 'Votre police POL-1 (Motor) expire demain (2 octobre 2026)'));
});

it('keeps the legacy shape for callers that pass plain text', function () {
    $f = makeMobileCustomerFixture('+237671220005');
    $row = UserNotification::notify($f['user'], 'INFO', 'Mine', 'For me');

    expect($row->code)->toBeNull()
        ->and($row->toMobile())->toMatchArray(['title' => 'Mine', 'body' => 'For me', 'code' => null])
        ->and($row->toMobile('fr'))->toMatchArray(['title' => 'Mine', 'body' => 'For me']);
});
