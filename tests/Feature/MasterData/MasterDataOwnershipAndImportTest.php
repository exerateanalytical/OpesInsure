<?php

declare(strict_types=1);

/*
 | Batch 3B — REQ-MDM-006 (tenant overrides, private values, carrier/broker mappings), REQ-MDM-007 (duplicates,
 | maker-checker merge, import/export, version history), REQ-MDM-009 (screens), REQ-DUP-013 (one suggestion path),
 | REQ-IMP-001 (one generic import pipeline: upload → map → validate → duplicates → preview → approve → import → audit).
 */

use App\Application\Approvals\ApprovalService;
use App\Application\Import\ImportPipeline;
use App\Application\Import\ImportTargetRegistry;
use App\Application\MasterData\MasterDataCatalogue;
use App\Application\MasterData\MasterDataMergeService;
use App\Application\MasterData\MasterDataOverrideService;
use App\Application\MasterData\MasterDataSearch;
use App\Application\Vehicles\VehicleMasterAdminService;
use App\Domain\Tenancy\TenantContext;
use App\Models\ApprovalRequest;
use App\Models\Import\ImportBatch;
use App\Models\MasterData\MasterDataChange;
use App\Models\MasterData\MasterDataList;
use App\Models\MasterData\MasterDataValue;
use App\Models\Vehicles\VehicleGeneration;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function b3bList(string $domain = 'b3b', string $list = 'things'): MasterDataList
{
    $domainId = DB::table('master_data_domains')->where('code', $domain)->value('id') ?? tap((string) Str::uuid(), fn ($id) => DB::table('master_data_domains')
        ->insert(['id' => $id, 'code' => $domain, 'label_en' => 'B3B', 'label_fr' => 'B3B']));

    return MasterDataList::firstOrCreate(['domain_code' => $domain, 'code' => $list], ['domain_id' => $domainId, 'label_en' => 'Things', 'label_fr' => 'Choses']);
}

function b3bValue(MasterDataList $l, string $code, string $en, ?string $fr = null, array $extra = []): MasterDataValue
{
    return MasterDataValue::create(['list_id' => $l->id, 'domain_code' => $l->domain_code, 'list_code' => $l->code, 'code' => $code, 'label_en' => $en, 'label_fr' => $fr ?? $en] + $extra);
}

function b3bFile(string $ext, string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'b3b').'.'.$ext;
    file_put_contents($path, $content);

    return $path;
}

function b3bAdmin(array $permissions = ['imports.create', 'imports.approve', 'master_data.overrides.manage', 'master_data.merge.request', 'master_data.merge.approve'])
{
    return makeAuthTestUser(test()->tenant, $permissions);
}

beforeEach(function () {
    $this->tenant = makeAuthTestTenant('B3B');
    app(TenantContext::class)->set($this->tenant->id);
});

// ---------------------------------------------------------------- REQ-IMP-001

