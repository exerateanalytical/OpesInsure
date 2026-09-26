<?php

declare(strict_types=1);

// D2 follow-up (DOCUMENT_SECURITY_COMPLETION_PLAN): MAPPED_PLATFORM_SOURCE field rules render in the secure shell.

use App\Application\Documents\Engine\DocumentShellView;
use App\Application\Documents\Security\MappedFieldValues;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\PolicyIssuanceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

it('D2 REQ-DOC-SEC-D2: mapped values go to their zone, absent ones are omitted, fixed-zone keys are not repeated', function () {
    $mapped = [
        'beneficiary information where applicable' => ['key' => 'beneficiary.list', 'source' => 'beneficiary_designations'],
        'quote reference' => ['key' => 'quote.number', 'source' => 'quotes.quote_number'],
        'net premium' => ['key' => 'premium.net', 'source' => 'quote_offers.premium_minor'],
        'valid-until date' => ['key' => 'quote.valid_until', 'source' => 'quote_offers.valid_until'],
        'proposed policyholder' => ['key' => 'party.name', 'source' => 'parties.display_name'],
        'document number' => ['key' => 'document.number', 'source' => 'documents.document_number'],
        'payment preference' => ['key' => null, 'source' => 'gap'],
    ];
    $values = ['beneficiary.list' => [['name' => 'Ada'], ['name' => 'Ben']], 'quote.number' => 'Q-1', 'premium.net' => 90000, 'quote.valid_until' => '', 'party.name' => 'X', 'document.number' => 'D-1'];
    $rows = DocumentShellView::mappedRows($mapped, $values, fn ($k) => $k, fn ($m) => number_format($m / 100, 0, '.', ' ').' XAF');

    expect(array_column($rows['C'], 'value'))->toBe(['Ada · Ben'])
        ->and(array_column($rows['D'], 'value'))->toBe(['Q-1', '900 XAF'])
        ->and(json_encode($rows))->not->toContain('PENDING');
});

it('D2 REQ-DOC-SEC-D2: new issuance resolves mapped platform values; the shell receives the mapped rows', function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't']]])]);
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['offer_id' => null, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF']]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
    app(\App\Application\Payments\WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $f['payment']->provider_reference, 'amount_minor' => $f['payment']->amount_minor, 'currency' => $f['payment']->currency, 'status' => 'SUCCEEDED',
    ], 'sig');
    $renders = [];
    View::composer('pdf.engine-shell', function ($view) use (&$renders) {
        $renders[] = $view->getData();
    });
    $policy = app(PolicyIssuanceService::class)->approve(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail(),
        ['policy_number' => 'POL-D2-'.Str::random(6), 'carrier_reference' => 'CR'], \App\Models\User::create(['full_name' => 'Desk', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']));

    $values = MappedFieldValues::resolve($policy->refresh(), []);
    expect($values)->toHaveKey('premium.net')->and($values)->not->toContain(null)->and($values)->not->toContain('');
    expect($renders)->not->toBeEmpty();
    foreach ($renders as $d) {
        expect($d)->toHaveKeys(['mappedParty', 'mappedContent']);
        foreach (array_merge($d['mappedParty'], $d['mappedContent']) as $r) {
            expect(trim((string) $r['value']))->not->toBe('');
        }
    }
});
