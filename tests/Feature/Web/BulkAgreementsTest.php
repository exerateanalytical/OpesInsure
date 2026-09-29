<?php

declare(strict_types=1);

/** S10 — bulk agreement setup (CSV → validate → DRAFT via CarrierBrokerAgreementService → submit → maker-checker activate) and the coverage matrix. */

use App\Application\CarrierOperations\Agreements\BulkAgreementImporter;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\Distribution\AgreementCoverage;
use App\Filament\Admin\Pages\Distribution\BulkAgreements;
use App\Models\Carrier;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function baUser(Tenant $t, array $permissions): User
{
    $u = User::create(['full_name' => 'BA '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $t->id, 'user_id' => $u->id, 'role_code' => 'COMPLIANCE_ADMIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $t->id, 'code' => 'BA-'.Str::random(6), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function baAs(User $u, Tenant $t): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($t->id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function baCsv(array $rows): UploadedFile
{
    $lines = [implode(',', array_keys($rows[0]))];
    foreach ($rows as $r) {
        $lines[] = implode(',', array_map(fn ($v) => '"'.$v.'"', $r));
    }

    return UploadedFile::fake()->createWithContent('agreements.csv', implode("\n", $lines));
}

beforeEach(function () {
    $this->t = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'Platform '.Str::random(6), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    app(TenantContext::class)->set($this->t->id);
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Activa Assurances', 'status' => 'ACTIVE']);
    $this->carrier = Carrier::create(['party_id' => $party->id, 'cima_code' => 'BA-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => []]);
    DB::table('carriers')->where('id', $this->carrier->id)->update(['brand_short_name' => 'ACTIVA', 'insurer_code' => 'ACT-CM']);
    $this->product = DB::table('insurance_products')->insertGetId(['id' => $pid = (string) Str::uuid(), 'carrier_id' => $this->carrier->id, 'line_code' => 'MOTOR', 'code' => 'AUTO-1', 'name' => 'Auto',
        'version' => 1, 'effective_from' => now()->subYear()->toDateString(), 'status' => 'ACTIVE', 'coverages' => '[]', 'eligibility_rules' => '{}', 'created_at' => now(), 'updated_at' => now()], 'id') ?: $pid;
    $this->broker = Partner::create(['tenant_id' => $this->t->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Douala Courtage', 'status' => 'ACTIVE'])->id,
        'type' => 'BROKER', 'status' => 'ACTIVE', 'licence_number' => 'LIC-777', 'compliance' => []]);
    $this->row = ['insurer' => 'activa', 'broker' => 'LIC-777', 'lines' => 'MOTOR', 'products' => '', 'commission' => 'MOTOR=15',
        'effective_from' => now()->subDay()->toDateString(), 'effective_until' => '', 'settlement_terms' => '30 days after month end', 'permissions' => 'quote;bind', 'source_document' => 'signed.pdf'];
});

it('gates the pages and actions exactly on the agreement API permissions', function () {
    baAs(baUser($this->t, ['claims.view']), $this->t);
    expect(BulkAgreements::canAccess())->toBeFalse()->and(AgreementCoverage::canAccess())->toBeFalse();

    baAs(baUser($this->t, ['distribution.agreements.view']), $this->t);
    expect(BulkAgreements::canAccess())->toBeTrue();
    Livewire::test(BulkAgreements::class)->assertOk()->assertDontSee(__('bulk_agreements.upload.heading'))->call('createDrafts')->assertForbidden();
    Livewire::test(AgreementCoverage::class)->assertOk()->assertSee('Douala Courtage');
});

it('previews row errors and refuses to create anything while errors remain', function () {
    baAs(baUser($this->t, ['distribution.agreements.view', 'distribution.agreements.manage']), $this->t);
    $bad = [...$this->row, 'insurer' => 'Nobody', 'commission' => 'HOME=10'];
    Livewire::test(BulkAgreements::class)->set('upload', baCsv([$this->row, $bad]))->call('preview')
        ->assertSee('Unknown insurer')->assertSee(__('bulk_agreements.errors.fix_first'))->call('createDrafts');
    expect(DB::table('carrier_broker_agreements')->count())->toBe(0);

    $checked = app(BulkAgreementImporter::class)->validate([$this->row, $this->row], ['carrier' => null, 'tenant' => null]);
    expect($checked[1]['errors'])->toContain(__('bulk_agreements.errors.duplicate_row', ['row' => 2]));
});

it('creates drafts, submits, and activates only by a different approver', function () {
    $maker = baUser($this->t, ['distribution.agreements.view', 'distribution.agreements.manage', 'distribution.agreements.approve']);
    baAs($maker, $this->t);
    Livewire::test(BulkAgreements::class)->set('upload', baCsv([$this->row]))->call('preview')->assertSee('Ready')->call('createDrafts')
        ->assertNotified(__('bulk_agreements.drafts_created', ['count' => 1]));
    $a = DB::table('carrier_broker_agreements')->first();
    expect($a->status)->toBe('DRAFT')->and($a->created_by)->toBe($maker->id)
        ->and(json_decode($a->settlement_terms, true))->toBe(['terms' => '30 days after month end']);
    $line = DB::table('carrier_broker_agreement_products')->where('agreement_id', $a->id)->first();
    expect($line->line_code)->toBe('MOTOR')->and((int) $line->commission_basis_points)->toBe(1500)->and((bool) $line->can_bind)->toBeTrue();

    $batch = DB::table('agreement_bulk_imports')->first();
    Livewire::test(BulkAgreements::class)->call('submitBatch', $batch->id)->set('reason', 'Signed contracts')->call('activateBatch', $batch->id);
    expect(DB::table('agreement_bulk_imports')->value('status'))->toBe('SUBMITTED')
        ->and(DB::table('carrier_broker_agreements')->value('status'))->toBe('DRAFT');

    baAs(baUser($this->t, ['distribution.agreements.view', 'distribution.agreements.approve']), $this->t);
    Livewire::test(BulkAgreements::class)->set('reason', 'Signed contracts')->call('activateBatch', $batch->id)->assertNotified(__('bulk_agreements.activated'));
    expect(DB::table('carrier_broker_agreements')->value('status'))->toBe('ACTIVE')->and(DB::table('agreement_bulk_imports')->value('status'))->toBe('ACTIVATED');

    $m = app(BulkAgreementImporter::class)->coverage(['carrier' => null, 'tenant' => null]);
    $row = collect($m['rows'])->firstWhere('id', $this->broker->id);
    expect($row['cells'][$this->carrier->id])->toBe('ACTIVE');
});

it('lists brokers and agents with zero sellable products and scopes insurer rows to the own carrier', function () {
    $m = app(BulkAgreementImporter::class)->coverage(['carrier' => null, 'tenant' => null]);
    expect(collect($m['rows'])->firstWhere('id', $this->broker->id)['sellable'])->toBe(0);

    $other = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Other Re', 'status' => 'ACTIVE'])->id, 'cima_code' => 'OT-'.Str::random(5), 'status' => 'ACTIVE', 'capabilities' => []]);
    $checked = app(BulkAgreementImporter::class)->validate([$this->row], ['carrier' => $other->id, 'tenant' => null]);
    expect($checked[0]['errors'])->toContain(__('bulk_agreements.errors.not_own_carrier'));
});

it('has matching EN and FR keys', function () {
    $flat = fn (array $a) => array_keys(\Illuminate\Support\Arr::dot($a));
    expect($flat(require lang_path('fr/bulk_agreements.php')))->toEqual($flat(require lang_path('en/bulk_agreements.php')));
});