it('runs the generic pipeline: upload → auto-map → validate → duplicates → preview → submit → approve → import → audit', function () {
    $l = b3bList();
    b3bValue($l, 'HAMMER', 'Hammer', 'Marteau');
    $maker = b3bAdmin();
    $checker = b3bAdmin();
    $csv = b3bFile('csv', "\xEF\xBB\xBFcode;label_en;label_fr;aliases\nSAW;Saw;Scie;Handsaw|Egoïne\n;Screw driver;Tournevis;\nHAMMER;Hammer;Marteau;\n;Hammer;Marteau bis;\n");

    $batch = app(ImportPipeline::class)->upload('master_data_values', ['domain' => 'b3b', 'list' => 'things'], $csv, 'tools.csv', $maker, [], $this->tenant->id);
    expect($batch->status)->toBe('VALIDATED')
        ->and($batch->report['new'])->toBe(['SAW', 'SCREW_DRIVER'])
        ->and(collect($batch->report['duplicates'])->pluck('matches')->all())->toBe(['HAMMER', 'HAMMER'])
        ->and($batch->report['preview'][0]['label_en'])->toBe('Saw')
        ->and($batch->file_sha256)->toHaveLength(64);

    $batch = app(ImportPipeline::class)->submit($batch, $maker, 'Tool list from supplier');
    expect($batch->status)->toBe('PENDING_APPROVAL')->and(MasterDataValue::where('code', 'SAW')->exists())->toBeFalse();

    // Maker cannot approve their own import.
    expect(fn () => app(ImportPipeline::class)->approve($batch, $maker))->toThrow(ValidationException::class);

    $batch = app(ImportPipeline::class)->approve($batch->fresh(), $checker, 'ok');
    expect($batch->status)->toBe('IMPORTED')->and($batch->imported_count)->toBe(2)->and($batch->approved_by)->toBe($checker->id)
        ->and(MasterDataValue::where(['list_id' => $l->id, 'code' => 'SAW'])->value('source_type'))->toBe('MANUAL_VERIFIED')
        ->and(app(MasterDataSearch::class)->search('b3b', 'things', 'egoine')[0]['code'])->toBe('SAW')
        ->and(MasterDataChange::where(['action' => 'CREATED', 'source' => 'IMPORT'])->count())->toBe(2)
        ->and(ApprovalRequest::find($batch->approval_request_id)->status)->toBe('APPROVED');
    expect(DB::table('audit_log')->where('subject_type', 'import_batch')->where('subject_id', $batch->id)->pluck('action')->all())
        ->toContain('import.uploaded', 'import.validated', 'import.submitted', 'import.imported');
})->group('REQ-IMP-001', 'REQ-MDM-007');

it('reads XLSX and JSON, asks for a mapping when required columns are missing, and never overwrites', function () {
    $l = b3bList();
    $json = b3bFile('json', json_encode([['Name EN' => 'Drill', 'Nom FR' => 'Perceuse', 'aliases' => ['Power drill']]]));
    $p = app(ImportPipeline::class);
    $batch = $p->upload('master_data_values', ['domain' => 'b3b', 'list' => 'things'], $json, 'drill.json', null, [], $this->tenant->id);
    expect($batch->status)->toBe('UPLOADED')->and($batch->report['needs_mapping'])->toBe(['label_en', 'label_fr'])
        ->and($batch->source_columns)->toBe(['name en', 'nom fr', 'aliases']);
    $batch = $p->map($batch, ['label_en' => 'Name EN', 'label_fr' => 'nom fr']);
    expect($batch->status)->toBe('VALIDATED')->and($batch->report['new'])->toBe(['DRILL'])->and($batch->rows[0]['aliases'])->toBe('Power drill');
    expect(fn () => $p->map($batch, ['label_en' => 'missing column']))->toThrow(ValidationException::class);

    $xlsx = tempnam(sys_get_temp_dir(), 'b3b').'.xlsx';
    $w = new OpenSpout\Writer\XLSX\Writer;
    $w->openToFile($xlsx);
    $w->addRow(OpenSpout\Common\Entity\Row::fromValues(['code', 'label_en', 'label_fr']));
    $w->addRow(OpenSpout\Common\Entity\Row::fromValues(['LEVEL', 'Spirit level', 'Niveau']));
    $w->addRow(OpenSpout\Common\Entity\Row::fromValues(['bad code!', 'X', 'X']));
    $w->close();
    $x = $p->upload('master_data_values', ['domain' => 'b3b', 'list' => 'things'], $xlsx, 'levels.xlsx', null, [], $this->tenant->id);
    expect($x->format)->toBe('xlsx')->and($x->status)->toBe('FAILED')->and($x->report['errors'][0]['row'])->toBe(2);
    expect(fn () => $p->submit($x, b3bAdmin()))->toThrow(ValidationException::class);

    expect(fn () => $p->upload('master_data_values', ['domain' => 'b3b', 'list' => 'nope'], $json, 'x.json', null))->toThrow(ValidationException::class)
        ->and(fn () => $p->upload('master_data_values', ['domain' => 'b3b', 'list' => 'things'], $json, 'x.exe', null))->toThrow(ValidationException::class)
        ->and(fn () => $p->upload('unknown_target', [], $json, 'x.json', null))->toThrow(ValidationException::class);
})->group('REQ-IMP-001');

