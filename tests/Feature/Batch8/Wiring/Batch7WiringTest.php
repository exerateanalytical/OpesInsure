<?php

declare(strict_types=1);

use App\Application\Certificates\CertificateService;
use App\Application\Identity\RoleCatalogue;
use App\Models\Carrier;
use App\Application\Policies\IssuanceQueue\IssuanceException;
use App\Models\Party;
use App\Models\StickerStock;
use App\Models\TenantMembership;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

it('grants the Batch 7 permissions to the existing catalogue roles', function (string $role, array $must, array $mustNot) {
    $perms = RoleCatalogue::defaultPermissions($role);
    foreach ($must as $p) {
        expect($perms)->toContain($p);
    }
    foreach ($mustNot as $p) {
        expect($perms)->not->toContain($p);
    }
})->with([
    'reinsurance officer' => ['REINSURANCE_OFFICER', ['reinsurance.reinsurers.manage', 'reinsurance.treaties.view', 'reinsurance.treaties.manage', 'reinsurance.treaties.approve', 'reinsurance.cessions.view', 'reinsurance.cessions.calculate'], []],
    'carrier super admin' => ['CARRIER_SUPER_ADMIN', ['reinsurance.treaties.approve', 'provider_tariffs.approve', 'coinsurance.approve', 'policies.issuance_queue.resolve', 'stickers.allocate.carrier'], []],
    'carrier admin' => ['CARRIER_ADMIN', ['providers.view', 'providers.manage', 'providers.credential', 'provider_networks.view', 'provider_networks.manage', 'policies.issuance_queue.view', 'policies.issuance_queue.manage', 'stickers.allocate.carrier', 'stickers.allocate'], ['provider_tariffs.approve', 'policies.issuance_queue.resolve', 'reinsurance.treaties.approve']],
    'carrier staff' => ['CARRIER_STAFF', ['policies.issuance_queue.view', 'stickers.view', 'providers.view'], ['stickers.allocate.carrier', 'policies.issuance_queue.manage']],
    'underwriter' => ['UNDERWRITER', ['coinsurance.view', 'coinsurance.manage', 'coinsurance.apportion'], ['coinsurance.approve']],
    'senior underwriter' => ['SENIOR_UNDERWRITER', ['coinsurance.manage', 'coinsurance.approve'], []],
    'finance officer' => ['FINANCE_OFFICER', ['policies.issuance_queue.view', 'policies.issuance_queue.manage', 'coinsurance.apportion'], ['policies.issuance_queue.resolve', 'coinsurance.approve']],
    'broker admin' => ['BROKER_ADMIN', ['stickers.allocate', 'stickers.reconcile', 'stickers.assign.any', 'policies.issuance_queue.view'], ['stickers.allocate.carrier']],
    'broker supervisor' => ['BROKER_SUPERVISOR', ['stickers.view', 'stickers.allocate', 'stickers.assign.any'], ['stickers.allocate.carrier']],
    'broker staff' => ['BROKER_STAFF', ['stickers.view', 'stickers.handover', 'stickers.assign'], ['stickers.assign.any', 'stickers.allocate']],
    'agent' => ['AGENT', ['stickers.view', 'stickers.handover', 'stickers.assign'], ['stickers.assign.any', 'stickers.allocate', 'reinsurance.treaties.view']],
    'branch manager' => ['BRANCH_MANAGER', ['stickers.allocate', 'stickers.reconcile', 'policies.issuance_queue.view'], ['stickers.assign.any']],
]);

it('keeps the wildcard roles covering the new permissions and every catalogued suggestion an existing role', function () {
    foreach (['FINANCE_MANAGER', 'CLAIMS_MANAGER', 'SYSTEM_ADMIN'] as $role) {
        expect(RoleCatalogue::defaultPermissions($role))->toBe(['*']);
    }
    foreach (['issuance_ops', 'providers', 'coinsurance', 'reinsurance'] as $category) {
        foreach (config("permissions.{$category}") as $code => $meta) {
            expect(array_diff($meta['suggested_roles'], RoleCatalogue::codes()))->toBe([], "{$code} suggests a role that does not exist");
        }
    }
});

it('schedules the hourly paid-not-issued scan, which records exceptions per tenant', function () {
    Artisan::call('list');
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'policies:scan-issuance-queue'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *');

    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['offer_id' => null, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF']]);
    makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED', 'reconciled_at' => now()->subHours(2), 'requested_by' => $f['user']->id]);

    $this->artisan('policies:scan-issuance-queue')->assertSuccessful();
    $this->artisan('policies:scan-issuance-queue')->assertSuccessful();
    expect(IssuanceException::where('proposal_id', $f['proposal']->id)->where('kind', 'PAID_NOT_ISSUED')->count())->toBe(1);
});

it('lets carrier-linked staff move stickers of their own carrier only', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $h = tenantHeaderFor($f['tenant']);
    $perms = ['stickers.view', 'stickers.handover', 'stickers.allocate', 'stickers.reconcile', 'stickers.assign', 'stickers.allocate.carrier'];
    $own = makeAuthTestUser($f['tenant'], $perms, 'CARRIER_ADMIN');
    $foreign = makeAuthTestUser($f['tenant'], $perms, 'CARRIER_ADMIN');
    $broker = makeAuthTestUser($f['tenant'], $perms, 'BROKER_ADMIN');
    $other = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Other Carrier', 'status' => 'ACTIVE'])->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
    TenantMembership::where('user_id', $own->id)->update(['carrier_id' => $f['carrier']->id]);
    TenantMembership::where('user_id', $foreign->id)->update(['carrier_id' => $other->id]);
    app(CertificateService::class)->receiveBatch(['carrier_id' => $f['carrier']->id, 'batch_number' => 'B-'.Str::random(6),
        'stickers' => array_map(fn ($i) => ['serial_number' => "W8-{$i}", 'security_code' => Str::random(24)], range(1, 3))], $own);
    $move = fn (array $serials) => $this->postJson('/api/v1/sticker-handovers', ['carrier_id' => $f['carrier']->id, 'from' => ['level' => 'CARRIER'], 'to' => ['level' => 'BROKER'], 'serial_numbers' => $serials], $h);

    Passport::actingAs($foreign);
    $move(['W8-1'])->assertForbidden();
    expect(StickerStock::where('status', 'IN_TRANSIT')->count())->toBe(0);

    Passport::actingAs($own);
    $ho = $move(['W8-1', 'W8-2'])->assertCreated()->json('data.id');

    // A carrier-linked user of another insurer cannot decide it either; the broker (not carrier-scoped) can.
    Passport::actingAs($foreign);
    $this->postJson("/api/v1/sticker-handovers/{$ho}/reject", ['reason' => 'not ours'], $h)->assertForbidden();
    Passport::actingAs($broker);
    $this->postJson("/api/v1/sticker-handovers/{$ho}/accept", [], $h)->assertOk()->assertJsonPath('data.status', 'ACCEPTED');

    Passport::actingAs($foreign);
    $this->postJson('/api/v1/sticker-reconciliations', ['carrier_id' => $f['carrier']->id, 'holder' => ['level' => 'BROKER'], 'counted_serials' => ['W8-1', 'W8-2']], $h)->assertForbidden();
});
