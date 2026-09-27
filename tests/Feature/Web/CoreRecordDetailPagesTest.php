<?php

declare(strict_types=1);

/**
 * REQ-UI-002 core-record detail pages (web UI phase 1): policy, claim, quote,
 * proposal, generated document, party, customer, partner. Each renders the
 * shared RecordShell (header summary + Overview / Timeline / Documents /
 * Financial / Related tabs) for an authorised user and refuses others.
 */

use App\Application\WebExperiences\{DocumentPanelQuery, RelatedRecordsQuery};
use App\Domain\Tenancy\TenantContext;
use App\Models\{Claim, Partner, Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function coreDetailUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => ['*'], 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

/** @return array<string, mixed> */
function coreDetailFixture(string $n = '1'): array
{
    $t = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'Core Detail '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $c = makeMobileFinanceProposalChain($t);
    $c['proposal']->update(['proposal_number' => 'PRP-CORE-'.$n]);
    $policy = makeMobileTestPolicy($c['proposal'], $t, $c['carrier']->id, $c['party']->id, ['policy_number' => 'POL-CORE-'.$n]);
    $claim = Claim::create(['tenant_id' => $t->id, 'policy_id' => $policy->id, 'claimant_party_id' => $c['party']->id, 'claim_number' => 'CLM-CORE-'.$n, 'status' => 'OPEN', 'loss_occurred_at' => now()->subDay(), 'loss_details' => []]);
    $doc = makeMobileTestDocument($t, $c['party'], ['policy_id' => $policy->id, 'document_type_code' => 'POLICY_SCHEDULE', 'document_origin' => 'SYSTEM', 'security_level' => 'PUBLIC_VERIFIABLE', 'status' => 'ISSUED', 'document_number' => 'DOC-CORE-'.$n, 'verification_code' => 'VC-CORE-'.$n, 'issuer_type' => 'CARRIER', 'issued_at' => now()]);
    $customer = makeMobileTestTenantCustomer($t, $c['party']);
    $partner = Partner::create(['tenant_id' => $t->id, 'party_id' => $c['party']->id, 'type' => 'AGENT', 'status' => 'ACTIVE']);
    $quote = $c['proposal']->offer->quote;
    $quote->update(['partner_id' => $partner->id]);

    return ['tenant' => $t, 'party' => $c['party'], 'policy' => $policy, 'claim' => $claim, 'document' => $doc, 'customer' => $customer, 'partner' => $partner, 'quote' => $quote, 'proposal' => $c['proposal']];
}

function coreDetailUrls(array $f): array
{
    return [
        'policy' => ['/admin/policies/'.$f['policy']->id, 'POL-CORE-1'],
        'claim' => ['/admin/claims/'.$f['claim']->id, 'CLM-CORE-1'],
        'quote' => ['/admin/quotes/'.$f['quote']->id, 'Mobile Finance Test Customer'],
        'proposal' => ['/admin/proposals/'.$f['proposal']->id, 'PRP-CORE-1'],
        'document' => ['/admin/document-engine/registry/'.$f['document']->id, 'DOC-CORE-1'],
        'party' => ['/admin/parties/'.$f['party']->id, 'Mobile Finance Test Customer'],
        'customer' => ['/admin/customers/'.$f['customer']->id, $f['customer']->customer_number],
        'partner' => ['/admin/partners/'.$f['partner']->id, 'Mobile Finance Test Customer'],
    ];
}

test('REQ-UI-002 every core detail page renders the record shell with overview, related and timeline tabs for an admin', function () {
    $f = coreDetailFixture();
    $admin = coreDetailUser($f['tenant'], 'PLATFORM_ADMIN');

    foreach (coreDetailUrls($f) as $name => [$url, $identifier]) {
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('data-testid="record-identifier"', false)
            ->assertSee($identifier)
            ->assertSee(__('web_experience.tabs.overview'))
            ->assertSee(__('web_experience.tabs.timeline'))
            ->assertSee(__('web_experience.tabs.related'));
    }
});

test('REQ-UI-002 core detail pages refuse a panel user whose role does not grant the record (403)', function () {
    $f = coreDetailFixture();
    $claimsOfficer = coreDetailUser($f['tenant'], 'CLAIMS_OFFICER');
    $finance = coreDetailUser($f['tenant'], 'FINANCE_ADMIN');
    $urls = coreDetailUrls($f);

    foreach (['policy', 'document', 'party', 'customer', 'partner'] as $name) {
        $this->actingAs($claimsOfficer)->get($urls[$name][0])->assertForbidden();
        $this->flushSession();
    }
    $this->actingAs($finance)->get($urls['claim'][0])->assertForbidden();
    $this->flushSession();

    // Quote / proposal are tenant-membership scoped: another tenant's record is never shown.
    $other = coreDetailFixture('2');
    $foreignAdmin = coreDetailUser($other['tenant'], 'PLATFORM_ADMIN');
    expect($this->actingAs($foreignAdmin)->get($urls['quote'][0])->status())->toBeIn([403, 404])
        ->and($this->actingAs($foreignAdmin)->get($urls['proposal'][0])->status())->toBeIn([403, 404]);
});

test('REQ-UI-002 related records and document rows are tenant-scoped and carry download / verify links', function () {
    $f = coreDetailFixture();
    $other = coreDetailFixture('2');
    app(TenantContext::class)->set($f['tenant']->id);
    $this->actingAs(coreDetailUser($f['tenant'], 'PLATFORM_ADMIN'));

    $groups = collect(app(RelatedRecordsQuery::class)->for($f['policy']))->keyBy('key');
    expect($groups->keys()->all())->toContain('customer', 'proposal', 'claims')
        ->and($groups['claims']['rows'][0]['label'])->toBe('CLM-CORE-1');

    $partnerGroups = collect(app(RelatedRecordsQuery::class)->for($f['partner']))->keyBy('key');
    expect($partnerGroups->keys()->all())->toContain('quotes');

    // Party is platform-wide: only the current tenant's book is listed.
    $partyPolicies = collect(app(RelatedRecordsQuery::class)->for($f['party']))->keyBy('key')['policies']['rows'] ?? [];
    expect(collect($partyPolicies)->pluck('label')->all())->toBe(['POL-CORE-1']);

    $docs = app(DocumentPanelQuery::class)->for($f['policy'], auth()->user());
    expect($docs['rows'])->toHaveCount(1)
        ->and($docs['rows'][0]['download_url'])->toContain('policy-documents')
        ->and($docs['rows'][0]['verify_url'])->toContain('VC-CORE-1');
    expect(app(DocumentPanelQuery::class)->for($f['customer'], auth()->user())['rows'])->toHaveCount(1);
});