it('rejects and cancels through the approval engine, and approval from the generic inbox imports', function () {
    b3bList();
    $maker = b3bAdmin();
    $checker = b3bAdmin();
    $p = app(ImportPipeline::class);
    $up = fn (string $name) => $p->upload('master_data_values', ['domain' => 'b3b', 'list' => 'things'], b3bFile('csv', "label_en,label_fr\n$name,$name FR\n"), "$name.csv", $maker, [], $this->tenant->id);

    $rejected = $p->reject($p->submit($up('Clamp'), $maker), $checker, 'Not verified');
    expect($rejected->status)->toBe('REJECTED')->and(MasterDataValue::where('code', 'CLAMP')->exists())->toBeFalse();

    $cancelled = $p->cancel($p->submit($up('Vice'), $maker), $maker, 'Wrong list');
    expect($cancelled->status)->toBe('CANCELLED')->and(ApprovalRequest::find($cancelled->approval_request_id)->status)->toBe('CANCELLED');

    $viaInbox = $p->submit($up('Chisel'), $maker);
    app(ApprovalService::class)->approve(ApprovalRequest::find($viaInbox->approval_request_id), $checker, 'inbox');
    expect($viaInbox->fresh()->status)->toBe('IMPORTED')->and(MasterDataValue::where('code', 'CHISEL')->exists())->toBeTrue();
    expect(fn () => ImportBatch::find($viaInbox->id)->delete())->toThrow(LogicException::class);
})->group('REQ-IMP-001');

it('imports vehicle generations and variants through the same pipeline with the vehicle admin rules', function () {
    $make = VehicleMake::create(['code' => 'B3B_TOYOTA', 'name' => 'B3B Toyota', 'normalized_name' => 'B3BTOYOTA', 'provenance' => 'MANUAL_VERIFIED']);
    $model = VehicleModel::create(['code' => 'B3B_TOYOTA_COROLLA', 'make_id' => $make->id, 'name' => 'Corolla', 'normalized_name' => 'COROLLA', 'provenance' => 'MANUAL_VERIFIED']);
    app(VehicleMasterAdminService::class)->createGeneration($model, ['name' => 'E170', 'year_from' => 2013, 'year_to' => 2019], null);
    $maker = b3bAdmin();
    $checker = b3bAdmin();
    $p = app(ImportPipeline::class);

    $gen = $p->upload('vehicle_generations', [], b3bFile('csv', "make,model,name,year_from,year_to\nB3B Toyota,Corolla,E210,2019,\nB3B_TOYOTA,corolla,E170,2013,2019\nB3B Toyota,Corolla,E120,2003,1999\nNoSuchMake,X,Y,,\n"), 'gens.csv', $maker, [], $this->tenant->id);
    expect($gen->status)->toBe('FAILED')->and($gen->report['new'])->toBe(['B3B_TOYOTA_COROLLA/E210'])
        ->and($gen->report['duplicates'][0]['row'])->toBe(2)->and(count($gen->report['errors']))->toBe(2)
        ->and(VehicleGeneration::where('model_id', $model->id)->count())->toBe(1); // dry-run left nothing behind

    $gen = $p->upload('vehicle_generations', [], b3bFile('csv', "make,model,name,year_from\nB3B Toyota,Corolla,E210,2019\n"), 'gens2.csv', $maker, [], $this->tenant->id);
    $p->approve($p->submit($gen, $maker), $checker);
    expect(VehicleGeneration::where(['model_id' => $model->id, 'name' => 'E210'])->value('year_from'))->toBe(2019);

    $var = $p->upload('vehicle_variants', [], b3bFile('csv', "make,model,generation,name,engine_capacity_cc,transmission\nB3B Toyota,Corolla,E210,1.8 Hybrid,1798,\nB3B Toyota,Corolla,,1.6 base,99999,\n"), 'vars.csv', $maker, [], $this->tenant->id);
    expect($var->status)->toBe('FAILED')->and($var->report['errors'][0]['error'])->toContain('engine_capacity_cc');
    $var = $p->upload('vehicle_variants', [], b3bFile('csv', "make,model,generation,name,engine_capacity_cc\nB3B Toyota,Corolla,E210,1.8 Hybrid,1798\n"), 'vars2.csv', $maker, [], $this->tenant->id);
    $p->approve($p->submit($var, $maker), $checker);
    expect(VehicleVariant::where(['model_id' => $model->id, 'name' => '1.8 Hybrid'])->first()->generation_id)->not->toBeNull()
        ->and(DB::table('vehicle_master_changes')->where('action', 'CREATED')->count())->toBeGreaterThanOrEqual(3);
    expect(array_keys(app(ImportTargetRegistry::class)->options()))->toContain('master_data_values', 'vehicle_generations', 'vehicle_variants');
})->group('REQ-IMP-001', 'REQ-VEH-001');

