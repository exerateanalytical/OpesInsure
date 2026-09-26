<?php

declare(strict_types=1);

/*
 * Workflow Institutional Data Master v1 — claims, repair_network, health,
 * payments_finance, accounting, commission, reinsurance_coinsurance (wm3).
 * REQ-MDM-003 (merge by code, no duplicates), REQ-DUP-013, workflow data master rules.
 */

use App\Application\Claims\ClaimNetworkReference;
use App\Application\Claims\ClaimReferenceCodes;
use App\Application\FinancialDistribution\CommissionReference;
use App\Application\FinancialDistribution\ReinsuranceReference;
use App\Application\Ledger\JournalTypeReference;
use App\Application\Payments\PaymentMethodReference;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function wm3Master(): array
{
    return json_decode((string) file_get_contents(database_path('data/workflow_institutional_data_master_2026.json')), true, flags: JSON_THROW_ON_ERROR);
}

function wm3Codes(string $domain, string $list): array
{
    return DB::table('master_data_values')->where(['domain_code' => $domain, 'list_code' => $list])->pluck('code')->all();
}

it('seeds the workflow lists into existing domains without duplicating codes [REQ-MDM-003]', function () {
    (new MasterDataSeeder)->run();

    // Claim types hang under the existing claim categories.
    expect(wm3Codes('claims', 'claim_type'))->toHaveCount(19);
    $orphans = DB::table('master_data_values')->where(['domain_code' => 'claims', 'list_code' => 'claim_type'])->whereNull('parent_value_id')->count();
    expect($orphans)->toBe(0);

    // Decision codes resolve onto the existing decision_type list: nothing added.
    expect(wm3Codes('claims', 'decision_type'))->toHaveCount(8)->not->toContain('APPROVED');

    // Synonyms are not re-added; genuinely new codes are.
    expect(wm3Codes('provider', 'provider_type'))->toContain('SPECIALIST_PRACTICE')->not->toContain('AMBULANCE');
    expect(wm3Codes('provider', 'medical_specialty'))->toContain('PATHOLOGY')->not->toContain('PEDIATRICS');
    expect(wm3Codes('health', 'benefit_category'))->toContain('AMBULANCE')->not->toContain('PREVENTIVE_CARE');
    expect(wm3Codes('partners', 'adjuster_type'))->toContain('VALUER', 'INVESTIGATOR')->not->toContain('MOTOR_ADJUSTER');
    expect(wm3Codes('finance', 'settlement_frequency'))->toContain('CUSTOM')->not->toContain('BIWEEKLY');
    expect(wm3Codes('reinsurance', 'coinsurance_role'))->toBe(['LEAD', 'FOLLOWER']);

    // Every seeded workflow value carries provenance + one of the 7 data statuses.
    $statuses = wm3Master()['dataset']['status_codes'];
    $rows = DB::table('master_data_values')->where('source_type', ClaimReferenceCodes::SOURCE)->get();
    expect($rows->count())->toBeGreaterThan(40);
    foreach ($rows as $row) {
        $a = json_decode((string) $row->attributes, true);
        expect($a['source'])->toBe(ClaimReferenceCodes::SOURCE)->and($statuses)->toContain($a['data_status']);
    }
});

it('keeps PENDING_SOURCE and CONFIG_REQUIRED domains empty and structure-only', function () {
    (new MasterDataSeeder)->run();
    $empty = [
        // evidence_type / decision_reason / rejection_reason were supplied by Gap Closure Pack 03 (tests/Feature/GapClosure/MotorClaimsRepairExpertsTest.php).
        'claims.damage_type', 'claims.injury_type', 'claims.fraud_indicator',
        'partners.adjuster', 'provider.medical_service', 'provider.provider_tariff', 'financial_institutions.bank',
        'finance.chart_of_accounts', 'finance.gl_mapping', 'reinsurance.reinsurer', 'reinsurance.reinsurance_broker',
    ];
    foreach ($empty as $key) {
        [$d, $l] = explode('.', $key);
        $list = DB::table('master_data_lists')->where(['domain_code' => $d, 'code' => $l])->first();
        expect($list)->not->toBeNull()->and((bool) $list->structure_only)->toBeTrue()
            ->and($list->source_type)->toBe(ClaimReferenceCodes::SOURCE)
            ->and(wm3Codes($d, $l))->toBe([]);
    }
    // PARTIALLY_KNOWN mobile money master: known operators only, carried as UNVERIFIED.
    $mm = DB::table('master_data_values')->where(['domain_code' => 'financial_institutions', 'list_code' => 'mobile_money_provider'])->get();
    expect($mm->pluck('code')->all())->toBe(['MTN_MOMO', 'ORANGE_MONEY']);
    foreach ($mm as $v) {
        expect(json_decode((string) $v->attributes, true))->toMatchArray(['data_status' => 'UNVERIFIED', 'source_status' => 'PARTIALLY_KNOWN']);
    }
});

