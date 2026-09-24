<?php

declare(strict_types=1);

use App\Models\PolicyCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

it('lists only the authenticated customer\'s own policies', function () {
    $fixture = makeMobileCustomerFixture();
    $mine = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);

    $otherFixture = makeMobileCustomerFixture('+237670000096');
    makeMobileTestPolicy($otherFixture['proposal'], $fixture['tenant'], $otherFixture['carrier']->id, $otherFixture['party']->id);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson('/api/v1/mobile/wallet', tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($mine->id);
});

it('shows a single owned policy with certificates and delivery loaded, 403s for someone else\'s', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);
    PolicyCertificate::create(['policy_id' => $policy->id, 'certificate_template_id' => makeMobileTestCertificateTemplate()->id, 'serial_number' => 'SN-'.Str::random(8), 'verification_token_hash' => hash('sha256', Str::random(20)), 'document_hash' => hash('sha256', 'x'), 'status' => 'VALID', 'issued_at' => now(), 'issued_by' => $fixture['user']->id]);

    $otherFixture = makeMobileCustomerFixture('+237670000095');
    $theirs = makeMobileTestPolicy($otherFixture['proposal'], $fixture['tenant'], $otherFixture['carrier']->id, $otherFixture['party']->id);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson("/api/v1/mobile/wallet/policies/{$policy->id}", tenantHeaderFor($fixture['tenant']));
    $response->assertStatus(200);
    expect($response->json('data.certificates.0.status'))->toBe('VALID');

    $this->getJson("/api/v1/mobile/wallet/policies/{$theirs->id}", tenantHeaderFor($fixture['tenant']))->assertStatus(403);
});

it('returns certificate metadata for an owned policy, and 404s when none is valid yet', function () {
    $fixture = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id);

    Passport::actingAs($fixture['user']);

    $this->getJson("/api/v1/policies/{$policy->id}/certificate", tenantHeaderFor($fixture['tenant']))->assertStatus(404);

    $certificate = PolicyCertificate::create(['policy_id' => $policy->id, 'certificate_template_id' => makeMobileTestCertificateTemplate()->id, 'serial_number' => 'SN-'.Str::random(8), 'verification_token_hash' => hash('sha256', Str::random(20)), 'document_hash' => hash('sha256', 'x'), 'status' => 'VALID', 'issued_at' => now(), 'issued_by' => $fixture['user']->id]);

    $response = $this->getJson("/api/v1/policies/{$policy->id}/certificate", tenantHeaderFor($fixture['tenant']));
    $response->assertStatus(200);
    expect($response->json('data.serial_number'))->toBe($certificate->serial_number);
});

it('rejects an unauthenticated wallet request', function () {
    $this->getJson('/api/v1/mobile/wallet')->assertStatus(401);
});
