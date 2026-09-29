<?php

declare(strict_types=1);

/**
 * Q1 queue-based malware scanning: uploads are stored first and never lost while ClamAV is missing, held
 * (SCAN_UNAVAILABLE, not attachable), then scanned by documents:rescan-pending the moment the scanner answers —
 * CLEAN files get the attachment the customer already asked for, INFECTED files are quarantined and notified.
 * Nothing is ever CLEAN without a real scan.
 */

use App\Application\Documents\Adapters\FailClosedMalwareScanAdapter;
use App\Application\Documents\Adapters\MalwareScanAdapter;
use App\Application\Documents\Adapters\ScanResult;
use App\Application\Documents\Scanning\MalwareScannerHealth;
use App\Filament\Admin\Pages\DocumentsUnderwriting\UploadScanning;
use App\Models\Document;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

const Q1_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** Scanner up/down switch for the test: the adapter verdict plus the health probe documents:rescan-pending uses. */
function q1Scanner(?string $verdict): void
{
    app()->bind(MalwareScanAdapter::class, fn () => $verdict === null ? new FailClosedMalwareScanAdapter : new class($verdict) implements MalwareScanAdapter
    {
        public function __construct(private string $verdict) {}

        public function scan(string $absolutePath, string $declaredMimeType): ScanResult
        {
            return match ($this->verdict) {
                'CLEAN' => ScanResult::clean(),
                'INFECTED' => ScanResult::infected('stream: Eicar-Test-Signature FOUND'),
                default => ScanResult::failed('scanner error'),
            };
        }
    });
    app()->instance(MalwareScannerHealth::class, new class($verdict !== null) extends MalwareScannerHealth
    {
        public function __construct(private bool $up) {}

        public function status(): array
        {
            return ['configured' => $this->up, 'reachable' => $this->up, 'endpoint' => $this->up ? 'tcp://127.0.0.1:3310' : null, 'version' => $this->up ? 'ClamAV 1.4.1' : null, 'error' => $this->up ? null : 'down'];
        }
    });
}

/** Customer uploads a photo and asks for it to be attached to their claim. @return array{0: array, 1: Document, 2: \Illuminate\Testing\TestResponse, 3: object} */
function q1UploadAndAttach($test): array
{
    Storage::fake('local');
    $f = makeMobileCustomerFixture('+2376700'.random_int(10000, 99999));
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party']);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    $upload = $test->postJson('/api/v1/mobile/documents', ['category' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/png', 'file_base64' => Q1_PNG], $h)->assertStatus(201);
    $attach = $test->postJson("/api/v1/mobile/claims/{$claim->id}/evidence", ['document_id' => $upload->json('data.id'), 'evidence_type' => 'DAMAGE_PHOTO', 'purpose' => 'CLAIM_EVIDENCE'], $h);

    return [$f, Document::findOrFail($upload->json('data.id')), $attach, $claim];
}