it('is idempotent and never overwrites an admin-edited value', function () {
    (new MasterDataSeeder)->run();
    $count = DB::table('master_data_values')->count();
    DB::table('master_data_values')->where(['domain_code' => 'claims', 'list_code' => 'reserve_type', 'code' => 'LEGAL'])
        ->update(['label_en' => 'Legal fees (edited)', 'admin_modified_at' => now()]);

    (new MasterDataSeeder)->run();

    expect(DB::table('master_data_values')->count())->toBe($count)
        ->and(DB::table('master_data_values')->where(['domain_code' => 'claims', 'list_code' => 'reserve_type', 'code' => 'LEGAL'])->value('label_en'))->toBe('Legal fees (edited)');
});

it('resolves every workflow code onto a stored master-data code', function () {
    (new MasterDataSeeder)->run();
    $w = wm3Master();
    $has = fn (string $d, string $l, ?string $c) => $c !== null && in_array($c, wm3Codes($d, $l), true);

    foreach ($w['claims']['claim_types'] as $c) {
        expect($has('claims', 'claim_type', $c))->toBeTrue()->and($has('claims', 'claim_category', ClaimReferenceCodes::categoryFor($c)))->toBeTrue();
    }
    foreach ($w['claims']['reserve_types'] as $c) expect($has('claims', 'reserve_type', $c) && ClaimReferenceCodes::isReserveType($c))->toBeTrue();
    foreach ($w['claims']['recovery_types'] as $c) expect($has('claims', 'recovery_type', $c) && ClaimReferenceCodes::isRecoveryType($c))->toBeTrue();
    foreach ($w['claims']['decision_codes'] as $c) expect($has('claims', 'decision_type', ClaimReferenceCodes::DECISION_CODES[$c]['decision_type']))->toBeTrue();
    foreach ($w['repair_network']['expert_types'] as $c) expect($has('partners', 'adjuster_type', ClaimNetworkReference::canonical('expert', $c)))->toBeTrue();
    foreach ($w['health']['provider_types'] as $c) expect($has('provider', 'provider_type', ClaimNetworkReference::canonical('provider', $c)))->toBeTrue();
    foreach ($w['health']['specialties'] as $c) expect($has('provider', 'medical_specialty', ClaimNetworkReference::canonical('specialty', $c)))->toBeTrue();
    foreach ($w['health']['benefit_categories'] as $c) expect($has('health', 'benefit_category', ClaimNetworkReference::canonical('benefit', $c)))->toBeTrue();
    foreach ($w['payments_finance']['payment_methods'] as $c) expect($has('payments', 'payment_method', $c) && PaymentMethodReference::isMethod($c))->toBeTrue();
    foreach ($w['payments_finance']['currencies'] as $c) expect($has('currencies', 'currency', $c['code']) && PaymentMethodReference::isVerifiedCurrency($c['code']))->toBeTrue();
    foreach ($w['accounting']['journal_types'] as $c) {
        expect(JournalTypeReference::isType($c))->toBeTrue()
            ->and($has('finance', 'journal_type', JournalTypeReference::TYPES[$c]['book']))->toBeTrue();
        foreach (JournalTypeReference::TYPES[$c]['events'] as $e) {
            expect($e === 'JOURNAL_REVERSAL' || $has('accounting', 'event_type', $e))->toBeTrue();
        }
    }
    foreach ($w['commission']['basis_types'] as $c) expect($has('finance', 'commission_basis', $c))->toBeTrue();
    foreach ($w['commission']['earning_events'] as $c) expect($has('finance', 'commission_earning_event', $c))->toBeTrue();
    foreach ($w['commission']['clawback_reasons'] as $c) expect($has('finance', 'commission_clawback_reason', $c) && CommissionReference::isClawbackReason($c))->toBeTrue();
    foreach ($w['commission']['settlement_cycles'] as $c) expect($has('finance', 'settlement_frequency', CommissionReference::settlementFrequency($c)))->toBeTrue();
    $r = $w['reinsurance_coinsurance'];
    foreach ($r['reinsurance_types'] as $c) expect($has('reinsurance', 'reinsurance_type', $c))->toBeTrue();
    foreach ($r['treaty_types'] as $c) expect($has('reinsurance', 'treaty_type', $c))->toBeTrue();
    foreach ($r['coinsurance_roles'] as $c) expect($has('reinsurance', 'coinsurance_role', ReinsuranceReference::coinsuranceRole($c)))->toBeTrue();
    foreach ($r['bordereau_types'] as $c) expect($has('reinsurance', 'bordereau_type', ReinsuranceReference::bordereauType($c)))->toBeTrue();
});

