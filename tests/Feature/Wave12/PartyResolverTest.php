<?php

declare(strict_types=1);

use App\Application\Identity\PartyResolver;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('resolves via the real party_id FK without touching party_contacts at all', function () {
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Linked Party', 'status' => 'ACTIVE']);
    $user = User::create(['full_name' => 'Linked User', 'phone_e164' => '+237670000001', 'party_id' => $party->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);

    $resolved = (new PartyResolver)->forUser($user);

    expect($resolved->id)->toBe($party->id);
});

it('falls back to phone matching and self-heals party_id when it was not already set', function () {
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Unlinked Party', 'status' => 'ACTIVE']);
    PartyContact::create(['party_id' => $party->id, 'type' => 'PHONE', 'normalized_value' => '+237670000002', 'is_primary' => true]);
    $user = User::create(['full_name' => 'Unlinked User', 'phone_e164' => '+237670000002', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);

    expect($user->party_id)->toBeNull();

    $resolved = (new PartyResolver)->forUser($user);

    expect($resolved->id)->toBe($party->id);
    expect($user->refresh()->party_id)->toBe($party->id); // self-healed

    // Second call now takes the fast path — no phone lookup needed, same result.
    $second = (new PartyResolver)->forUser($user->refresh());
    expect($second->id)->toBe($party->id);
});

it('returns null for a user with no party_id and no matching phone contact', function () {
    $user = User::create(['full_name' => 'No Party User', 'phone_e164' => '+237670000003', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);

    expect((new PartyResolver)->forUser($user))->toBeNull();
    expect($user->refresh()->party_id)->toBeNull();
});

it('resolves the caller\'s own Partner org via party_id, for an agent/broker/carrier login', function () {
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Agent Party', 'status' => 'ACTIVE']);
    $tenant = App\Models\Tenant::create(['type' => 'BROKER', 'legal_name' => 'Test Broker Org', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $partner = Partner::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'type' => 'AGENT', 'status' => 'ACTIVE']);
    $user = User::create(['full_name' => 'Agent User', 'phone_e164' => '+237670000004', 'party_id' => $party->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);

    $resolved = (new PartyResolver)->partnerForUser($user);

    expect($resolved->id)->toBe($partner->id);
});

it('partnerForUser returns null when the party has no corresponding Partner row (an ordinary customer)', function () {
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Plain Customer', 'status' => 'ACTIVE']);
    $user = User::create(['full_name' => 'Customer User', 'phone_e164' => '+237670000005', 'party_id' => $party->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);

    expect((new PartyResolver)->partnerForUser($user))->toBeNull();
});

it('the backfill command eagerly links every unlinked user with a matching phone contact, and skips the rest', function () {
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Backfill Party', 'status' => 'ACTIVE']);
    PartyContact::create(['party_id' => $party->id, 'type' => 'PHONE', 'normalized_value' => '+237670000006', 'is_primary' => true]);

    $linkable = User::create(['full_name' => 'Linkable', 'phone_e164' => '+237670000006', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $noMatch = User::create(['full_name' => 'No Match', 'phone_e164' => '+237670000007', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);

    Illuminate\Support\Facades\Artisan::call('identity:backfill-party-links');

    expect(Illuminate\Support\Facades\Artisan::output())->toContain('Linked: 1');
    expect($linkable->refresh()->party_id)->toBe($party->id);
    expect($noMatch->refresh()->party_id)->toBeNull();
});
