<?php

declare(strict_types=1);

use App\Models\RiskAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/mobile_customer_helpers.php';

it('lists only the authenticated customer\'s own assets', function () {
    $fixture = makeMobileCustomerFixture();
    $mine = makeMobileTestRiskAsset($fixture['tenant'], $fixture['party']);

    $otherFixture = makeMobileCustomerFixture('+237670000301');
    makeMobileTestRiskAsset($fixture['tenant'], $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->getJson('/api/v1/mobile/assets', tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.data'))->toHaveCount(1);
    expect($response->json('data.data.0.id'))->toBe($mine->id);
});

it('shows a single owned asset, 403s for someone else\'s, 404s for a nonexistent one', function () {
    $fixture = makeMobileCustomerFixture();
    $mine = makeMobileTestRiskAsset($fixture['tenant'], $fixture['party']);

    $otherFixture = makeMobileCustomerFixture('+237670000302');
    $theirs = makeMobileTestRiskAsset($fixture['tenant'], $otherFixture['party']);

    Passport::actingAs($fixture['user']);

    $this->getJson("/api/v1/mobile/assets/{$mine->id}", tenantHeaderFor($fixture['tenant']))->assertStatus(200);
    $this->getJson("/api/v1/mobile/assets/{$theirs->id}", tenantHeaderFor($fixture['tenant']))->assertStatus(403);
    $this->getJson('/api/v1/mobile/assets/'.Str::uuid(), tenantHeaderFor($fixture['tenant']))->assertStatus(404);
});

it('registers a new insured asset for the customer\'s own party', function () {
    $fixture = makeMobileCustomerFixture();
    makeMobileTestTenantCustomer($fixture['tenant'], $fixture['party']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/assets', [
        'type' => 'VEHICLE',
        'display_name' => 'My Toyota Corolla',
        'external_reference' => 'LT-1234-AB',
        'facts' => ['plate_number' => 'LT-1234-AB', 'make' => 'Toyota'],
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(201);
    expect($response->json('data.party_id'))->toBe($fixture['party']->id);
    expect($response->json('data.status'))->toBe('ACTIVE');
    expect($response->json('data.version'))->toBe(1);
});

it('rejects a duplicate external_reference for the same tenant and type with a 409', function () {
    $fixture = makeMobileCustomerFixture();
    makeMobileTestTenantCustomer($fixture['tenant'], $fixture['party']);
    Passport::actingAs($fixture['user']);

    $payload = [
        'type' => 'VEHICLE',
        'display_name' => 'My Toyota Corolla',
        'external_reference' => 'LT-1234-AB',
        'facts' => ['plate_number' => 'LT-1234-AB'],
    ];

    $this->postJson('/api/v1/mobile/assets', $payload, tenantHeaderFor($fixture['tenant']))->assertStatus(201);
    $this->postJson('/api/v1/mobile/assets', $payload, tenantHeaderFor($fixture['tenant']))->assertStatus(409);
});

it('attaches an owned, clean document as asset evidence', function () {
    $fixture = makeMobileCustomerFixture();
    $asset = makeMobileTestRiskAsset($fixture['tenant'], $fixture['party']);
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party'], ['category' => 'VEHICLE_REGISTRATION']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/assets/{$asset->id}/documents", [
        'document_id' => $document->id,
        'purpose' => 'REGISTRATION_CARD',
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(201);
    expect($response->json('data.documents.0.id'))->toBe($document->id);
    expect($response->json('data.documents.0.purpose'))->toBe('REGISTRATION_CARD');
});

it('rejects attaching an infected document and rejects attaching someone else\'s document', function () {
    $fixture = makeMobileCustomerFixture();
    $asset = makeMobileTestRiskAsset($fixture['tenant'], $fixture['party']);
    $infected = makeMobileTestDocument($fixture['tenant'], $fixture['party'], ['scan_status' => 'INFECTED']);

    Passport::actingAs($fixture['user']);

    $this->postJson("/api/v1/mobile/assets/{$asset->id}/documents", [
        'document_id' => $infected->id,
        'purpose' => 'REGISTRATION_CARD',
    ], tenantHeaderFor($fixture['tenant']))->assertStatus(422);

    $otherFixture = makeMobileCustomerFixture('+237670000303');
    $theirs = makeMobileTestDocument($fixture['tenant'], $otherFixture['party']);

    $this->postJson("/api/v1/mobile/assets/{$asset->id}/documents", [
        'document_id' => $theirs->id,
        'purpose' => 'REGISTRATION_CARD',
    ], tenantHeaderFor($fixture['tenant']))->assertStatus(403);
});

it('requests a scan and gets back the honest manual-review placeholder since no OCR provider is configured', function () {
    $fixture = makeMobileCustomerFixture();
    $asset = makeMobileTestRiskAsset($fixture['tenant'], $fixture['party']);
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);
    $asset->documents()->attach($document->id, ['purpose' => 'REGISTRATION_CARD']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/assets/{$asset->id}/scan", [], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.documents.0.ocr_data.status'))->toBe('MANUAL_REVIEW_REQUIRED');
    expect($response->json('data.documents.0.ocr_data.fields'))->toBe([]);
});

it('refuses to scan a document that has not passed the malware scan', function () {
    $fixture = makeMobileCustomerFixture();
    $asset = makeMobileTestRiskAsset($fixture['tenant'], $fixture['party']);
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party'], ['scan_status' => 'PENDING']);
    $asset->documents()->attach($document->id, ['purpose' => 'REGISTRATION_CARD']);

    Passport::actingAs($fixture['user']);

    $this->postJson("/api/v1/mobile/assets/{$asset->id}/scan", [], tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('lets the customer manually confirm facts for an attached document, bumping the asset version', function () {
    $fixture = makeMobileCustomerFixture();
    $asset = makeMobileTestRiskAsset($fixture['tenant'], $fixture['party'], ['facts' => ['plate_number' => 'LT-1234-AB']]);
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);
    $asset->documents()->attach($document->id, ['purpose' => 'REGISTRATION_CARD']);

    Passport::actingAs($fixture['user']);

    $response = $this->postJson("/api/v1/mobile/assets/{$asset->id}/scan/{$document->id}/confirm", [
        'version' => 1,
        'facts' => ['chassis_number' => 'VF1LT1234AB'],
    ], tenantHeaderFor($fixture['tenant']));

    $response->assertStatus(200);
    expect($response->json('data.version'))->toBe(2);
    expect($response->json('data.facts.chassis_number'))->toBe('VF1LT1234AB');
    expect($response->json('data.facts.plate_number'))->toBe('LT-1234-AB');
    expect($response->json('data.documents.0.ocr_data.status'))->toBe('CUSTOMER_CONFIRMED');
    expect($response->json('data.documents.0.verification_status'))->toBe('NEEDS_REVIEW');

    expect(RiskAsset::findOrFail($asset->id)->version)->toBe(2);
});

it('rejects a stale version on confirm', function () {
    $fixture = makeMobileCustomerFixture();
    $asset = makeMobileTestRiskAsset($fixture['tenant'], $fixture['party']);
    $document = makeMobileTestDocument($fixture['tenant'], $fixture['party']);
    $asset->documents()->attach($document->id, ['purpose' => 'REGISTRATION_CARD']);

    Passport::actingAs($fixture['user']);

    $this->postJson("/api/v1/mobile/assets/{$asset->id}/scan/{$document->id}/confirm", [
        'version' => 99,
        'facts' => ['chassis_number' => 'VF1LT1234AB'],
    ], tenantHeaderFor($fixture['tenant']))->assertStatus(422);
});

it('rejects unauthenticated access to the asset endpoints', function () {
    $this->getJson('/api/v1/mobile/assets')->assertStatus(401);

    $fixture = makeMobileCustomerFixture();
    $asset = makeMobileTestRiskAsset($fixture['tenant'], $fixture['party']);
    $this->postJson("/api/v1/mobile/assets/{$asset->id}/scan", [], tenantHeaderFor($fixture['tenant']))->assertStatus(401);
});
