<?php

declare(strict_types=1);

use App\Filament\Admin\Resources;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

uses(RefreshDatabase::class);

/*
 * REQ-UI-002: every configuration / reference-data resource has a read-only
 * detail page (shared RecordInfolist: details, status & provenance, related,
 * audit & history) and list rows open it. Secrets stay masked.
 */

/** Minimal row for any table: NOT NULL columns get type-appropriate values; FKs are not enforced for the fixture insert. */
function viewPageFixture(string $resourceClass, string $tenantId, array $overrides = []): Model
{
    /** @var class-string<Model> $modelClass */
    $modelClass = $resourceClass::getModel();
    $model = new $modelClass;
    $table = $model->getTable();
    $cols = DB::select("SELECT column_name, data_type, udt_name, is_nullable, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?", [$table]);
    $allowed = [];
    foreach (DB::select("SELECT pg_get_constraintdef(c.oid) AS def FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE c.contype = 'c' AND t.relname = ?", [$table]) as $chk) {
        if (preg_match("/\(+(\w+)\)?(?:::\w+(?: \w+)?)?\s*=\s*ANY\s*\(+ARRAY\['([^']*)'/", $chk->def, $m)) {
            $allowed[$m[1]] ??= $m[2];
        }
    }
    $row = [];
    foreach ($cols as $c) {
        $name = $c->column_name;
        if (str_contains($name, 'tenant_id')) {
            $row[$name] = $tenantId;
            continue;
        }
        if ($c->is_nullable === 'YES' || $c->column_default !== null) {
            continue;
        }
        if (isset($allowed[$name])) {
            $row[$name] = $allowed[$name];
            continue;
        }
        $row[$name] = match (true) {
            $c->udt_name === 'uuid' => (string) Str::uuid(),
            in_array($c->udt_name, ['int2', 'int4', 'int8', 'numeric', 'float4', 'float8'], true) => 1,
            $c->udt_name === 'bool' => true,
            in_array($c->udt_name, ['json', 'jsonb'], true) => json_encode(['en' => 'Test', 'fr' => 'Test']),
            $c->udt_name === 'date' => '2026-01-01',
            str_starts_with($c->udt_name, 'time') && ! str_starts_with($c->udt_name, 'timestamp') => '08:00:00',
            str_starts_with($c->udt_name, 'timestamp') => '2026-01-01 00:00:00',
            $c->data_type === 'ARRAY' => '{}',
            default => 'T'.Str::upper(Str::random(5)),
        };
    }
    if (! isset($row[$model->getKeyName()]) && ! $model->getIncrementing()) {
        $row[$model->getKeyName()] = (string) Str::uuid();
    }
    $row = array_merge($row, $overrides);
    $id = DB::transaction(function () use ($table, $row, $model) {
        DB::statement('SET LOCAL session_replication_role = replica');
        $id = DB::table($table)->insertGetId($row, $model->getKeyName());
        DB::statement('SET LOCAL session_replication_role = DEFAULT');

        return $id;
    });

    return $modelClass::query()->withoutGlobalScopes()->findOrFail($id);
}