it('exposes the pipeline over the API, tenant-scoped and permission-gated', function () {
    b3bList();
    $maker = b3bAdmin(['imports.create']);
    $checker = b3bAdmin(['imports.create', 'imports.approve']);
    Passport::actingAs($maker);
    $file = UploadedFile::fake()->createWithContent('things.csv', "label_en,label_fr\nMallet,Maillet\n");
    $res = $this->postJson('/api/v1/imports', ['target' => 'master_data_values', 'params' => ['domain' => 'b3b', 'list' => 'things'], 'file' => $file], tenantHeader($this->tenant))
        ->assertCreated()->assertJsonPath('data.status', 'VALIDATED')->assertJsonPath('data.summary.new', 1);
    $id = $res->json('data.id');
    $this->getJson('/api/v1/imports/targets', tenantHeader($this->tenant))->assertOk()->assertJsonFragment(['key' => 'vehicle_variants']);
    $this->postJson("/api/v1/imports/$id/submit", [], tenantHeader($this->tenant))->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');
    $this->postJson("/api/v1/imports/$id/approve", [], tenantHeader($this->tenant))->assertForbidden();

    Passport::actingAs($checker);
    $this->postJson("/api/v1/imports/$id/approve", ['note' => 'fine'], tenantHeader($this->tenant))->assertOk()->assertJsonPath('data.status', 'IMPORTED');

    $other = makeAuthTestTenant('Other');
    Passport::actingAs(makeAuthTestUser($other, ['imports.create']));
    $this->getJson("/api/v1/imports/$id", tenantHeader($other))->assertNotFound();
})->group('REQ-IMP-001');

// ---------------------------------------------------------------- REQ-MDM-006

it('applies tenant overrides (hide, alias, internal code) and private values without changing meaning', function () {
    $l = b3bList();
    $hammer = b3bValue($l, 'HAMMER', 'Hammer', 'Marteau');
    $saw = b3bValue($l, 'SAW', 'Saw', 'Scie');
    $other = b3bValue($l, 'OTHER', 'Other', 'Autre', ['is_other' => true]);
    $svc = app(MasterDataOverrideService::class);
    $version = DB::table('master_data_domains')->where('code', 'b3b')->value('catalog_version');

    $svc->setOverride($this->tenant->id, $saw, 'HIDE', null, null);
    $svc->setOverride($this->tenant->id, $hammer, 'ALIAS', 'Masse', null);
    $svc->setOverride($this->tenant->id, $hammer, 'INTERNAL_CODE', 'TL-001', null);
    $private = $svc->addPrivateValue($this->tenant->id, 'b3b', 'things', ['label_en' => 'Crowbar', 'label_fr' => 'Pied-de-biche'], null);
    expect(fn () => $svc->setOverride($this->tenant->id, $other, 'HIDE', null, null))->toThrow(ValidationException::class)
        ->and(fn () => $svc->setOverride($this->tenant->id, $hammer, 'RENAME', 'x', null))->toThrow(ValidationException::class)
        ->and(fn () => $svc->addPrivateValue($this->tenant->id, 'b3b', 'things', ['label_en' => 'hammer'], null))->toThrow(ValidationException::class);

    $mine = collect(app(MasterDataSearch::class)->search('b3b', 'things', null, null, $this->tenant->id));
    expect($mine->pluck('code')->all())->not->toContain('SAW')->toContain('HAMMER', $private->code)
        ->and($mine->firstWhere('code', 'HAMMER')['internal_code'])->toBe('TL-001')
        ->and(app(MasterDataSearch::class)->search('b3b', 'things', 'masse', null, $this->tenant->id)[0]['code'])->toBe('HAMMER');
    // Other tenants and the platform see the canonical list unchanged.
    $theirs = collect(app(MasterDataSearch::class)->search('b3b', 'things', null, null, makeAuthTestTenant('X')->id));
    expect($theirs->pluck('code')->all())->toContain('SAW')->not->toContain($private->code)
        ->and($hammer->fresh()->label_en)->toBe('Hammer')
        ->and(DB::table('master_data_domains')->where('code', 'b3b')->value('catalog_version'))->toBeGreaterThan($version)
        ->and(MasterDataChange::where('entity_type', 'TENANT_OVERRIDE')->count())->toBe(3);
})->group('REQ-MDM-006');

