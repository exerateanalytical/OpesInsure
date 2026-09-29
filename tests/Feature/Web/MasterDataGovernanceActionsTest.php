<?php

declare(strict_types=1);

/**
 * UI coverage batches 19 (master data / vehicle & fiscal power) and 21 (document governance, legacy migration):
 * the staff desktop actions call the same services, with the same permissions, as the API routes; maker-checker
 * refusals from the services are shown as a failure notification.
 */

use App\Application\Vehicles\Power\FiscalPowerService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\DocumentDestructionRequests;
use App\Filament\Admin\Pages\DocumentIntakeQueue;
use App\Filament\Admin\Pages\FiscalPowerConflicts;
use App\Filament\Admin\Pages\FiscalPowerRecords;
use App\Filament\Admin\Pages\LegalHolds;
use App\Filament\Admin\Pages\RetentionSchedules;
use App\Filament\Admin\Pages\SignatureRequests;
use App\Filament\Admin\Pages\StampDutySchedules;
use App\Filament\Admin\Pages\TransportLicences;
use App\Filament\Admin\Resources\BrokerMasterDataMappings\Pages\ListBrokerMasterDataMappings;
use App\Filament\Admin\Resources\CarrierMasterDataMappings\Pages\ListCarrierMasterDataMappings;
use App\Filament\Admin\Resources\LegacyMigrations\LegacyMigrationResource;
use App\Filament\Admin\Resources\LegacyMigrations\Pages\ListLegacyMigrations;
use App\Filament\Admin\Resources\LegacyMigrations\Pages\ViewLegacyMigration;
use App\Filament\Admin\Resources\MasterDataLists\Pages\ViewMasterDataList;
use App\Filament\Admin\Resources\MasterDataReviews\Pages\ListMasterDataReviews;
use App\Filament\Admin\Resources\VehicleVariants\Pages\ViewVehicleVariant;
use App\Models\Document;
use App\Models\Import\ImportBatch;
use App\Models\IntegrationClient;
use App\Models\MasterData\MasterDataDomain;
use App\Models\MasterData\MasterDataList;
use App\Models\MasterData\MasterDataValue;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleVariant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

const MDGA_VP = ['vehicle_power.view', 'vehicle_power.manage', 'vehicle_power.fiscal.submit', 'vehicle_power.fiscal.verify', 'vehicle_power.stamp_duty.manage', 'vehicle_power.stamp_duty.approve'];
const MDGA_DOCS = ['documents.read', 'documents.intake.manage', 'documents.retention.manage', 'documents.retention.approve', 'documents.legal_hold.manage',
    'documents.destruction.request', 'documents.destruction.approve', 'documents.signatures.manage'];
const MDGA_MD = ['master_data.overrides.manage', 'master_data.mappings.manage'];
const MDGA_LM_MAKER = ['legacy_migration.manage'];
const MDGA_LM_CHECKER = ['legacy_migration.manage', 'legacy_migration.approve', 'legacy_migration.commit'];

