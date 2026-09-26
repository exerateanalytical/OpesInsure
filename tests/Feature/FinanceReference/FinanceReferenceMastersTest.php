<?php

declare(strict_types=1);

// Agent GP6 — gap closure pack 06: banks / payment institutions, payment provider profiles, GL control accounts,
// event-to-GL view, cost centres. REQ-ACC-001, REQ-PAY-003, REQ-PAY-014, REQ-GAP-06.

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\Finance\ReferenceMasters\FinanceReferenceCatalogue;
use App\Application\Finance\ReferenceMasters\FinanceReferenceService;
use App\Application\Import\ImportTargetRegistry;
use App\Application\Ledger\Journals\ManualJournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

function gp6Perms(): array
{
    return ['finance.accounts.view', 'finance.payment_providers.configure', 'finance.payment_providers.approve', 'finance.gl.configure', 'finance.gl.approve', 'finance.institutions.manage'];
}

it('REQ-GAP-06 seeds banks as PENDING_OFFICIAL_IMPORT and the two DGTCFM payment institutions as verified, idempotently', function () {
    $banks = DB::table('financial_institutions')->where('institution_type', 'BANK')->get();
    expect($banks)->toHaveCount(11)->and($banks->pluck('verification_status')->unique()->all())->toBe(['PENDING_OFFICIAL_IMPORT'])
        ->and($banks->whereNotNull('bic_swift')->count())->toBe(0)->and($banks->whereNotNull('bank_code')->count())->toBe(0);
    $pis = DB::table('financial_institutions')->where('institution_type', 'PAYMENT_INSTITUTION')->pluck('verification_status', 'legal_name')->all();
    expect($pis)->toEqualCanonicalizing(['Mobile Money Corporation' => 'VERIFIED_PUBLIC_SOURCE', 'Orange Money Cameroun' => 'VERIFIED_PUBLIC_SOURCE']);

    // Admin edit survives a re-seed.
    $afri = DB::table('financial_institutions')->where('code', 'BANK_AFRILAND_FIRST_BANK')->first();
    DB::table('financial_institutions')->where('id', $afri->id)->update(['trade_name' => 'Edited', 'admin_edited_at' => now()]);
    FinanceReferenceCatalogue::seed();
    expect(DB::table('financial_institutions')->count())->toBe(13)->and(DB::table('financial_institutions')->where('id', $afri->id)->value('trade_name'))->toBe('Edited');

    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, gp6Perms()));
    $prod = $this->getJson('/api/v1/finance/reference/institutions?production_only=1', tenantHeader($t))->assertOk()->json('data');
    expect(collect($prod)->pluck('institution_type')->unique()->all())->toBe(['PAYMENT_INSTITUTION']);
    $this->patchJson('/api/v1/finance/reference/institutions/'.$afri->id, ['verification_status' => 'VERIFIED_PUBLIC_SOURCE', 'source_url' => null, 'reason' => 'x'], tenantHeader($t))
        ->assertOk(); // existing BEAC source url is kept
    $this->patchJson('/api/v1/finance/reference/institutions/'.$afri->id, ['bic_swift' => 'BAD', 'reason' => 'x'], tenantHeader($t))->assertUnprocessable();
});

it('REQ-GAP-06 official bank import completes a placeholder through the generic import target and refuses duplicates', function () {
    $target = app(ImportTargetRegistry::class)->get('financial_institutions');
    $seen = [];
    $row = ['institution_type' => 'BANK', 'legal_name' => 'BICEC', 'bic_swift' => 'ICLRCMCX', 'source_url' => 'https://example.test/cobac', 'verification_status' => 'VERIFIED_PUBLIC_SOURCE'];
    expect($target->check($row, [], $seen)['status'])->toBe('NEW');
    $id = $target->import($row, [], null, (string) Str::uuid());
    $r = DB::table('financial_institutions')->find($id);
    expect($r->code)->toBe('BANK_BICEC')->and($r->verification_status)->toBe('VERIFIED_PUBLIC_SOURCE')->and($r->bic_swift)->toBe('ICLRCMCX');
    $seen = [];
    expect($target->check($row, [], $seen)['status'])->toBe('DUPLICATE');
    $seen = [];
    expect($target->check(['legal_name' => 'X'] + $row, [], $seen)['status'])->toBe('DUPLICATE'); // same BIC
    expect($target->check(['institution_type' => 'BANK', 'legal_name' => 'Y'], [], $seen)['status'])->toBe('ERROR');
});

