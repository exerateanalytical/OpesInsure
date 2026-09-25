<?php

declare(strict_types=1);

// Agent F1 — mirrored broker/insurer views, dashboards with drill-down, statements (DOC-193..200), aging, reports, export, events.

use App\Application\Events\Catalogue\DomainEventCatalogue;
use App\Application\Finance\Subledger\AgingService;
use App\Application\Finance\Subledger\SubledgerCatalogue;
use App\Application\Finance\Subledger\SubledgerViews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/subledger_helpers.php';

beforeEach(fn () => Storage::fake('local'));

it('broker opens one insurer and sees premium payable and commission receivable separately; the insurer mirror sees receivable and payable', function () {
    $w = fslWorld();
    $brokerAccrual = fslAccrual($w['tenant'], $w['policy'], $w['broker'], 8000);
    Passport::actingAs($w['staff']);
    $b = $this->getJson('/api/v1/finance/subledger/insurers/'.$w['carrier']->id.'?currency=XAF&tab=PREMIUMS', tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect($b['perspective'])->toBe('BROKER')
        ->and($b['premium_payable']['gross_minor'])->toBe(90000)->and($b['premium_payable']['outstanding_minor'])->toBe(90000)
        ->and($b['commission_receivable']['gross_minor'])->toBe(8000) // broker's own commission, not netted into the premium payable
        ->and($b['net_is_informational'])->toBeTrue()->and($b['tab_rows'])->toHaveCount(1);

    $i = $this->getJson('/api/v1/finance/subledger/brokers/'.$w['broker']->id.'?currency=XAF', tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect($i['perspective'])->toBe('INSURER')
        ->and($i['premium_receivable'])->toMatchArray(['gross_minor' => 100000, 'settled_minor' => 60000, 'outstanding_minor' => 40000])
        ->and($i['commission_payable']['gross_minor'])->toBe(8000)->and($i['commission_payable']['commission_ids'])->toBe([$brokerAccrual->id]);
    foreach (array_merge(SubledgerCatalogue::spec()['broker_dashboard']['insurer_detail_tabs']) as $tab) {
        $this->getJson('/api/v1/finance/subledger/insurers/'.$w['carrier']->id.'?tab='.$tab, tenantHeaderFor($w['tenant']))->assertOk();
    }
    foreach (SubledgerCatalogue::spec()['insurer_dashboard']['broker_detail_tabs'] as $tab) {
        $this->getJson('/api/v1/finance/subledger/brokers/'.$w['broker']->id.'?tab='.$tab, tenantHeaderFor($w['tenant']))->assertOk();
    }
});

it('dashboard KPI drills down to the source journal and supporting document, and no dashboard balance is editable', function () {
    $w = fslWorld();
    Passport::actingAs($w['staff']);
    $d = $this->getJson('/api/v1/finance/subledger/dashboards/broker?currency=XAF&group_by=insurer', tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect(array_keys($d['kpis']))->toEqualCanonicalizing(SubledgerCatalogue::spec()['broker_dashboard']['kpis']);
    expect($d['kpis']['premium_written']['value_minor'])->toBe(100000)->and($d['kpis']['premium_collected']['value_minor'])->toBe(60000)
        ->and($d['kpis']['premium_uncollected']['value_minor'])->toBe(40000)->and($d['kpis']['premium_unremitted']['value_minor'])->toBe(90000)
        ->and($d['kpis']['commission_payable']['value_minor'])->toBe(10000);

    // A supporting document on the policy (e.g. the premium invoice) is reachable from the KPI.
    $this->postJson('/api/v1/finance/subledger/documents/DOC-193', ['subject_id' => $w['party']->id, 'currency' => 'XAF'], tenantHeaderFor($w['tenant']))->assertCreated();
    DB::table('documents')->where('tenant_id', $w['tenant']->id)->update(['policy_id' => $w['policy']->id]);

    $unremitted = $this->getJson('/api/v1/finance/subledger/entries?'.http_build_query($d['kpis']['premium_unremitted']['drilldown']['filters']), tenantHeaderFor($w['tenant']))->json('data');
    expect($unremitted['totals']['XAF']['net_minor'])->toBe(-90000)->and($unremitted['rows'][0]['insurer_id'])->toBe($w['carrier']->id);
    $drill = $d['kpis']['premium_uncollected']['drilldown'];
    expect($drill['endpoint'])->toBe('finance/subledger/entries');
    $entries = $this->getJson('/api/v1/'.$drill['endpoint'].'?'.http_build_query($drill['filters']), tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect($entries['totals']['XAF']['net_minor'])->toBe(40000);
    $path = $this->getJson('/api/v1/finance/subledger/entries/'.$entries['rows'][0]['id'].'/drilldown', tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect($path['journal']['balanced'])->toBeTrue()->and($path['journal']['spec_status'])->toBe('POSTED')->and($path['source']['type'])->toBeIn(['financial_obligations', 'payment_intents'])
        ->and($path['policy']['id'])->toBe($w['policy']->id)->and($path['documents'])->not->toBeEmpty()
        ->and($path['path'])->toBe(['ENTRY', 'JOURNAL_ENTRY', 'SOURCE_TRANSACTION', 'POLICY', 'SUPPORTING_DOCUMENT']);

    $this->putJson('/api/v1/finance/subledger/dashboards/broker', ['kpis' => []], tenantHeaderFor($w['tenant']))->assertStatus(405);
    $this->patchJson('/api/v1/finance/subledger/entries/'.$entries['rows'][0]['id'].'/drilldown', [], tenantHeaderFor($w['tenant']))->assertStatus(405);

    $ins = $this->getJson('/api/v1/finance/subledger/dashboards/insurer?currency=XAF', tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect(array_keys($ins['kpis']))->toEqualCanonicalizing(SubledgerCatalogue::spec()['insurer_dashboard']['kpis'])
        ->and($ins['kpis']['premium_receivable_from_brokers']['value_minor'])->toBe(40000);
});

it('customer, broker and carrier statements reconcile, and DOC-193..200 are generated through the document engine', function () {
    $w = fslWorld();
    $s = app(SubledgerViews::class)->customerStatement($w['tenant']->id, $w['party']->id, ['currency' => 'XAF']);
    expect($s['reconciles'])->toBeTrue()->and($s['opening_balance'] + $s['debits'] - $s['credits'])->toBe($s['closing_balance'])
        ->and($s['closing_balance'])->toBe(40000)->and($s['payments'])->toBe(60000);
    Passport::actingAs($w['staff']);
    $subjects = ['DOC-193' => $w['party']->id, 'DOC-194' => $w['broker']->id, 'DOC-195' => $w['agent']->id, 'DOC-196' => $w['broker']->id,
        'DOC-197' => $w['carrier']->id, 'DOC-198' => (string) Str::uuid(), 'DOC-199' => $w['tenant']->id, 'DOC-200' => $w['tenant']->id];
    foreach ($subjects as $doc => $subject) {
        $r = $this->postJson('/api/v1/finance/subledger/documents/'.$doc, ['subject_id' => $subject, 'currency' => 'XAF'], tenantHeaderFor($w['tenant']))->assertCreated()->json('data');
        expect($r['document_type_code'])->toBe(SubledgerCatalogue::STATEMENT_DOCUMENTS[$doc]['type'])->and($r['provenance']['spec_document'])->toBe($doc)
            ->and($r['document_number'])->not->toBeEmpty();
        if (in_array($doc, ['DOC-193', 'DOC-194', 'DOC-197'], true)) {
            expect($r['provenance']['figures']['reconciles'])->toBeTrue();
        }
    }
    $carrier = DB::table('documents')->where('tenant_id', $w['tenant']->id)->where('document_type_code', 'CARRIER_SETTLEMENT_STATEMENT')->first();
    $fig = json_decode($carrier->provenance, true)['figures'];
    expect($fig['gross_premium_payable'])->toBe(90000)->and($fig['commission_receivable'])->toBe(0)->and($fig['net_settlement'])->toBe(90000);
    expect(DB::table('outbox_messages')->where('event_name', 'finance.statement.generated')->count())->toBe(8);
    $this->postJson('/api/v1/finance/subledger/documents/DOC-186', ['subject_id' => $w['party']->id], tenantHeaderFor($w['tenant']))->assertStatus(422);
});

it('aging uses the spec buckets and the configured date basis', function () {
    $w = fslWorld();
    $aging = app(AgingService::class);
    $o = fslBill($w['tenant'], $w['policy'], 5000, now()->subDays(45)->toDateString());
    DB::table('financial_obligations')->where('id', $o->id)->update(['created_at' => now()->subDays(100)]);
    expect(array_keys(SubledgerCatalogue::AGING_BUCKETS))->toBe(array_column(SubledgerCatalogue::spec()['aging']['buckets'], 'code'));
    $byDue = $aging->age($w['tenant']->id, 'RECEIVABLE', ['currency' => 'XAF']);
    expect($byDue['basis'])->toBe('DUE_DATE')->and($byDue['buckets']['XAF']['DAYS_31_60'])->toBe(5000)->and($byDue['buckets']['XAF']['CURRENT'])->toBe(40000);
    Passport::actingAs($w['staff']);
    $this->postJson('/api/v1/finance/subledger/aging-settings', ['scope' => 'RECEIVABLE', 'basis' => 'TRANSACTION_DATE'], tenantHeaderFor($w['tenant']))->assertOk();
    $byTx = $this->getJson('/api/v1/finance/subledger/aging/receivable?currency=XAF', tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect($byTx['basis'])->toBe('TRANSACTION_DATE')->and($byTx['buckets']['XAF']['DAYS_91_120'])->toBe(5000);
    $this->postJson('/api/v1/finance/subledger/aging-settings', ['scope' => 'RECEIVABLE', 'basis' => 'MOON_PHASE'], tenantHeaderFor($w['tenant']))->assertStatus(422);
    expect(AgingService::bucket(now()->subDays(121)->toDateString(), \Carbon\CarbonImmutable::now()))->toBe('DAYS_120_PLUS');
});

it('export exactly matches the active filters, and unsupported filters are refused', function () {
    $w = fslWorld();
    Passport::actingAs($w['staff']);
    $q = 'currency=XAF&policy_id='.$w['policy']->id.'&journal_type=PREMIUM_BILLING';
    $json = $this->getJson('/api/v1/finance/subledger/entries?'.$q, tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect($json['applied_filters'])->toBe(['currency' => 'XAF', 'journal_type' => 'PREMIUM_BILLING', 'policy_id' => $w['policy']->id]);
    $csv = $this->get('/api/v1/finance/subledger/entries?format=csv&'.$q, tenantHeaderFor($w['tenant']))->assertOk()->getContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect($lines[0])->toContain('"journal_type":"PREMIUM_BILLING"')->and(count($lines) - 2)->toBe(count($json['rows']))->and(count($json['rows']))->toBe(2);
    foreach (array_column($json['rows'], 'id') as $id) {
        expect($csv)->toContain($id);
    }
    $this->getJson('/api/v1/finance/subledger/entries?fleet_id='.Str::uuid(), tenantHeaderFor($w['tenant']))->assertStatus(422);
});

it('lists every spec report, delegates existing FR reports, and shows an unmatched mobile-money line as a reconciliation exception', function () {
    $w = fslWorld();
    $import = (string) Str::uuid();
    DB::table('reconciliation_imports')->insert(['id' => $import, 'tenant_id' => $w['tenant']->id, 'source_type' => 'MOBILE_MONEY', 'provider' => 'MTN_MOMO', 'statement_reference' => 'ST-1', 'period_start' => now()->subDay()->toDateString(), 'period_end' => now()->toDateString(),
        'currency' => 'XAF', 'file_hash' => str_repeat('b', 64), 'status' => 'COMPLETED', 'total_rows' => 1, 'matched_rows' => 0, 'exception_rows' => 1, 'uploaded_by' => $w['staff']->id,
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('reconciliation_items')->insert(['id' => (string) Str::uuid(), 'reconciliation_import_id' => $import, 'external_reference' => 'MOMO-404', 'transaction_at' => now(),
        'gross_minor' => 12500, 'fee_minor' => 0, 'net_minor' => 12500, 'currency' => 'XAF', 'status' => 'EXCEPTION', 'outcome' => 'UNMATCHED', 'exception_code' => 'NO_MATCH',
        'raw_data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    Passport::actingAs($w['staff']);
    $cat = $this->getJson('/api/v1/finance/subledger/reports', tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect(array_column($cat, 'code'))->toBe(SubledgerCatalogue::spec()['reports']);
    foreach (SubledgerCatalogue::spec()['reports'] as $code) {
        $r = $this->getJson('/api/v1/finance/subledger/reports/'.$code.'?currency=XAF', tenantHeaderFor($w['tenant']))->assertOk()->json('data');
        expect($r['status'])->toBe($code === 'DEBIT_CREDIT_NOTE_REGISTER' ? 'NOT_AVAILABLE' : 'AVAILABLE');
    }
    $unmatched = $this->getJson('/api/v1/finance/subledger/reports/UNMATCHED_PAYMENTS', tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect($unmatched['delegated_to'])->toBe('FR-09')->and(array_column($unmatched['rows'], 'external_reference'))->toBe(['MOMO-404']);
    $exc = collect($this->getJson('/api/v1/finance/subledger/reports/FINANCIAL_EXCEPTION_DASHBOARD', tenantHeaderFor($w['tenant']))->json('data.rows'))->firstWhere('source', 'reconciliation_exceptions');
    expect($exc['count'])->toBe(1);
    $close = $this->getJson('/api/v1/finance/subledger/reports/PERIOD_CLOSE_STATUS', tenantHeaderFor($w['tenant']))->assertOk()->json('data');
    expect($close['status'])->toBe('AVAILABLE');
});

it('registers every spec event as a catalogue alias and merges the spec into the canonical specification', function () {
    foreach (SubledgerCatalogue::spec()['events'] as $alias) {
        expect(DomainEventCatalogue::canonicalName($alias))->toBe(SubledgerCatalogue::EVENTS[$alias]);
    }
    $canonical = json_decode(file_get_contents(base_path('docs/spec/canonical/OpesInsure_Canonical_Implementation_Specification_v1.json')), true);
    expect($canonical)->toHaveKey('finance_counterparty_accounts_commission_subledger')
        ->and($canonical['finance_counterparty_accounts_commission_subledger']['acceptance_tests'])->toBe(SubledgerCatalogue::spec()['acceptance_tests']);
    expect(SubledgerCatalogue::PERMISSIONS)->toBe(SubledgerCatalogue::spec()['permissions'])
        ->and(SubledgerCatalogue::DIMENSIONS)->toBe(SubledgerCatalogue::spec()['ledger_entry']['dimensions'])
        ->and(array_keys(SubledgerCatalogue::RELATIONSHIPS))->toBe(array_keys(SubledgerCatalogue::spec()['counterparty_accounts']));
});