function mdgaUser(Tenant $tenant, array $permissions, string $roleCode): User
{
    $u = User::create(['full_name' => 'MDGA '.$roleCode.' '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    // COMPLIANCE_ADMIN membership opens the admin panel (and the master-data screens); actions come only from the role's permissions.
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $u->id, 'role_code' => 'COMPLIANCE_ADMIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenant->id, 'code' => $roleCode.'-'.Str::random(6), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function mdgaAs(User $u, Tenant $tenant): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenant->id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function mdgaDoc(Tenant $tenant, array $overrides = []): Document
{
    return Document::create(array_merge([
        'tenant_id' => $tenant->id, 'category' => 'INCOMING', 'storage_key' => 'documents/mdga/'.Str::random(12).'.pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => 1024, 'sha256' => hash('sha256', Str::random(20)), 'scan_status' => 'CLEAN', 'verification_status' => 'UNVERIFIED', 'ocr_data' => [],
    ], $overrides));
}

function mdgaVariant(): VehicleVariant
{
    $make = VehicleMake::create(['code' => 'MD_'.Str::upper(Str::random(5)), 'name' => 'Make', 'normalized_name' => 'MAKE'.Str::random(4), 'provenance' => 'MANUAL_VERIFIED']);
    $model = VehicleModel::create(['code' => $make->code.'_M', 'make_id' => $make->id, 'name' => 'Model', 'normalized_name' => 'MODEL', 'provenance' => 'MANUAL_VERIFIED']);

    return VehicleVariant::forceCreate(['id' => (string) Str::uuid(), 'model_id' => $model->id, 'code' => $make->code.'_V1', 'name' => '2.0 petrol', 'engine_capacity_cc' => 1998, 'provenance' => 'MANUAL_VERIFIED', 'active' => true]);
}

function mdgaList(): MasterDataList
{
    $code = 'mdga_'.Str::lower(Str::random(5));
    $domain = MasterDataDomain::create(['code' => $code, 'label_en' => 'MDGA domain', 'label_fr' => 'Domaine MDGA']);

    return MasterDataList::create(['domain_id' => $domain->id, 'domain_code' => $code, 'code' => 'items', 'label_en' => 'Items', 'label_fr' => 'Éléments']);
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(10, 0));
    $this->tenant = makeAuthTestTenant('MDGA');
    app(TenantContext::class)->set($this->tenant->id);
    $all = [...MDGA_VP, ...MDGA_DOCS, ...MDGA_MD];
    $this->maker = mdgaUser($this->tenant, [...$all, ...MDGA_LM_MAKER], 'MDGA_MAKER');
    $this->checker = mdgaUser($this->tenant, [...$all, ...MDGA_LM_CHECKER], 'MDGA_CHECKER');
    $this->reader = mdgaUser($this->tenant, ['claims.view'], 'MDGA_READER');
});

it('shows the screens and actions with the permission and hides them without it', function () {
    $variant = mdgaVariant();
    $list = mdgaList();

    mdgaAs($this->reader, $this->tenant);
    foreach ([DocumentIntakeQueue::class, RetentionSchedules::class, LegalHolds::class, DocumentDestructionRequests::class, SignatureRequests::class,
        FiscalPowerRecords::class, FiscalPowerConflicts::class, StampDutySchedules::class, TransportLicences::class] as $page) {
        expect($page::canAccess())->toBeFalse();
    }
    expect(LegacyMigrationResource::canViewAny())->toBeFalse();
    $v = Livewire::test(ViewVehicleVariant::class, ['record' => $variant->id])->assertOk();
    foreach (['vpRecordPower', 'vpSubmitFiscal', 'vpReportConflict', 'vpVerifyVariant'] as $a) {
        $v->assertActionHidden($a);
    }
    Livewire::test(ViewMasterDataList::class, ['record' => $list->id])->assertOk()->assertActionHidden('mdAddPrivateValue');
    Livewire::test(ListCarrierMasterDataMappings::class)->assertOk()->assertActionHidden('mdMapForCarrier');
    Livewire::test(ListBrokerMasterDataMappings::class)->assertOk()->assertActionHidden('mdMapForBroker');

    mdgaAs($this->maker, $this->tenant);
    foreach ([DocumentIntakeQueue::class, RetentionSchedules::class, LegalHolds::class, DocumentDestructionRequests::class, SignatureRequests::class,
        FiscalPowerRecords::class, FiscalPowerConflicts::class, StampDutySchedules::class, TransportLicences::class] as $page) {
        expect($page::canAccess())->toBeTrue();
        Livewire::test($page)->assertOk();
    }
    expect(LegacyMigrationResource::canViewAny())->toBeTrue();
    Livewire::test(ListLegacyMigrations::class)->assertOk()->assertActionVisible('lmStage');
    $v = Livewire::test(ViewVehicleVariant::class, ['record' => $variant->id])->assertOk();
    foreach (['vpRecordPower', 'vpSubmitFiscal', 'vpReportConflict', 'vpVerifyVariant'] as $a) {
        $v->assertActionVisible($a);
    }
    Livewire::test(ViewMasterDataList::class, ['record' => $list->id])->assertActionVisible('mdAddPrivateValue');
    Livewire::test(ListMasterDataReviews::class)->assertActionVisible('mdSuggest');
    Livewire::test(ListCarrierMasterDataMappings::class)->assertActionVisible('mdMapForCarrier');
    Livewire::test(ListBrokerMasterDataMappings::class)->assertActionVisible('mdMapForBroker');
});

it('drives technical power, fiscal power maker-checker and a conflict resolution on the variant', function () {
    $variant = mdgaVariant();
    mdgaAs($this->maker, $this->tenant);
    Livewire::test(ViewVehicleVariant::class, ['record' => $variant->id])
        ->callAction('vpRecordPower', ['power_source_value' => 110, 'power_source_unit' => 'KW'])->assertNotified(__('masterdata_actions.vpRecordPower.done'));
    expect(DB::table('vehicle_power_specs')->where('variant_id', $variant->id)->exists())->toBeTrue();

    Livewire::test(ViewVehicleVariant::class, ['record' => $variant->id])
        ->callAction('vpSubmitFiscal', ['fiscal_power_cv' => 9, 'source_type' => 'CIVIC', 'source_reference' => 'CIVIC-1'])->assertNotified(__('masterdata_actions.vpSubmitFiscal.done'));
    $rec = DB::table('vehicle_fiscal_power_records')->where('variant_id', $variant->id)->first();
    expect($rec->review_state)->toBe('PENDING_REVIEW');

    // Maker-checker: the capturer cannot verify their own value.
    Livewire::test(ViewVehicleVariant::class, ['record' => $variant->id])
        ->callAction('vpVerifyVariant', ['record_id' => $rec->id])->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('vehicle_fiscal_power_records')->where('id', $rec->id)->value('review_state'))->toBe('PENDING_REVIEW');

    mdgaAs($this->checker, $this->tenant);
    Livewire::test(ViewVehicleVariant::class, ['record' => $variant->id])
        ->callAction('vpVerifyVariant', ['record_id' => $rec->id])->assertNotified(__('masterdata_actions.vpVerifyVariant.done'));
    expect(DB::table('vehicle_fiscal_power_records')->where('id', $rec->id)->value('review_state'))->toBe('VERIFIED');

    mdgaAs($this->maker, $this->tenant);
    Livewire::test(ViewVehicleVariant::class, ['record' => $variant->id])
        ->callAction('vpReportConflict', ['fiscal_power_cv' => 11, 'source_type' => 'CAMEROON_AUTHORITY_DATA', 'source_reference' => 'DGI-99'])->assertNotified(__('masterdata_actions.vpReportConflict.done'));
    $conflict = DB::table('vehicle_fiscal_power_conflicts')->where('status', 'OPEN')->first();
    expect($conflict)->not->toBeNull();

    mdgaAs($this->checker, $this->tenant);
    Livewire::test(FiscalPowerConflicts::class)->assertCanSeeTableRecords([$conflict->id])
        ->callTableAction('vpResolveConflict', $conflict->id, ['accept_challenger' => true, 'reason' => 'Authority data confirmed'])
        ->assertNotified(__('masterdata_actions.vpResolveConflict.done'));
    expect(DB::table('vehicle_fiscal_power_conflicts')->where('id', $conflict->id)->value('status'))->toBe('RESOLVED')
        ->and(app(FiscalPowerService::class)->currentFor($variant->id, null)['fiscal_power_cv'])->toBe(11);
});