it('serves overrides, private values and carrier/broker mappings over the API', function () {
    $l = b3bList();
    $hammer = b3bValue($l, 'HAMMER', 'Hammer', 'Marteau');
    $admin = b3bAdmin(['master_data.overrides.manage', 'master_data.mappings.manage']);
    Passport::actingAs($admin);
    $h = tenantHeader($this->tenant);
    $id = $this->postJson('/api/v1/master-data/overrides', ['value_id' => $hammer->id, 'action' => 'ALIAS', 'text' => 'Masse'], $h)->assertCreated()->json('data.id');
    $this->getJson('/api/v1/master-data/overrides?domain=b3b', $h)->assertOk()->assertJsonPath('data.0.alias', 'Masse');
    $this->postJson('/api/v1/master-data/b3b/things/private-values', ['label_en' => 'Crowbar'], $h)->assertCreated()->assertJsonPath('data.source_type', 'USER_SUBMITTED');
    $this->deleteJson("/api/v1/master-data/overrides/$id", [], $h)->assertOk();
    expect(DB::table('master_data_tenant_overrides')->count())->toBe(0);
    // A plain tenant admin is neither an insurer nor a broker user.
    $this->putJson('/api/v1/master-data/carrier-mappings', ['value_id' => $hammer->id, 'external_code' => 'X1'], $h)->assertForbidden();
    $this->putJson('/api/v1/master-data/broker-mappings', ['value_id' => $hammer->id, 'external_code' => 'X1'], $h)->assertForbidden();
    // Public catalogue reads still resolve (literal admin paths do not shadow {domain}).
    $this->getJson('/api/v1/master-data/b3b/things')->assertOk();

    Passport::actingAs(b3bAdmin([]));
    $this->postJson('/api/v1/master-data/overrides', ['value_id' => $hammer->id, 'action' => 'HIDE'], $h)->assertForbidden();
})->group('REQ-MDM-006');

it('maps canonical values for carriers and brokers with version history', function () {
    $l = b3bList();
    $hammer = b3bValue($l, 'HAMMER', 'Hammer', 'Marteau');
    $svc = app(MasterDataOverrideService::class);
    $carrierId = (string) Str::uuid();
    $m = $svc->mapForCarrier($carrierId, $hammer, 'RC-3', 'Risk class 3', 'risk_class', null);
    $m2 = $svc->mapForCarrier($carrierId, $hammer, 'RC-4', null, 'RISK_CLASS', null);
    expect($m2->id)->toBe($m->id)->and($m2->external_code)->toBe('RC-4');
    $b = $svc->mapForBroker((string) Str::uuid(), $hammer, 'BRK-9', null, null);
    $svc->deactivateMapping($b, null);
    expect($b->fresh()->status)->toBe('INACTIVE')
        ->and(MasterDataChange::whereIn('entity_type', ['CARRIER_MAPPING', 'BROKER_MAPPING'])->pluck('action')->sort()->values()->all())->toBe(['MAPPING_CREATED', 'MAPPING_CREATED', 'MAPPING_DEACTIVATED', 'MAPPING_UPDATED']);
})->group('REQ-MDM-006');