dataset('config view pages', [
    Resources\ApprovalMatrixRules\ApprovalMatrixRuleResource::class,
    Resources\BrokerMasterDataMappings\BrokerMasterDataMappingResource::class,
    Resources\BusinessHours\BusinessHoursResource::class,
    Resources\CalendarExceptions\CalendarExceptionResource::class,
    Resources\CancellationRules\CancellationRuleResource::class,
    Resources\CarrierMasterDataMappings\CarrierMasterDataMappingResource::class,
    Resources\CertificateTemplates\CertificateTemplateResource::class,
    Resources\CimaAuthorities\CimaAuthorityResource::class,
    Resources\CimaCompulsoryInsurance\CimaCompulsoryInsuranceResource::class,
    Resources\CimaInsurerAuthorizations\CimaInsurerAuthorizationResource::class,
    Resources\CimaLegalReferences\CimaLegalReferenceResource::class,
    Resources\CimaMicroBranches\CimaMicroBranchResource::class,
    Resources\CimaProductMappings\CimaProductMappingResource::class,
    Resources\CimaReportingCategories\CimaReportingCategoryResource::class,
    Resources\CimaReportingMappings\CimaReportingMappingResource::class,
    Resources\CoverageDefinitions\CoverageDefinitionResource::class,
    Resources\Devices\DeviceResource::class,
    Resources\DisclosureSchemas\DisclosureSchemaResource::class,
    Resources\DocumentClassApplicability\DocumentClassApplicabilityResource::class,
    Resources\DocumentIssuanceProfiles\DocumentIssuanceProfileResource::class,
    Resources\DocumentNumberingFamilies\DocumentNumberingFamilyResource::class,
    Resources\DocumentPackItems\DocumentPackItemResource::class,
    Resources\DocumentRequirements\DocumentRequirementResource::class,
    Resources\DocumentStatusChanges\DocumentStatusChangeResource::class,
    Resources\ExclusionDefinitions\ExclusionDefinitionResource::class,
    Resources\InstitutionDirectory\InstitutionProfileResource::class,
    Resources\InstitutionDirectory\InstitutionVerificationLabelResource::class,
    Resources\InsuranceLines\InsuranceLineResource::class,
    Resources\IntegrationDeliveryAttempts\IntegrationDeliveryAttemptResource::class,
    Resources\Invitations\InvitationResource::class,
    Resources\MasterDataAliases\MasterDataAliasResource::class,
    Resources\MasterDataChanges\MasterDataChangeResource::class,
    Resources\MasterDataDomains\MasterDataDomainResource::class,
    Resources\MasterDataImports\MasterDataImportResource::class,
    Resources\MasterDataLists\MasterDataListResource::class,
    Resources\MasterDataMergeRequests\MasterDataMergeRequestResource::class,
    Resources\MasterDataReviews\MasterDataReviewResource::class,
    Resources\MasterDataTenantOverrides\MasterDataTenantOverrideResource::class,
    Resources\MasterDataValues\MasterDataValueResource::class,
    Resources\Memberships\MembershipResource::class,
    Resources\PaymentConnections\PaymentConnectionResource::class,
    Resources\PhysicalSecurityAssets\PhysicalSecurityAssetResource::class,
    Resources\ProductDocumentRequirements\ProductDocumentRequirementResource::class,
    Resources\RiskAssets\RiskAssetResource::class,
    Resources\StickerBatches\StickerBatchResource::class,
    Resources\StickerInventory\StickerInventoryResource::class,
    Resources\TariffVersions\TariffVersionResource::class,
    Resources\VehicleGenerations\VehicleGenerationResource::class,
    Resources\VehicleMakes\VehicleMakeResource::class,
    Resources\VehicleModels\VehicleModelResource::class,
    Resources\VehicleReferenceValues\VehicleReferenceValueResource::class,
    Resources\VehicleVariants\VehicleVariantResource::class,
    Resources\VehicleMasterChanges\VehicleMasterChangeResource::class,
    Resources\VehicleMasterReviews\VehicleMasterReviewResource::class,
]);

it('renders the detail page for an admin and list rows link to it', function (string $resource) {
    $tenant = makeAuthTestTenant('views');
    $tenant->update(['type' => 'PLATFORM']); // payment connections are platform-tenant only (security 2026-09-29)
    $admin = makeAuthTestSystemAdmin($tenant);
    $this->actingAs($admin, 'web');
    app(\App\Domain\Tenancy\TenantContext::class)->set($tenant->id);

    $overrides = [
        Resources\BusinessHours\BusinessHoursResource::class => ['opens' => '08:00', 'closes' => '17:00'],
        // CHECK ((carrier_id IS NULL) <> (partner_id IS NULL))
        Resources\InstitutionDirectory\InstitutionProfileResource::class => ['carrier_id' => (string) Str::uuid()],
    ][$resource] ?? [];
    $record = viewPageFixture($resource, $tenant->id, $overrides);
    if ($resource === Resources\StickerBatches\StickerBatchResource::class) {
        // Batches are listed to the custodian holding their stock.
        viewPageFixture(Resources\StickerInventory\StickerInventoryResource::class, $tenant->id, ['sticker_batch_id' => $record->getKey()]);
    }

    expect($resource::hasPage('view'))->toBeTrue();
    $this->get($resource::getUrl('view', ['record' => $record]))
        ->assertOk()
        ->assertSee('Status &amp; provenance', false)
        ->assertSee('Audit &amp; history', false);
})->with('config view pages');

it('never renders payment connection secrets on the detail page', function () {
    $tenant = makeAuthTestTenant('secret');
    $tenant->update(['type' => 'PLATFORM']); // payment connections are platform-tenant only (security 2026-09-29)
    $this->actingAs(makeAuthTestSystemAdmin($tenant), 'web');
    app(\App\Domain\Tenancy\TenantContext::class)->set($tenant->id);
    $record = viewPageFixture(Resources\PaymentConnections\PaymentConnectionResource::class, $tenant->id, ['credential_reference' => 'vault://super-secret-ref-123']);

    $this->get(Resources\PaymentConnections\PaymentConnectionResource::getUrl('view', ['record' => $record]))
        ->assertOk()->assertDontSee('super-secret-ref-123')->assertSee('(masked)');
});