it('captures, sources, verifies and rejects registration fiscal power; schedules and licences are maker-checker', function () {
    mdgaAs($this->maker, $this->tenant);
    Livewire::test(FiscalPowerRecords::class)
        ->callTableAction('vpSubmitRecord', null, ['registration_number' => 'LT 1 A', 'fiscal_power_cv' => 16])->assertNotified(__('masterdata_actions.vpSubmitRecord.done'));
    $draft = DB::table('vehicle_fiscal_power_records')->where('registration_number', 'LT1A')->first();
    expect($draft->review_state)->toBe('DRAFT');
    Livewire::test(FiscalPowerRecords::class)
        ->callTableAction('vpAttachSource', $draft->id, ['source_type' => 'CIVIC', 'source_reference' => 'CIVIC-LT1A'])->assertNotified(__('masterdata_actions.vpAttachSource.done'));
    expect(DB::table('vehicle_fiscal_power_records')->where('id', $draft->id)->value('review_state'))->toBe('PENDING_REVIEW');
    Livewire::test(FiscalPowerRecords::class)
        ->callTableAction('vpSubmitRecord', null, ['registration_number' => 'CE 2 B', 'fiscal_power_cv' => 7, 'source_type' => 'CIVIC', 'source_reference' => 'CIVIC-CE2B'])->assertNotified();
    $other = DB::table('vehicle_fiscal_power_records')->where('registration_number', 'CE2B')->first();

    mdgaAs($this->checker, $this->tenant);
    Livewire::test(FiscalPowerRecords::class)->callTableAction('vpVerifyRecord', $draft->id, [])->assertNotified(__('masterdata_actions.vpVerifyRecord.done'));
    Livewire::test(FiscalPowerRecords::class)->callTableAction('vpRejectRecord', $other->id, ['reason' => 'Unreadable document'])->assertNotified(__('masterdata_actions.vpRejectRecord.done'));
    expect(DB::table('vehicle_fiscal_power_records')->where('id', $draft->id)->value('review_state'))->toBe('VERIFIED')
        ->and(DB::table('vehicle_fiscal_power_records')->where('id', $other->id)->value('review_state'))->toBe('REJECTED');

    // Stamp duty schedule: maker drafts a version, a different user approves it.
    mdgaAs($this->maker, $this->tenant);
    $rates = collect(app(\App\Application\Vehicles\Power\FiscalPowerBands::class)->all())->mapWithKeys(fn ($b) => [$b->code => 50000])->all();
    Livewire::test(StampDutySchedules::class)
        ->callTableAction('vpScheduleVersion', null, ['schedule_code' => 'OTHER_VEHICLES', 'rates_xaf' => $rates, 'effective_from' => '2027-01-01', 'legal_reference' => 'LF 2027 art. 1'])
        ->assertNotified(__('masterdata_actions.vpScheduleVersion.done'));
    $schedule = DB::table('vehicle_stamp_duty_rate_schedules')->where('legal_reference', 'LF 2027 art. 1')->first();
    expect($schedule->status)->toBe('DRAFT');
    Livewire::test(StampDutySchedules::class)->callTableAction('vpScheduleApprove', $schedule->id)->assertNotified(__('workflow_actions.failed'));
    mdgaAs($this->checker, $this->tenant);
    Livewire::test(StampDutySchedules::class)->callTableAction('vpScheduleApprove', $schedule->id)->assertNotified(__('masterdata_actions.vpScheduleApprove.done'));
    expect(DB::table('vehicle_stamp_duty_rate_schedules')->where('id', $schedule->id)->value('status'))->toBe('APPROVED');

    // Transport licence: the recorder cannot mark it VALID; another user can.
    mdgaAs($this->maker, $this->tenant);
    Livewire::test(TransportLicences::class)
        ->callTableAction('vpRecordLicence', null, ['registration_number' => 'CE 9 T', 'licence_number' => 'TL-9', 'valid_from' => '2026-01-01'])->assertNotified(__('masterdata_actions.vpRecordLicence.done'));
    $lic = DB::table('vehicle_transport_licences')->where('tenant_id', $this->tenant->id)->first();
    Livewire::test(TransportLicences::class)->callTableAction('vpDecideLicence', $lic->id, ['status' => 'VALID'])->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('vehicle_transport_licences')->where('id', $lic->id)->value('status'))->toBe('PENDING');
    mdgaAs($this->checker, $this->tenant);
    Livewire::test(TransportLicences::class)->callTableAction('vpDecideLicence', $lic->id, ['status' => 'VALID'])->assertNotified(__('masterdata_actions.vpDecideLicence.done'));
    expect(DB::table('vehicle_transport_licences')->where('id', $lic->id)->value('status'))->toBe('VALID');
});

