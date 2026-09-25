<?php

declare(strict_types=1);

use App\Application\Cases\Models\WorkCase;
use App\Application\Reconciliation\RefundCandidateSink;
use App\Models\ReconciliationItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

const B95_PERMS = ['reconciliation.import', 'reconciliation.read', 'reconciliation.resolve', 'reconciliation.approve'];

function b95Import($test, Tenant $t, array $rows, string $ref = null): array
{
    $items = array_map(fn ($r) => ['external_reference' => $r[0], 'transaction_at' => now()->toDateTimeString(), 'gross_minor' => $r[1], 'fee_minor' => 0, 'net_minor' => $r[1]], $rows);

    return $test->postJson('/api/v1/reconciliation/imports', [
        'source_type' => 'PAYMENT_PROVIDER', 'provider' => 'MTN_MOMO', 'statement_reference' => $ref ?? 'ST-'.Str::random(8),
        'period_start' => now()->subDay()->toDateString(), 'period_end' => now()->toDateString(), 'currency' => 'XAF',
        'file_hash' => hash('sha256', Str::random(30)), 'items' => $items,
    ], tenantHeader($t))->assertCreated()->json('data');
}

function b95Outcomes(string $importId): array
{
    return ReconciliationItem::where('reconciliation_import_id', $importId)->orderBy('external_reference')->pluck('outcome', 'external_reference')->all();
}

function b95Fixture(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $pay = fn (array $o = []) => makeMobileTestPayment($f['proposal'], $f['tenant'], $o);

    return [$f['tenant'], $pay];
}

it('REQ-PAY-007 classifies MATCHED / UNMATCHED / OVER / UNDER / PARTIAL and opens exception cases', function () {
    [$t, $pay] = b95Fixture();
    Passport::actingAs(makeAuthTestUser($t, B95_PERMS));
    $ok = $pay(['provider_reference' => 'R-OK']);
    $pay(['provider_reference' => 'R-OVER']);
    $pay(['provider_reference' => 'R-UNDER']);
    $pay(['provider_reference' => 'R-PART', 'status' => 'PENDING']);

    $imp = b95Import($this, $t, [['R-OK', 100000], ['R-NONE', 5000], ['R-OVER', 120000], ['R-UNDER', 90000], ['R-PART', 40000]]);

    expect(b95Outcomes($imp['id']))->toBe(['R-NONE' => 'UNMATCHED', 'R-OK' => 'MATCHED', 'R-OVER' => 'OVER', 'R-PART' => 'PARTIAL', 'R-UNDER' => 'UNDER'])
        ->and($imp['matched_rows'])->toBe(1)->and($imp['exception_rows'])->toBe(4)->and($imp['status'])->toBe('COMPLETED_WITH_EXCEPTIONS');
    $over = ReconciliationItem::where('external_reference', 'R-OVER')->first();
    expect($over->variance_minor)->toBe(20000)->and($over->status)->toBe('EXCEPTION')->and($over->case_id)->not->toBeNull();
    $case = WorkCase::withoutGlobalScopes()->find($over->case_id);
    expect($case->case_type_code)->toBe('RECONCILIATION_EXCEPTION')->and($case->priority)->toBe('HIGH')->and($case->subject_id)->toBe($over->id);
    expect(ReconciliationItem::where('external_reference', 'R-OK')->value('case_id'))->toBeNull()
        ->and(DB::table('outbox_messages')->where('event_name', 'reconciliation.exception.raised')->count())->toBe(4);
});

it('REQ-PAY-007 WF-026/027/086 flags a duplicate payment as refund candidate', function () {
    [$t, $pay] = b95Fixture();
    Passport::actingAs(makeAuthTestUser($t, B95_PERMS));
    $pay(['provider_reference' => 'R-DUP']);
    $sink = new class implements RefundCandidateSink
    {
        public array $calls = [];

        public function refundCandidate(ReconciliationItem $item, string $reason, int $amountMinor, string $currency): void
        {
            $this->calls[] = [$item->id, $reason, $amountMinor, $currency];
        }
    };
    app()->instance(RefundCandidateSink::class, $sink);

    b95Import($this, $t, [['R-DUP', 100000]]);
    $imp = b95Import($this, $t, [['R-DUP', 100000]]);

    $dup = ReconciliationItem::where('reconciliation_import_id', $imp['id'])->first();
    expect($dup->outcome)->toBe('DUPLICATE')->and($dup->exception_code)->toBe('DUPLICATE_PAYMENT')
        ->and($dup->refund_candidate)->toBeTrue()->and($dup->refund_reason)->toBe('DUPLICATE_PAYMENT')
        ->and($sink->calls)->toBe([[$dup->id, 'DUPLICATE_PAYMENT', 100000, 'XAF']])
        ->and(DB::table('outbox_messages')->where('event_name', 'reconciliation.refund_candidate.flagged')->count())->toBe(1);
});

