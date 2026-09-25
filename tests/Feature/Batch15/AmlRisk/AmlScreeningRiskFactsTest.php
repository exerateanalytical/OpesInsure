<?php

declare(strict_types=1);

/** WA-FIX — E9 → E8 contract: ScreeningService::riskFacts(tenant, party) from screening_hits dispositions feeds the AML risk rating. */

use App\Application\Compliance\Aml\Screening\Models\ScreeningHit;
use App\Application\Compliance\Aml\Screening\ScreeningListService;
use App\Application\Compliance\Aml\Screening\ScreeningService;
use App\Models\Party;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_auth_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

beforeEach(function () {
    $this->fx = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->fx['tenant'];
    $this->analyst = makeAuthTestUser($this->tenant, ['aml.risk.rate', 'aml.risk.view', 'cases.view', 'cases.restricted.view'], 'AML_ANALYST');
    $this->maker = makeAuthTestUser($this->tenant, ['aml.screening.lists.manage']);
    $this->checker = makeAuthTestUser($this->tenant, ['aml.screening.lists.approve']);
});

function wafixList(string $type, string $name): void
{
    $svc = app(ScreeningListService::class);
    $src = $svc->createSource(test()->tenant->id, ['code' => 'WF_'.$type, 'name' => "WF {$type}", 'list_type' => $type], test()->maker);
    $v = $svc->import($src, 'CSV', "entry_ref,name\nWF-1,{$name}\n", 'wf-'.Str::random(4), test()->maker);
    $svc->decide($v, true, null, test()->checker);
}

function wafixRate(): \Illuminate\Testing\TestResponse
{
    $fx = test()->fx;
    foreach (['ID_FRONT', 'PROOF_OF_ADDRESS'] as $purpose) {
        Passport::actingAs($fx['user']);
        test()->postJson('/api/v1/mobile/kyc/documents', ['document_id' => makeMobileTestDocument($fx['tenant'], $fx['party'])->id, 'purpose' => $purpose], tenantHeaderFor($fx['tenant']));
    }
    Passport::actingAs($fx['user']);
    test()->postJson('/api/v1/mobile/kyc/submission', [], tenantHeaderFor($fx['tenant']) + ['Idempotency-Key' => (string) Str::uuid()]);
    Passport::actingAs(test()->analyst);

    return test()->postJson('/api/v1/aml/customers/'.$fx['party']->id.'/risk-rating', ['reason' => 'Screening facts'], tenantHeaderFor(test()->tenant))->assertStatus(201);
}

it('WA-FIX: riskFacts maps hit dispositions; the AML rating uses them (source SCREENING_SERVICE) and drops false positives', function () {
    $party = Party::findOrFail($this->fx['party']->id);
    $svc = app(ScreeningService::class);
    expect($svc->riskFacts($this->tenant->id, $party->id))->toBe([]);

    wafixList('SANCTIONS', $party->display_name);
    wafixList('PEP', $party->display_name);
    $svc->screenParty($this->tenant->id, $party, 'MANUAL_RESCREEN');
    $hits = ScreeningHit::where('party_id', $party->id)->get()->keyBy('list_type');
    expect($hits)->toHaveCount(2)
        ->and($svc->riskFacts($this->tenant->id, $party->id))->toBe(['PEP_POSSIBLE_MATCH', 'SANCTIONS_POSSIBLE_MATCH']);

    // PEP → TRUE_MATCH (confirmed), SANCTIONS → FALSE_POSITIVE (no fact); maker-checker dispositions.
    $svc->decide($svc->propose($hits['PEP'], 'TRUE_MATCH', 'Same person', $this->maker), true, null, $this->checker);
    $svc->decide($svc->propose($hits['SANCTIONS'], 'FALSE_POSITIVE', 'Different DOB', $this->maker), true, null, $this->checker);
    expect($svc->riskFacts($this->tenant->id, $party->id))->toBe(['PEP_CONFIRMED_MATCH']);

    $r = wafixRate();
    expect($r->json('data.screening_source'))->toBe('SCREENING_SERVICE')
        ->and($r->json('data.explanation.screening.facts'))->toContain('PEP_CONFIRMED_MATCH')->not->toContain('SANCTIONS_POSSIBLE_MATCH')
        ->and($r->json('data.explanation.hard_triggers'))->toBe(['PEP_CONFIRMED_MATCH'])
        ->and($r->json('data.band'))->toBe('HIGH');
});