it('REQ-PAY-014 payment provider profile stays CONFIG_REQUIRED until complete, refuses secrets and needs maker-checker', function () {
    $t = makeAuthTestTenant();
    $maker = makeAuthTestUser($t, gp6Perms());
    $svc = app(FinanceReferenceService::class);
    expect(fn () => $svc->assertProviderUsable($t->id, 'MTN_MOMO'))->toThrow(ValidationException::class);

    Passport::actingAs($maker);
    $this->postJson('/api/v1/finance/reference/payment-providers', ['provider_type' => 'MTN_MOMO', 'callback_profile' => ['headers' => ['api_key' => 'x']]], tenantHeader($t))
        ->assertUnprocessable();
    $this->postJson('/api/v1/finance/reference/payment-providers', ['provider_type' => 'MTN_MOMO', 'api_base_url' => 'http://insecure.test'], tenantHeader($t))->assertUnprocessable();
    $mtn = DB::table('financial_institutions')->where('code', 'PI_MOBILE_MONEY_CORPORATION')->value('id');
    $bank = DB::table('financial_institutions')->where('code', 'BANK_BICEC')->value('id');
    $this->postJson('/api/v1/finance/reference/payment-providers', ['provider_type' => 'BANK_TRANSFER', 'environment' => 'PRODUCTION', 'financial_institution_id' => $bank], tenantHeader($t))
        ->assertUnprocessable(); // pending bank not usable in production
    $id = $this->postJson('/api/v1/finance/reference/payment-providers', ['provider_type' => 'MTN_MOMO', 'financial_institution_id' => $mtn,
        'reconciliation_reference_rules' => ['field_mapping' => ['ref' => 'externalId']]], tenantHeader($t))->assertCreated()->json('data.id');
    $list = $this->getJson('/api/v1/finance/reference/payment-providers', tenantHeader($t))->assertOk()->json('data');
    expect($list[0]['status'])->toBe('CONFIG_REQUIRED')->and($list[0]['missing'])->toContain('merchant_identifier', 'connection_id');
    $this->postJson("/api/v1/finance/reference/payment-providers/$id/submit", [], tenantHeader($t))->assertUnprocessable();

    $conn = (string) Str::uuid();
    DB::table('payment_provider_connections')->insert(['id' => $conn, 'tenant_id' => $t->id, 'provider' => 'mtn_momo', 'environment' => 'SANDBOX', 'status' => 'ACTIVE',
        'credential_reference' => 'vault://x', 'capabilities' => '[]', 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->patchJson("/api/v1/finance/reference/payment-providers/$id", ['merchant_identifier' => 'M-1', 'settlement_account' => 'ACC-1', 'settlement_cycle' => 'T_PLUS_1',
        'effective_from' => now()->toDateString(), 'connection_id' => $conn], tenantHeader($t))->assertOk();
    $this->postJson("/api/v1/finance/reference/payment-providers/$id/submit", [], tenantHeader($t))->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');
    $this->postJson("/api/v1/finance/reference/payment-providers/$id/decision", ['decision' => 'APPROVE', 'reason' => 'ok'], tenantHeader($t))->assertUnprocessable();
    Passport::actingAs(makeAuthTestUser($t, gp6Perms()));
    $this->postJson("/api/v1/finance/reference/payment-providers/$id/decision", ['decision' => 'APPROVE', 'reason' => 'ok'], tenantHeader($t))->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    expect($svc->assertProviderUsable($t->id, 'MTN_MOMO')->id)->toBe($id);

    // Cross-tenant: another tenant sees nothing and cannot touch it.
    $other = makeAuthTestTenant('b');
    Passport::actingAs(makeAuthTestUser($other, gp6Perms()));
    expect($this->getJson('/api/v1/finance/reference/payment-providers', tenantHeader($other))->assertOk()->json('data'))->toBe([]);
    $this->postJson("/api/v1/finance/reference/payment-providers/$id/submit", [], tenantHeader($other))->assertNotFound();
});

it('REQ-ACC-001 control accounts fall back to the PLATFORM_NORMALIZED baseline, UNEARNED_PREMIUM is CONFIG_REQUIRED, tenant mapping is maker-checker', function () {
    $t = makeAuthTestTenant();
    $maker = makeAuthTestUser($t, gp6Perms());
    Passport::actingAs($maker);
    $rows = collect($this->getJson('/api/v1/finance/reference/control-accounts', tenantHeader($t))->assertOk()->json('data'))->keyBy('control_code');
    expect($rows)->toHaveCount(19)->and($rows['CUSTOMER_RECEIVABLES']['ledger_account_code'])->toBe('411000')->and($rows['CUSTOMER_RECEIVABLES']['data_status'])->toBe('CONFIG_REQUIRED')
        ->and($rows['CUSTOMER_RECEIVABLES']['baseline_status'])->toBe('PLATFORM_NORMALIZED')->and($rows['UNEARNED_PREMIUM']['ledger_account_code'])->toBeNull();

    $this->postJson('/api/v1/finance/reference/control-accounts', ['control_code' => 'UNEARNED_PREMIUM', 'ledger_account_code' => '999999', 'reason' => 'x'], tenantHeader($t))->assertUnprocessable();
    $this->postJson('/api/v1/finance/reference/control-accounts', ['control_code' => 'NOPE', 'ledger_account_code' => '411000', 'reason' => 'x'], tenantHeader($t))->assertUnprocessable();
    $m = $this->postJson('/api/v1/finance/reference/control-accounts', ['control_code' => 'CUSTOMER_RECEIVABLES', 'ledger_account_code' => '411100', 'reason' => 'tenant chart'], tenantHeader($t))
        ->assertCreated()->json('data');
    $this->postJson("/api/v1/finance/reference/control-accounts/{$m['id']}/approve", [], tenantHeader($t))->assertUnprocessable();
    Passport::actingAs(makeAuthTestUser($t, gp6Perms()));
    $this->postJson("/api/v1/finance/reference/control-accounts/{$m['id']}/approve", [], tenantHeader($t))->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $rows = collect($this->getJson('/api/v1/finance/reference/control-accounts', tenantHeader($t))->json('data'))->keyBy('control_code');
    expect($rows['CUSTOMER_RECEIVABLES']['ledger_account_code'])->toBe('411100')->and($rows['CUSTOMER_RECEIVABLES']['scope'])->toBe('TENANT');
    // Baseline untouched for other tenants.
    expect(collect(app(FinanceReferenceService::class)->controlAccounts(makeAuthTestTenant('c')->id))->firstWhere('control_code', 'CUSTOMER_RECEIVABLES')['ledger_account_code'])->toBe('411000');
});

it('REQ-ACC-001 event-to-GL view resolves spec events through accounting_event_mappings and flags unmapped events CONFIG_REQUIRED', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, gp6Perms()));
    $rows = collect($this->getJson('/api/v1/finance/reference/gl-event-mappings', tenantHeader($t))->assertOk()->json('data'))->keyBy('event_code');
    expect($rows)->toHaveCount(19)
        ->and($rows['PREMIUM_BILLED'])->toMatchArray(['accounting_event' => 'finance.obligation.created', 'debit_account' => '411000', 'credit_account' => '702000', 'approval_status' => 'PLATFORM_NORMALIZED'])
        ->and($rows['LEVY_RECOGNIZED']['approval_status'])->toBe('CONFIG_REQUIRED')->and($rows['CREDIT_NOTE']['approval_status'])->toBe('CONFIG_REQUIRED');
    app(\App\Application\Ledger\Posting\AccountingEventMappingService::class)->publish($t->id, 'commission.paid', '421000', '585000', null, 'tenant pays by momo');
    $rows = collect($this->getJson('/api/v1/finance/reference/gl-event-mappings', tenantHeader($t))->json('data'))->keyBy('event_code');
    expect($rows['COMMISSION_PAID'])->toMatchArray(['credit_account' => '585000', 'approval_status' => 'VERIFIED', 'scope' => 'TENANT']);
    expect(DB::table('accounting_event_mappings')->whereNull('tenant_id')->where('event_code', 'commission.paid')->where('status', 'ACTIVE')->value('credit_account_code'))->toBe('521000');
});