it('maps workflow claim decisions onto the stored decision and status codes without changing them', function () {
    expect(ClaimReferenceCodes::decisionForApi('APPROVED'))->toBe('APPROVE')
        ->and(ClaimReferenceCodes::decisionForApi('PARTIALLY_APPROVED'))->toBe('PARTIAL')
        ->and(ClaimReferenceCodes::decisionForApi('REJECTED'))->toBe('DECLINE')
        ->and(ClaimReferenceCodes::decisionForApi('DECLINE'))->toBe('DECLINE')
        ->and(ClaimReferenceCodes::decisionForApi('CLOSED_NO_PAYMENT'))->toBeNull()
        ->and(ClaimReferenceCodes::decisionCodeForStatus('DECLINED'))->toBe('REJECTED')
        ->and(ClaimReferenceCodes::RECOVERY_TYPES)->toContain('SUBROGATION', 'SALVAGE', 'OTHER'); // legacy stored codes stay valid
    expect(Schema::hasColumn('claim_reserve_changes', 'reserve_type'))->toBeTrue();
});

it('maps payment methods onto the existing providers instead of new ones', function () {
    expect(PaymentMethodReference::methodForProvider('mtn_momo'))->toBe('MTN_MOMO')
        ->and(PaymentMethodReference::methodForProvider('orange_money'))->toBe('ORANGE_MONEY')
        ->and(PaymentMethodReference::providersFor('CASH'))->toBe([]);
    $providers = ['fake', 'maviance', 'campay', 'mtn_momo', 'orange_money'];
    foreach (PaymentMethodReference::METHODS as $list) {
        expect(array_diff($list, $providers))->toBe([]);
    }
    expect(PaymentMethodReference::isVerifiedCurrency('EUR'))->toBeFalse();
});

it('validates commission rule reference conditions and leaves legacy conditions alone', function () {
    expect(CommissionReference::normaliseConditions(['basis_type' => 'collected_premium', 'settlement_cycle' => 'BIWEEKLY', 'channel' => 'X']))
        ->toBe(['basis_type' => 'COLLECTED_PREMIUM', 'settlement_cycle' => 'FORTNIGHTLY', 'channel' => 'X']);
    expect(CommissionReference::normaliseConditions([]))->toBe([]);
    expect(fn () => CommissionReference::normaliseConditions(['earning_event' => 'WHENEVER']))->toThrow(ValidationException::class);
});

it('reports chart of accounts and GL mapping as CONFIG_REQUIRED until configured', function () {
    expect(JournalTypeReference::readiness(null))->toBe(['chart_of_accounts' => 'CONFIG_REQUIRED', 'gl_mapping' => 'CONFIG_REQUIRED', 'cost_centre' => 'CONFIG_REQUIRED']);
    expect(JournalTypeReference::typeForEvent('CLAIM_PAID'))->toBe('CLAIM_PAYMENT')
        ->and(ReinsuranceReference::bordereauType('claims'))->toBe('CLAIM');
});