it('REQ-PAY-007 split settlement promotes the earlier PARTIAL line when the remainder arrives', function () {
    [$t, $pay] = b95Fixture();
    Passport::actingAs(makeAuthTestUser($t, B95_PERMS));
    $p = $pay(['provider_reference' => 'R-SPLIT', 'status' => 'PENDING']);
    $first = b95Import($this, $t, [['R-SPLIT', 40000]]);
    $partial = ReconciliationItem::where('reconciliation_import_id', $first['id'])->first();
    expect($partial->outcome)->toBe('PARTIAL');

    $second = b95Import($this, $t, [['R-SPLIT', 60000]]);
    $last = ReconciliationItem::where('reconciliation_import_id', $second['id'])->first();
    expect($last->outcome)->toBe('MATCHED')->and($second['status'])->toBe('COMPLETED')
        ->and($partial->refresh()->status)->toBe('MATCHED')
        ->and(WorkCase::withoutGlobalScopes()->find($partial->case_id)->status)->toBe('CLOSED')
        ->and(\App\Models\ReconciliationImport::find($first['id'])->status)->toBe('COMPLETED');

    // A third line after full settlement is a duplicate.
    $third = b95Import($this, $t, [['R-SPLIT', 60000]]);
    expect(b95Outcomes($third['id']))->toBe(['R-SPLIT' => 'DUPLICATE']);
    
});

it('REQ-PAY-007 workspace search + manual match with maker-checker closes the exception case', function () {
    [$t, $pay] = b95Fixture();
    $maker = makeAuthTestUser($t, B95_PERMS);
    $checker = makeAuthTestUser($t, B95_PERMS);
    $target = $pay(['provider_reference' => 'R-REAL']);
    Passport::actingAs($maker);
    $imp = b95Import($this, $t, [['BANK-TYPO-1', 100000]]);
    $item = ReconciliationItem::where('reconciliation_import_id', $imp['id'])->first();

    $found = $this->getJson('/api/v1/reconciliation/workspace/items?outcome=UNMATCHED&reference=typo', tenantHeader($t))->assertOk()->json('data');
    expect(collect($found)->pluck('id')->all())->toBe([$item->id]);
    $cands = $this->getJson("/api/v1/reconciliation/workspace/items/{$item->id}/candidates", tenantHeader($t))->assertOk()->json('data.candidates');
    expect(collect($cands)->pluck('id'))->toContain($target->id);

    // Direct MATCHED resolution bypassing maker-checker is refused.
    $this->postJson("/api/v1/reconciliation/items/{$item->id}/resolve", ['resolution' => 'MATCHED', 'matched_type' => 'PAYMENT_INTENT', 'matched_id' => $target->id, 'notes' => str_repeat('n', 25)], tenantHeader($t))->assertStatus(422);

    $m = $this->postJson("/api/v1/reconciliation/items/{$item->id}/manual-matches", ['matched_id' => $target->id, 'notes' => 'Bank truncated the provider reference.'], tenantHeader($t))->assertCreated()->json('data');
    $this->postJson("/api/v1/reconciliation/items/{$item->id}/manual-matches", ['matched_id' => $target->id, 'notes' => 'Bank truncated the provider reference.'], tenantHeader($t))->assertStatus(422);
    $this->postJson("/api/v1/reconciliation/manual-matches/{$m['id']}/decide", ['decision' => 'APPROVE', 'note' => 'self approve'], tenantHeader($t))->assertStatus(422);

    Passport::actingAs($checker);
    $this->postJson("/api/v1/reconciliation/manual-matches/{$m['id']}/decide", ['decision' => 'APPROVE', 'note' => 'Checked bank slip'], tenantHeader($t))->assertOk()->assertJsonPath('data.status', 'APPROVED');

    $item->refresh();
    expect($item->status)->toBe('MATCHED')->and($item->matched_id)->toBe($target->id)
        ->and(WorkCase::withoutGlobalScopes()->find($item->case_id)->status)->toBe('CLOSED')
        ->and($item->import->refresh()->status)->toBe('COMPLETED')
        ->and(DB::table('outbox_messages')->where('event_name', 'reconciliation.manual_match.approved')->count())->toBe(1);

    // The import can now be approved by the checker; the manually matched payment is reconciled.
    $this->postJson("/api/v1/reconciliation/imports/{$imp['id']}/approve", [], tenantHeader($t))->assertOk();
    expect($target->refresh()->reconciled_at)->not->toBeNull();

    // Tenant isolation.
    $other = makeAuthTestTenant('b95x');
    Passport::actingAs(makeAuthTestUser($other, B95_PERMS));
    $this->getJson("/api/v1/reconciliation/workspace/items/{$item->id}/candidates", tenantHeader($other))->assertNotFound();
});