it('adds a private value and records a staff suggestion through the master-data services', function () {
    $list = mdgaList();
    mdgaAs($this->maker, $this->tenant);
    Livewire::test(ViewMasterDataList::class, ['record' => $list->id])
        ->callAction('mdAddPrivateValue', ['label_en' => 'Zzq private value '.Str::random(4)])->assertNotified(__('masterdata_actions.mdAddPrivateValue.done'));
    expect(MasterDataValue::where('tenant_id', $this->tenant->id)->where('list_id', $list->id)->exists())->toBeTrue();

    $before = DB::table('master_data_review_queue')->count();
    Livewire::test(ListMasterDataReviews::class)
        ->callAction('mdSuggest', ['list' => $list->domain_code.'|'.$list->code, 'text' => 'Qwxz suggested '.Str::random(5)])->assertNotified(__('masterdata_actions.mdSuggest.done'));
    expect(DB::table('master_data_review_queue')->count())->toBe($before + 1);
});

it('runs document intake, retention, legal hold, destruction and signature workflows with maker-checker', function () {
    mdgaAs($this->maker, $this->tenant);
    $known = mdgaDoc($this->tenant);
    $suggested = mdgaDoc($this->tenant);
    $junk = mdgaDoc($this->tenant);
    Livewire::test(DocumentIntakeQueue::class)
        ->callTableAction('dgIntakeReceive', null, ['document_id' => $known->id, 'channel' => 'EMAIL', 'declared_type_code' => 'DOC-151'])->assertHasNoTableActionErrors()->assertNotified(__('masterdata_actions.dgIntakeReceive.done'));
    Livewire::test(DocumentIntakeQueue::class)
        ->callTableAction('dgIntakeReceive', null, ['document_id' => $suggested->id, 'channel' => 'UPLOAD', 'original_filename' => 'accident_report_scan.pdf'])->assertHasNoTableActionErrors()->assertNotified();
    Livewire::test(DocumentIntakeQueue::class)
        ->callTableAction('dgIntakeReceive', null, ['document_id' => $junk->id, 'channel' => 'POST', 'original_filename' => 'zzqx_8812.pdf'])->assertHasNoTableActionErrors()->assertNotified();
    $items = DB::table('document_intake_items')->where('tenant_id', $this->tenant->id)->get()->keyBy('document_id');
    expect($items[$known->id]->status)->toBe('CLASSIFIED')->and($items[$suggested->id]->status)->toBe('RECEIVED');
    Livewire::test(DocumentIntakeQueue::class)
        ->assertTableActionVisible('dgIntakeClassify', $items[$suggested->id]->id)->callTableAction('dgIntakeClassify', $items[$suggested->id]->id, ['document_type_code' => 'ACCIDENT_REPORT'])->assertHasNoTableActionErrors()->assertNotified(__('masterdata_actions.dgIntakeClassify.done'));
    Livewire::test(DocumentIntakeQueue::class)
        ->assertTableActionVisible('dgIntakeReject', $items[$junk->id]->id)->callTableAction('dgIntakeReject', $items[$junk->id]->id, ['reason' => 'Not insurance related'])->assertHasNoTableActionErrors()->assertNotified(__('masterdata_actions.dgIntakeReject.done'));
    expect(DB::table('document_intake_items')->where('id', $items[$suggested->id]->id)->value('status'))->toBe('CLASSIFIED')
        ->and(DB::table('document_intake_items')->where('id', $items[$junk->id]->id)->value('status'))->toBe('REJECTED');

    // Retention schedule: drafter cannot approve (visible to them only because they also hold approve here → refused).
    Livewire::test(RetentionSchedules::class)
        ->callTableAction('dgRetentionDraft', null, ['code' => 'EVD-10Y', 'document_type_code' => 'ACCIDENT_REPORT', 'retention_years' => 10, 'trigger_event' => 'CREATED_AT', 'legal_basis' => 'Owner decision'])
        ->assertHasNoTableActionErrors()->assertNotified(__('masterdata_actions.dgRetentionDraft.done'));
    $schedule = DB::table('retention_schedules')->where('code', 'EVD-10Y')->first();
    Livewire::test(RetentionSchedules::class)->assertTableActionVisible('dgRetentionApprove', $schedule->id)->callTableAction('dgRetentionApprove', $schedule->id)->assertNotified(__('workflow_actions.failed'));
    mdgaAs($this->checker, $this->tenant);
    Livewire::test(RetentionSchedules::class)->assertTableActionVisible('dgRetentionApprove', $schedule->id)->callTableAction('dgRetentionApprove', $schedule->id)->assertNotified(__('masterdata_actions.dgRetentionApprove.done'));
    expect(DB::table('retention_schedules')->where('id', $schedule->id)->value('status'))->toBe('ACTIVE');

    // Legal hold place / release.
    $party = Party::create(['type' => 'PERSON', 'display_name' => 'Held Person', 'status' => 'ACTIVE']);
    Livewire::test(LegalHolds::class)
        ->callTableAction('dgHoldPlace', null, ['subject_type' => 'PARTY', 'subject_id' => $party->id, 'reason_code' => 'LITIGATION', 'notes' => 'Court case'])->assertHasNoTableActionErrors()->assertNotified(__('masterdata_actions.dgHoldPlace.done'));
    $hold = DB::table('legal_holds')->where('subject_id', $party->id)->first();
    // The person who placed the hold cannot release it.
    Livewire::test(LegalHolds::class)->callTableAction('dgHoldRelease', $hold->id, ['reason' => 'self'])->assertNotified(__('workflow_actions.failed'));
    mdgaAs($this->maker, $this->tenant);
    Livewire::test(LegalHolds::class)->assertTableActionVisible('dgHoldRelease', $hold->id)->callTableAction('dgHoldRelease', $hold->id, ['reason' => 'Case settled'])->assertHasNoTableActionErrors()->assertNotified(__('masterdata_actions.dgHoldRelease.done'));
    expect(DB::table('legal_holds')->where('id', $hold->id)->value('released_at'))->not->toBeNull();

    // Destruction: requester ≠ decider.
    $old = mdgaDoc($this->tenant, ['document_type_code' => 'ACCIDENT_REPORT']);
    DB::table('documents')->where('id', $old->id)->update(['created_at' => now()->subYears(12)]);
    mdgaAs($this->maker, $this->tenant);
    Livewire::test(DocumentDestructionRequests::class)
        ->callTableAction('dgDestructionRequest', null, ['document_id' => $old->id, 'reason' => 'Retention elapsed'])->assertHasNoTableActionErrors()->assertNotified(__('masterdata_actions.dgDestructionRequest.done'));
    $req = DB::table('document_destruction_requests')->where('document_id', $old->id)->first();
    Livewire::test(DocumentDestructionRequests::class)->assertTableActionVisible('dgDestructionDecide', $req->id)->callTableAction('dgDestructionDecide', $req->id, ['approve' => true, 'note' => 'self'])->assertHasNoTableActionErrors()->assertNotified(__('workflow_actions.failed'));
    mdgaAs($this->checker, $this->tenant);
    Livewire::test(DocumentDestructionRequests::class)->assertTableActionVisible('dgDestructionDecide', $req->id)->callTableAction('dgDestructionDecide', $req->id, ['approve' => false, 'note' => 'Keep for audit'])
        ->assertHasNoTableActionErrors()->assertNotified(__('masterdata_actions.dgDestructionDecide.done'));
    expect(DB::table('document_destruction_requests')->where('id', $req->id)->value('status'))->toBe('REJECTED');

    // Signature request / cancel.
    $signer = mdgaUser($this->tenant, [], 'MDGA_SIGNER');
    $toSign = mdgaDoc($this->tenant, ['document_origin' => 'SYSTEM']);
    Livewire::test(SignatureRequests::class)
        ->callTableAction('dgSignatureRequest', null, ['document_id' => $toSign->id, 'consent_text' => 'I agree to sign electronically.',
            'signers' => [['user_id' => $signer->id, 'name' => 'Signer', 'role' => 'POLICYHOLDER', 'order' => 1]]])
        ->assertHasNoTableActionErrors()->assertNotified(__('masterdata_actions.dgSignatureRequest.done'));
    $sig = DB::table('signature_requests')->where('document_id', $toSign->id)->first();
    expect($sig->status)->toBe('PENDING');
    Livewire::test(SignatureRequests::class)->assertTableActionVisible('dgSignatureCancel', $sig->id)->callTableAction('dgSignatureCancel', $sig->id, ['reason' => 'Wrong version'])->assertHasNoTableActionErrors()->assertNotified(__('masterdata_actions.dgSignatureCancel.done'));
    expect(DB::table('signature_requests')->where('id', $sig->id)->value('status'))->toBe('CANCELLED');
});

