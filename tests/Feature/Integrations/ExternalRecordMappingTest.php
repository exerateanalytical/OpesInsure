<?php

declare(strict_types=1);

use App\Application\Integrations\ExternalRecordMappingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/Concerns/helpers.php';

it('maps an external record to an internal one', function () {
    $actor = User::factory()->create();
    $client = registerTestIntegrationClient($actor);
    $service = app(ExternalRecordMappingService::class);
    $opesinsureId = (string) Str::uuid();

    $mapping = $service->map($client, 'policy', 'BROKER-9001', $opesinsureId);

    expect($mapping->opesinsure_record_id)->toBe($opesinsureId);
    expect($mapping->synchronization_status)->toBe('SYNCED');
});

it('is idempotent when mapping the same external id to the same internal id twice', function () {
    $actor = User::factory()->create();
    $client = registerTestIntegrationClient($actor);
    $service = app(ExternalRecordMappingService::class);
    $opesinsureId = (string) Str::uuid();

    $first = $service->map($client, 'policy', 'BROKER-9002', $opesinsureId);
    $second = $service->map($client, 'policy', 'BROKER-9002', $opesinsureId);

    expect($first->id)->toBe($second->id);
    expect(DB::table('external_record_mappings')->count())->toBe(1);
});

it('refuses to remap an external id to a different internal record, and flags no silent overwrite', function () {
    $actor = User::factory()->create();
    $client = registerTestIntegrationClient($actor);
    $service = app(ExternalRecordMappingService::class);

    $service->map($client, 'policy', 'BROKER-9003', (string) Str::uuid());

    expect(fn () => $service->map($client, 'policy', 'BROKER-9003', (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    expect(DB::table('external_record_mappings')->count())->toBe(1);
});

it('scopes the unique external id to the connection, not globally', function () {
    $actor = User::factory()->create();
    $clientA = registerTestIntegrationClient($actor, ['name' => 'Connector A']);
    $clientB = registerTestIntegrationClient($actor, ['name' => 'Connector B']);
    $service = app(ExternalRecordMappingService::class);

    $service->map($clientA, 'policy', 'SAME-EXTERNAL-ID', (string) Str::uuid());
    $mappingB = $service->map($clientB, 'policy', 'SAME-EXTERNAL-ID', (string) Str::uuid());

    expect($mappingB)->not->toBeNull();
    expect(DB::table('external_record_mappings')->count())->toBe(2);
});

it('flags a conflict without deleting the existing mapping', function () {
    $actor = User::factory()->create();
    $client = registerTestIntegrationClient($actor);
    $service = app(ExternalRecordMappingService::class);
    $mapping = $service->map($client, 'policy', 'BROKER-9004', (string) Str::uuid());

    $flagged = $service->flagConflict($mapping, 'PARTNER_CHANGED_FIELD_WE_OWN', ['field' => 'coverage_dates']);

    expect($flagged->synchronization_status)->toBe('CONFLICT');
    expect($flagged->conflict_status)->toBe('PARTNER_CHANGED_FIELD_WE_OWN');
    expect(DB::table('external_record_mappings')->where('id', $mapping->id)->exists())->toBeTrue();
});