function q1Staff(Tenant $tenant, array $permissions, string $roleCode = 'CLAIMS_MANAGER'): User
{
    $user = User::factory()->create(['status' => 'ACTIVE', 'locale' => 'en']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $roleCode, 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $roleCode], ['id' => (string) Str::uuid(), 'permissions' => $permissions, 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

it('holds an upload as SCAN_UNAVAILABLE while the scanner is down: stored, not attachable, not downloadable, attachment remembered', function () {
    q1Scanner(null);
    [$f, $doc, $attach, $claim] = q1UploadAndAttach($this);

    expect($doc->scan_status)->toBe('SCAN_UNAVAILABLE');
    Storage::disk('local')->assertExists($doc->storage_key);
    $attach->assertStatus(202);
    expect($attach->json('data.status'))->toBe('PENDING_SECURITY_CHECK')
        ->and($attach->json('data.message'))->toContain('Security check in progress');
    expect(DB::table('claim_documents')->where('claim_id', $claim->id)->count())->toBe(0);
    expect(DB::table('document_pending_attachments')->where('document_id', $doc->id)->value('status'))->toBe('PENDING');
    $this->postJson("/api/v1/mobile/documents/{$doc->id}/access", ['purpose' => 'VIEW'], tenantHeaderFor($f['tenant']))->assertStatus(422);

    // The scheduled rescan while the scanner is still down spends no attempt and never releases the file.
    $this->artisan('documents:rescan-pending')->assertSuccessful();
    expect($doc->refresh()->scan_status)->toBe('SCAN_UNAVAILABLE');
    expect(DB::table('document_scan_queue')->where('document_id', $doc->id)->value('next_attempt_at'))->toBeNull();
});

it('scans and auto-attaches held claim evidence once the scanner comes up', function () {
    q1Scanner(null);
    [$f, $doc, $attach, $claim] = q1UploadAndAttach($this);
    $attach->assertStatus(202);

    q1Scanner('CLEAN');
    $this->artisan('documents:rescan-pending')->assertSuccessful();

    expect($doc->refresh()->scan_status)->toBe('CLEAN');
    expect(DB::table('claim_documents')->where(['claim_id' => $claim->id, 'document_id' => $doc->id])->value('evidence_type'))->toBe('DAMAGE_PHOTO');
    expect(DB::table('document_pending_attachments')->where('document_id', $doc->id)->value('status'))->toBe('ATTACHED');
    expect(DB::table('document_scan_queue')->where('document_id', $doc->id)->first())->status->toBe('CLEAN')->scanned_at->not->toBeNull();
    expect(DB::table('user_notifications')->where('user_id', $f['user']->id)->where('title', 'File attached')->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('action', 'document.scan.clean')->where('subject_id', $doc->id)->exists())->toBeTrue();

    // Now visible in the customer's evidence list and downloadable.
    $this->getJson("/api/v1/mobile/claims/{$claim->id}/evidence", tenantHeaderFor($f['tenant']))->assertOk();
    $this->postJson("/api/v1/mobile/documents/{$doc->id}/access", ['purpose' => 'VIEW'], tenantHeaderFor($f['tenant']))->assertOk();
});

it('attaches immediately when the scanner is up at upload time', function () {
    q1Scanner('CLEAN');
    [, $doc, $attach, $claim] = q1UploadAndAttach($this);

    $attach->assertStatus(201);
    expect($doc->scan_status)->toBe('CLEAN');
    expect(DB::table('claim_documents')->where('claim_id', $claim->id)->count())->toBe(1);
});

it('quarantines an infected file, cancels the pending attachment and notifies the uploader and document reviewers', function () {
    q1Scanner(null);
    [$f, $doc, , $claim] = q1UploadAndAttach($this);
    $reviewer = q1Staff($f['tenant'], ['documents.review'], 'UNDERWRITER');

    q1Scanner('INFECTED');
    $this->artisan('documents:rescan-pending')->assertSuccessful();

    expect($doc->refresh()->scan_status)->toBe('INFECTED');
    $row = DB::table('document_scan_queue')->where('document_id', $doc->id)->first();
    expect($row->quarantined_at)->not->toBeNull()->and($row->verdict)->toContain('Eicar')->and($row->notified_at)->not->toBeNull();
    expect(DB::table('document_pending_attachments')->where('document_id', $doc->id)->value('status'))->toBe('CANCELLED');
    expect(DB::table('claim_documents')->where('claim_id', $claim->id)->count())->toBe(0);
    expect(DB::table('user_notifications')->where('user_id', $f['user']->id)->where('title', 'File rejected')->exists())->toBeTrue();
    expect(DB::table('user_notifications')->where('user_id', $reviewer->id)->where('title', 'Infected upload quarantined')->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('action', 'document.scan.infected')->where('subject_id', $doc->id)->exists())->toBeTrue();

    // A later rescan never revisits a quarantined file.
    q1Scanner('CLEAN');
    $this->artisan('documents:rescan-pending', ['--now' => true])->assertSuccessful();
    expect($doc->refresh()->scan_status)->toBe('INFECTED');
});

it('never marks a file CLEAN without a real clean verdict (scanner answers but cannot scan, backoff grows)', function () {
    q1Scanner(null);
    [, $doc] = q1UploadAndAttach($this);

    q1Scanner('ERROR'); // the health probe answers, the scan itself fails
    $this->artisan('documents:rescan-pending')->assertSuccessful();

    expect($doc->refresh()->scan_status)->toBe('SCAN_UNAVAILABLE');
    $row = DB::table('document_scan_queue')->where('document_id', $doc->id)->first();
    expect((int) $row->attempts)->toBeGreaterThanOrEqual(2)->and($row->next_attempt_at)->not->toBeNull();

    // Backoff: not due again on the next 5-minute run.
    $before = (int) $row->attempts;
    $this->artisan('documents:rescan-pending')->assertSuccessful();
    expect((int) DB::table('document_scan_queue')->where('document_id', $doc->id)->value('attempts'))->toBe($before);
    expect(DB::table('documents')->where('scan_status', 'CLEAN')->where('id', $doc->id)->exists())->toBeFalse();
});

it('adopts unreviewed legacy FAILED uploads into the queue but leaves staff-reviewed ones alone', function () {
    $f = makeMobileCustomerFixture();
    Storage::fake('local');
    $legacy = makeMobileTestDocument($f['tenant'], $f['party'], ['scan_status' => 'FAILED', 'storage_key' => 'documents/legacy.png', 'sha256' => hash('sha256', base64_decode(Q1_PNG))]);
    Storage::disk('local')->put('documents/legacy.png', base64_decode(Q1_PNG));
    $reviewed = makeMobileTestDocument($f['tenant'], $f['party'], ['scan_status' => 'FAILED', 'storage_key' => 'documents/reviewed.png', 'sha256' => str_repeat('b', 64)]);
    app(\App\Application\Audit\AuditWriter::class)->record('document.reviewed', 'document', $reviewed->id, ['scan_status' => 'FAILED']);

    q1Scanner(null);
    $this->artisan('documents:rescan-pending')->assertSuccessful();
    expect($legacy->refresh()->scan_status)->toBe('SCAN_UNAVAILABLE')->and($reviewed->refresh()->scan_status)->toBe('FAILED');

    q1Scanner('CLEAN');
    $this->artisan('documents:rescan-pending')->assertSuccessful();
    expect($legacy->refresh()->scan_status)->toBe('CLEAN')->and($reviewed->refresh()->scan_status)->toBe('FAILED');
});

it('shows the Upload scanning screen with scanner status, counts and the held queue, and rescans on demand', function () {
    q1Scanner(null);
    [$f, $doc] = q1UploadAndAttach($this);
    $admin = q1Staff($f['tenant'], ['documents.review']);
    $this->actingAs($admin);
    app(\App\Domain\Tenancy\TenantContext::class)->set($f['tenant']->id);

    $lw = \Livewire\Livewire::test(UploadScanning::class)->assertOk()
        ->assertSee(__('scan_queue.scanner.heading'))->assertSee(__('scan_queue.status.SCAN_UNAVAILABLE'))
        ->assertCanSeeTableRecords([$doc->id]);

    q1Scanner('CLEAN');
    $lw->callTableAction('rescan', $doc->id);
    expect($doc->refresh()->scan_status)->toBe('CLEAN');
    expect(DB::table('claim_documents')->where('document_id', $doc->id)->count())->toBe(1);
});

it('reports the malware scanner on the system health summary', function () {
    config(['services.clamav.host' => null, 'services.clamav.socket' => null]);
    $check = app(\App\Application\Operations\SystemHealthService::class)->summary()['checks']['malware_scanner'];
    expect($check['status'])->toBe('NOT_CONFIGURED');

    config(['services.clamav.host' => '127.0.0.1', 'services.clamav.port' => 1, 'services.clamav.timeout' => 1]);
    $check = (new MalwareScannerHealth)->status();
    expect($check)->configured->toBeTrue()->reachable->toBeFalse()->endpoint->toBe('tcp://127.0.0.1:1');
});
