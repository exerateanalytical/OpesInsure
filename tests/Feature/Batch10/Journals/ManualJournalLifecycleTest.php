<?php

declare(strict_types=1);

use App\Application\Ledger\LedgerService;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

const B107_ALL = ['ledger.read', 'ledger.adjust', 'ledger.approve', 'ledger.post', 'ledger.reverse'];

function b107Account(Tenant $t, string $code, string $status = 'ACTIVE'): string
{
    $id = (string) Str::uuid();
    DB::table('ledger_accounts')->insert(['id' => $id, 'tenant_id' => $t->id, 'code' => $code, 'name' => 'Acc '.$code, 'type' => 'ASSET', 'currency' => 'XAF', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function b107Payload(string $dr, string $cr, int $d = 5000, int $c = 5000): array
{
    return ['reference_type' => 'MANUAL_ADJUSTMENT', 'reference_id' => (string) Str::uuid(), 'currency' => 'XAF', 'reason_code' => 'CORRECTION', 'description' => 'Fix',
        'lines' => [['account_id' => $dr, 'debit_minor' => $d], ['account_id' => $cr, 'credit_minor' => $c]]];
}

it('REQ-ACC-002 walks DRAFT -> VALIDATED -> APPROVED -> POSTED -> REVERSED with maker-checker and a mirror reversal', function () {
    $t = makeAuthTestTenant();
    $maker = makeAuthTestUser($t, B107_ALL);
    $checker = makeAuthTestUser($t, B107_ALL);
    $a = b107Account($t, '1000');
    $b = b107Account($t, '2000');

    Passport::actingAs($maker);
    $id = $this->postJson('/api/v1/ledger/manual-journals', b107Payload($a, $b), tenantHeader($t))->assertCreated()->json('data.journal.id');
    expect(DB::table('journals')->find($id))->status->toBe('DRAFT')->journal_type->toBe('MANUAL')->posted_at->toBeNull();

    // cannot approve or post before validation
    $this->postJson("/api/v1/ledger/manual-journals/{$id}/post", [], tenantHeader($t))->assertStatus(409);
    $this->postJson("/api/v1/ledger/manual-journals/{$id}/validate", [], tenantHeader($t))->assertOk()->assertJsonPath('data.journal.status', 'VALIDATED');
    // maker cannot approve their own journal
    $this->postJson("/api/v1/ledger/manual-journals/{$id}/approve", [], tenantHeader($t))->assertForbidden();

    Passport::actingAs($checker);
    $this->postJson("/api/v1/ledger/manual-journals/{$id}/approve", [], tenantHeader($t))->assertOk()->assertJsonPath('data.journal.status', 'APPROVED');
    $this->postJson("/api/v1/ledger/manual-journals/{$id}/post", [], tenantHeader($t))->assertOk()->assertJsonPath('data.journal.status', 'POSTED');

    $tb = $this->getJson('/api/v1/ledger/trial-balance?currency=XAF', tenantHeader($t))->assertOk()->json('data');
    expect($tb['balanced'])->toBeTrue()->and($tb['total_debit_minor'])->toBe(5000)->and($tb['accounts'])->toHaveCount(2);

    $rev = $this->postJson("/api/v1/ledger/manual-journals/{$id}/reverse", ['reason_code' => 'ERROR', 'notes' => 'Posted to the wrong account by mistake.'], tenantHeader($t))
        ->assertOk()->json('data.reversal_id');
    expect(DB::table('journals')->find($id)->status)->toBe('REVERSED')
        ->and(DB::table('journals')->find($rev)->reverses_journal_id)->toBe($id)
        ->and((int) DB::table('journal_lines')->where('journal_id', $id)->where('account_id', $a)->value('debit_minor'))->toBe(5000)
        ->and((int) DB::table('journal_lines')->where('journal_id', $rev)->where('account_id', $a)->value('credit_minor'))->toBe(5000);

    $tb = $this->getJson('/api/v1/ledger/trial-balance', tenantHeader($t))->json('data');
    expect(collect($tb['accounts'])->pluck('balance_minor')->all())->toBe([0, 0]);
    foreach (['drafted', 'validated', 'approved', 'posted', 'reversed'] as $e) {
        expect(DB::table('outbox_messages')->where('event_name', "ledger.journal.{$e}")->count())->toBe(1);
    }
});

it('REQ-ACC-002 validation refuses unbalanced journals and inactive accounts', function () {
    $t = makeAuthTestTenant();
    $u = makeAuthTestUser($t, B107_ALL);
    $a = b107Account($t, '1000');
    $b = b107Account($t, '2000');
    $dead = b107Account($t, '3000', 'INACTIVE');
    Passport::actingAs($u);

    $id = $this->postJson('/api/v1/ledger/manual-journals', b107Payload($a, $b, 5000, 4000), tenantHeader($t))->assertCreated()->json('data.journal.id');
    $this->postJson("/api/v1/ledger/manual-journals/{$id}/validate", [], tenantHeader($t))->assertUnprocessable();
    expect(DB::table('journals')->find($id)->status)->toBe('DRAFT');

    $id2 = $this->postJson('/api/v1/ledger/manual-journals', b107Payload($a, $dead), tenantHeader($t))->assertCreated()->json('data.journal.id');
    $this->postJson("/api/v1/ledger/manual-journals/{$id2}/validate", [], tenantHeader($t))->assertUnprocessable();

    // checker can send a validated journal back to draft
    $id3 = $this->postJson('/api/v1/ledger/manual-journals', b107Payload($a, $b), tenantHeader($t))->json('data.journal.id');
    $this->postJson("/api/v1/ledger/manual-journals/{$id3}/validate", [], tenantHeader($t))->assertOk();
    $this->postJson("/api/v1/ledger/manual-journals/{$id3}/reject", ['reason_code' => 'NEEDS_SUPPORT'], tenantHeader($t))->assertOk()->assertJsonPath('data.journal.status', 'DRAFT');

    // another tenant cannot see or act on it
    $t2 = makeAuthTestTenant('b');
    Passport::actingAs(makeAuthTestUser($t2, B107_ALL));
    $this->postJson("/api/v1/ledger/manual-journals/{$id3}/validate", [], tenantHeader($t2))->assertNotFound();
});

it('REQ-ACC-002 automatic postings stay POSTED immediately and cannot enter the manual lifecycle', function () {
    $t = makeAuthTestTenant();
    $u = makeAuthTestUser($t, B107_ALL);
    $a = b107Account($t, '1000');
    $b = b107Account($t, '2000');
    $id = app(LedgerService::class)->post($t->id, 'TEST', (string) Str::uuid(), 'XAF', [['account_id' => $a, 'debit_minor' => 10], ['account_id' => $b, 'credit_minor' => 10]], 'c');
    expect(DB::table('journals')->find($id))->status->toBe('POSTED')->journal_type->toBe('AUTOMATIC');

    Passport::actingAs($u);
    $this->postJson("/api/v1/ledger/manual-journals/{$id}/approve", [], tenantHeader($t))->assertStatus(409);
});

it('REQ-ACC-002 the database refuses unbalanced posted journals and self-approval', function () {
    $t = makeAuthTestTenant();
    $u = makeAuthTestUser($t, []);
    $a = b107Account($t, '1000');
    DB::statement('SET CONSTRAINTS journals_balanced_on_journal, journals_balanced_on_line IMMEDIATE');

    $base = ['tenant_id' => $t->id, 'reference_type' => 'X', 'currency' => 'XAF', 'correlation_id' => 'c', 'created_at' => now(), 'updated_at' => now()];
    $id = (string) Str::uuid();
    DB::table('journals')->insert($base + ['id' => $id, 'reference_id' => (string) Str::uuid(), 'status' => 'DRAFT', 'journal_type' => 'MANUAL', 'posted_at' => null]);
    DB::table('journal_lines')->insert(['id' => (string) Str::uuid(), 'journal_id' => $id, 'account_id' => $a, 'debit_minor' => 7, 'credit_minor' => 0]);

    expect(fn () => DB::transaction(fn () => DB::table('journals')->where('id', $id)->update(['status' => 'POSTED', 'posted_at' => now(), 'approved_by' => $u->id])))
        ->toThrow(QueryException::class, 'is not balanced');
    expect(fn () => DB::transaction(fn () => DB::table('journals')->where('id', $id)->update(['created_by' => $u->id, 'approved_by' => $u->id])))
        ->toThrow(QueryException::class, 'journals_maker_checker');
    expect(fn () => DB::transaction(fn () => DB::table('journal_lines')->insert(['id' => (string) Str::uuid(), 'journal_id' => $id, 'account_id' => $a, 'debit_minor' => 1, 'credit_minor' => 1])))
        ->toThrow(QueryException::class, 'journal_lines_one_side');
});