it('REQ-ACC-001 cost centres are tenant-scoped and a manual journal refuses an unknown or inactive cost centre dimension', function () {
    $t = makeAuthTestTenant();
    $u = makeAuthTestUser($t, gp6Perms());
    Passport::actingAs($u);
    $cc = $this->postJson('/api/v1/finance/reference/cost-centres', ['code' => 'hq-ops', 'name' => 'HQ operations'], tenantHeader($t))->assertCreated()->json('data');
    expect($cc['code'])->toBe('HQ-OPS');
    $this->postJson('/api/v1/finance/reference/cost-centres', ['code' => 'HQ-OPS', 'name' => 'dup'], tenantHeader($t))->assertUnprocessable();

    $svc = app(ManualJournalService::class);
    $acct = function (string $code) use ($t): string {
        $id = (string) Str::uuid();
        DB::table('ledger_accounts')->insert(['id' => $id, 'tenant_id' => $t->id, 'code' => $code, 'name' => $code, 'type' => 'ASSET', 'currency' => 'XAF', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $a = $acct('521000');
    $b = $acct('471000');
    $lines = fn (string $ccId) => [['account_id' => $a, 'debit_minor' => 100, 'dimensions' => ['cost_centre_id' => $ccId]], ['account_id' => $b, 'credit_minor' => 100]];
    $data = fn (string $ccId) => ['reference_type' => 'manual', 'reference_id' => (string) Str::uuid(), 'currency' => 'XAF', 'reason_code' => 'ADJ', 'lines' => $lines($ccId)];
    expect($svc->createDraft($t->id, $u->id, $data($cc['id']), 'c1'))->toBeString();
    expect(fn () => $svc->createDraft($t->id, $u->id, $data((string) Str::uuid()), 'c2'))->toThrow(ValidationException::class);
    $this->postJson("/api/v1/finance/reference/cost-centres/{$cc['id']}/status", ['status' => 'INACTIVE', 'reason' => 'closed'], tenantHeader($t))->assertOk();
    expect(fn () => $svc->createDraft($t->id, $u->id, $data($cc['id']), 'c3'))->toThrow(ValidationException::class);

    $other = makeAuthTestTenant('z');
    Passport::actingAs(makeAuthTestUser($other, gp6Perms()));
    expect($this->getJson('/api/v1/finance/reference/cost-centres', tenantHeader($other))->json('data'))->toBe([]);
});

it('REQ-GAP-06 registers every pack 06 gate in the Data Readiness registry with a computed status', function () {
    $reg = app(DataReadinessRegistry::class);
    $pay = collect($reg->domain('payments_finance'))->keyBy('item');
    expect($pay['banks_master']['status'])->toBe('PENDING_SOURCE')->and($pay['banks_master']['production_usable'])->toBeFalse()
        ->and($pay['mobile_money_provider_master']['status'])->toBe('VERIFIED')->and($pay['payment_provider_config']['status'])->toBe('CONFIG_REQUIRED');
    $acc = collect($reg->domain('accounting'))->keyBy('item');
    expect($acc['chart_of_accounts']['status'])->toBe('CONFIG_REQUIRED')->and($acc['gl_mapping']['status'])->toBe('CONFIG_REQUIRED')
        ->and(implode(' ', $acc['gl_mapping']['missing']))->toContain('LEVY_RECOGNIZED')->and($acc['cost_centre']['status'])->toBe('CONFIG_REQUIRED');
    $t = makeAuthTestTenant();
    DB::table('cost_centres')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'code' => 'A', 'name' => 'A', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    expect(collect($reg->domain('accounting'))->firstWhere('item', 'cost_centre')['status'])->toBe('VERIFIED');
});

it('REQ-GAP-06 catalogues every new route permission and refuses users without it', function () {
    $cat = config('permissions.finance_reference');
    foreach (['finance.institutions.manage', 'finance.payment_providers.configure', 'finance.payment_providers.approve', 'finance.gl.configure', 'finance.gl.approve'] as $p) {
        expect($cat)->toHaveKey($p);
    }
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, ['finance.accounts.view']));
    $this->postJson('/api/v1/finance/reference/cost-centres', ['code' => 'X', 'name' => 'X'], tenantHeader($t))->assertForbidden();
    $this->getJson('/api/v1/finance/reference/cost-centres', tenantHeader($t))->assertOk();
});