// ---------------------------------------------------------------- REQ-MDM-007

it('detects duplicates and merges only after a second admin approves', function () {
    $l = b3bList();
    $a = b3bValue($l, 'SCREWDRIVER', 'Screwdriver', 'Tournevis');
    $b = b3bValue($l, 'SCREW_DRIVER', 'screwdriver ', 'Tournevis cruciforme');
    $groups = app(MasterDataMergeService::class)->duplicateGroups();
    expect(collect($groups)->firstWhere('label', 'screwdriver')['values'])->toHaveCount(2);

    $maker = b3bAdmin();
    $checker = b3bAdmin();
    $svc = app(MasterDataMergeService::class);
    $mr = $svc->request($b, $a, $maker, 'same tool');
    expect($mr->status)->toBe('PENDING')->and($b->fresh()->status)->toBe('ACTIVE')
        ->and(fn () => $svc->request($b, $a, $maker))->toThrow(ValidationException::class)
        ->and(fn () => $svc->approveRequest($mr, $maker))->toThrow(ValidationException::class);

    $svc->approveRequest($mr, $checker, 'ok');
    expect($mr->fresh()->status)->toBe('MERGED')->and($b->fresh()->status)->toBe('INACTIVE')->and($b->fresh()->merged_into_id)->toBe($a->id)
        ->and(app(MasterDataCatalogue::class)->value('b3b', 'things', 'SCREW_DRIVER')['code'])->toBe('SCREWDRIVER')
        ->and(MasterDataValue::whereKey($b->id)->exists())->toBeTrue();

    $c = b3bValue($l, 'DRIVER', 'Driver', 'Pilote');
    $r2 = $svc->request($c, $a, $maker);
    app(ApprovalService::class)->reject(ApprovalRequest::find($r2->approval_request_id), $checker, 'different');
    expect($r2->fresh()->status)->toBe('REJECTED')->and($c->fresh()->status)->toBe('ACTIVE');
})->group('REQ-MDM-007');

it('requests and decides merges over the API', function () {
    $l = b3bList();
    $a = b3bValue($l, 'PLIERS', 'Pliers', 'Pince');
    $b = b3bValue($l, 'PLIER', 'Pliers', 'Pinces');
    Passport::actingAs(b3bAdmin(['master_data.merge.request']));
    $h = tenantHeader($this->tenant);
    $this->getJson('/api/v1/master-data/duplicates', $h)->assertOk()->assertJsonPath('data.0.label', 'pliers');
    $id = $this->postJson('/api/v1/master-data/merges', ['from_value_id' => $b->id, 'into_value_id' => $a->id], $h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/master-data/merges/$id/decision", ['decision' => 'APPROVED'], $h)->assertForbidden();
    Passport::actingAs(b3bAdmin(['master_data.merge.approve']));
    $this->postJson("/api/v1/master-data/merges/$id/decision", ['decision' => 'APPROVED'], $h)->assertOk()->assertJsonPath('data.status', 'MERGED');
})->group('REQ-MDM-007');

// ---------------------------------------------------------------- REQ-DUP-013 / REQ-MDM-009

it('keeps one canonical suggestion path; the old paths are deprecated aliases of master-data/suggestions', function () {
    $routes = collect(Route::getRoutes()->getRoutes());
    $suggest = $routes->filter(fn ($r) => str_ends_with($r->getActionName(), 'MasterDataController@suggest'));
    $canonical = $suggest->reject(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_contains($m, 'DeprecatedRouteAlias')));
    expect($canonical->map->uri()->values()->all())->toBe(['api/v1/master-data/suggestions']);
    $vehicle = $routes->first(fn ($r) => $r->uri() === 'api/v1/mobile/vehicles/master-review');
    expect(collect($vehicle->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_contains($m, 'DeprecatedRouteAlias')))->toBeTrue();
})->group('REQ-DUP-013');