it('stages, maps, validates, dry-runs, reconciles, submits, approves and commits a legacy batch; rejects and rolls back others', function () {
    $source = IntegrationClient::create(['name' => 'LEGACY-ASSUR', 'client_id' => 'legacy-'.Str::random(8), 'client_secret_hash' => 'x', 'scopes' => ['migration'], 'status' => 'ACTIVE']);
    // "name" instead of "display_name": the batch needs an explicit column mapping.
    $csv = "legacy_id;type;name;date_of_birth;phone;email\nC1;INDIVIDUAL;Jean Mbarga;1980-02-01;+237690000001;jean@example.cm\nC2;ORGANIZATION;SARL Douala Fret;;;\n";
    $p = app(\App\Application\Import\Legacy\LegacyMigrationPipeline::class);
    $path = tempnam(sys_get_temp_dir(), 'mdga').'.csv';

    mdgaAs($this->maker, $this->tenant);
    // Staging from the screen goes through LegacyMigrationPipeline::stage (Livewire's test upload keeps the name and size, not the bytes,
    // so this staged batch is empty and waits for mapping); it is then discarded from its detail page.
    Livewire::test(ListLegacyMigrations::class)
        ->callAction('lmStage', ['entity' => 'legacy.customers', 'integration_client_id' => $source->id, 'file' => UploadedFile::fake()->createWithContent('customers.csv', $csv), 'control_count' => 2])
        ->assertNotified(__('masterdata_actions.lmStage.done'));
    $b0 = ImportBatch::where('tenant_id', $this->tenant->id)->where('pipeline', 'LEGACY')->latest()->firstOrFail();
    expect($b0->status)->toBe('UPLOADED')->and($b0->filename)->toBe('customers.csv')->and($b0->created_by)->toBe($this->maker->id)->and($b0->control_totals)->toBe(['count' => 2]);
    Livewire::test(ViewLegacyMigration::class, ['record' => $b0->id])->callAction('lmRollback', ['reason' => 'Re-staging'])->assertNotified(__('masterdata_actions.lmRollback.done'));
    expect($b0->fresh()->status)->toBe('ROLLED_BACK');

    file_put_contents($path, $csv);
    $b = $p->stage('legacy.customers', $source->id, $path, 'customers.csv', $this->maker, $this->tenant->id, [], ['count' => 2]);
    expect($b->status)->toBe('UPLOADED');

    $page = fn () => Livewire::test(ViewLegacyMigration::class, ['record' => $b->id]);
    $page()->callAction('lmMap', ['mapping' => ['display_name' => 'name']])->assertNotified(__('masterdata_actions.lmMap.done'));
    expect($b->fresh()->status)->toBe('VALIDATED');
    $page()->callAction('lmValidate')->assertNotified(__('masterdata_actions.lmValidate.done'));
    $page()->callAction('lmDryRun')->assertNotified(__('masterdata_actions.lmDryRun.done'));
    expect($b->fresh()->status)->toBe('DRY_RUN_OK');
    $page()->callAction('lmReconcile')->assertNotified(__('masterdata_actions.lmReconcile.done'));
    expect($b->fresh()->status)->toBe('RECONCILED');
    $page()->assertActionHidden('lmApprove')->callAction('lmSubmit', ['reason' => 'Go live'])->assertNotified(__('masterdata_actions.lmSubmit.done'));
    expect($b->fresh()->status)->toBe('PENDING_APPROVAL');
    // The maker does not hold legacy_migration.approve: the checker step is hidden for them.
    $page()->assertActionHidden('lmApprove')->assertActionHidden('lmCommit');

    mdgaAs($this->checker, $this->tenant);
    $page()->callAction('lmApprove', ['note' => 'Checked totals'])->assertNotified(__('masterdata_actions.lmApprove.done'));
    expect($b->fresh()->status)->toBe('APPROVED');
    $page()->callAction('lmCommit')->assertNotified(__('masterdata_actions.lmCommit.done'));
    expect($b->fresh()->status)->toBe('COMMITTED')->and($b->fresh()->imported_count)->toBe(2);

    // A second batch is rejected by the checker.
    file_put_contents($path, "legacy_id;type;display_name;date_of_birth;phone;email\nC9;INDIVIDUAL;Paul Etoa;1970-01-01;+237690000009;paul@example.cm\n");
    $b2 = $p->submit($p->reconcile($p->dryRun($p->stage('legacy.customers', $source->id, $path, 'c9.csv', $this->maker, $this->tenant->id), $this->maker)), $this->maker);
    $page2 = fn () => Livewire::test(ViewLegacyMigration::class, ['record' => $b2->id]);
    $page2()->callAction('lmReject', ['note' => 'Wrong source'])->assertNotified(__('masterdata_actions.lmReject.done'));
    expect($b2->fresh()->status)->toBe('REJECTED');
});