it('registers the MDM staff screens (MDM-001…020) for master-data admins only', function () {
    $screens = [
        App\Filament\Admin\Pages\MasterDataDashboard::class, App\Filament\Admin\Pages\MasterDataQuality::class,
        App\Filament\Admin\Resources\MasterDataDomains\MasterDataDomainResource::class, App\Filament\Admin\Resources\MasterDataLists\MasterDataListResource::class,
        App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource::class, App\Filament\Admin\Resources\MasterDataAliases\MasterDataAliasResource::class,
        App\Filament\Admin\Resources\MasterDataReviews\MasterDataReviewResource::class, App\Filament\Admin\Resources\MasterDataMergeRequests\MasterDataMergeRequestResource::class,
        App\Filament\Admin\Resources\MasterDataImports\MasterDataImportResource::class, App\Filament\Admin\Resources\CarrierMasterDataMappings\CarrierMasterDataMappingResource::class,
        App\Filament\Admin\Resources\BrokerMasterDataMappings\BrokerMasterDataMappingResource::class, App\Filament\Admin\Resources\MasterDataChanges\MasterDataChangeResource::class,
        App\Filament\Admin\Resources\MasterDataTenantOverrides\MasterDataTenantOverrideResource::class,
    ];
    foreach ($screens as $class) {
        expect(class_exists($class))->toBeTrue($class);
    }
    $this->actingAs(b3bAdmin());
    expect(App\Filament\Admin\Resources\MasterDataMergeRequests\MasterDataMergeRequestResource::canViewAny())->toBeFalse();
    $this->actingAs(makeAuthTestUser($this->tenant, [], 'PLATFORM_ADMIN'));
    expect(App\Filament\Admin\Resources\MasterDataMergeRequests\MasterDataMergeRequestResource::canViewAny())->toBeTrue()
        ->and(App\Filament\Admin\Resources\MasterDataImports\MasterDataImportResource::canCreate())->toBeFalse();
})->group('REQ-MDM-009');

it('renders the import, merge and override screens and drives an import from the Filament table', function () {
    $l = b3bList();
    $maker = makeAuthTestUser($this->tenant, [], 'PLATFORM_ADMIN');
    $checker = makeAuthTestUser($this->tenant, [], 'PLATFORM_ADMIN');
    $batch = app(ImportPipeline::class)->upload('master_data_values', ['domain' => 'b3b', 'list' => 'things'], b3bFile('csv', "label_en,label_fr\nAnvil,Enclume\n"), 'anvil.csv', $maker, [], $this->tenant->id);
    $this->actingAs($maker);
    Livewire\Livewire::test(App\Filament\Admin\Resources\MasterDataImports\Pages\ListMasterDataImports::class)
        ->assertCanSeeTableRecords([$batch])->callTableAction('submit', $batch, ['reason' => 'x'])->assertHasNoTableActionErrors();
    expect($batch->fresh()->status)->toBe('PENDING_APPROVAL');
    Livewire\Livewire::test(App\Filament\Admin\Resources\MasterDataImports\Pages\ListMasterDataImports::class)->assertTableActionHidden('approve', $batch);

    $this->actingAs($checker);
    Livewire\Livewire::test(App\Filament\Admin\Resources\MasterDataImports\Pages\ListMasterDataImports::class)
        ->callTableAction('approve', $batch->fresh(), ['note' => 'ok'])->assertHasNoTableActionErrors();
    expect($batch->fresh()->status)->toBe('IMPORTED');

    Livewire\Livewire::test(App\Filament\Admin\Resources\MasterDataMergeRequests\Pages\ListMasterDataMergeRequests::class)->assertOk();
    Livewire\Livewire::test(App\Filament\Admin\Resources\MasterDataTenantOverrides\Pages\ListMasterDataTenantOverrides::class)->assertOk();
    Livewire\Livewire::test(App\Filament\Admin\Resources\MasterDataValues\Pages\ListMasterDataValues::class)->filterTable('possible_duplicates')->assertOk();
})->group('REQ-MDM-009', 'REQ-IMP-001');
