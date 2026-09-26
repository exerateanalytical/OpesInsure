<?php

declare(strict_types=1);

namespace App\Application\MarketData\Import;

use App\Application\FinancialDistribution\CommissionService;
use App\Application\Import\ImportTarget;
use App\Models\CommissionRuleVersion;
use App\Models\InsuranceProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gap closure 01 — commission tables (PENDING_PRIVATE_SOURCE). Rows become DRAFT commission_rule_versions through the
 * canonical CommissionService::createRule; approval stays maker-checker (approveRule) and MarketDataGates refuses to
 * approve a rule that has no source agreement / document. Rates are never defaulted: a missing rate is an error.
 */
final class CommissionTableImportTarget implements ImportTarget
{
    use ResolvesMarketParties;

    public const KEY = 'commission_tables';

    public const BASIS_TYPES = ['WRITTEN_PREMIUM', 'COLLECTED_PREMIUM', 'NET_PREMIUM', 'GROSS_PREMIUM', 'FIXED_AMOUNT', 'INSTALLMENT_BASED'];

    public const BENEFICIARIES = ['BROKER', 'AGENT', 'SUB_AGENT', 'REFERRER', 'OTHER_AUTHORIZED'];

    public function __construct(private readonly CommissionService $commissions) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Commission tables (insurer / broker agreements)';
    }

    public function fields(): array
    {
        return ['insurer' => true, 'broker' => false, 'product_code' => false, 'line_code' => false, 'cima_branch_code' => false, 'channel' => false,
            'beneficiary_type' => true, 'basis_type' => true, 'rate_percent' => false, 'fixed_amount' => false, 'currency' => false,
            'earning_event' => false, 'clawback_rule' => false, 'tax_treatment' => false, 'effective_from' => true, 'effective_until' => false,
            'source_agreement_number' => false, 'source_document' => false];
    }

    public function params(array $params): array
    {
        if (blank($params['tenant_id'] ?? null) || ! DB::table('tenants')->where('id', $params['tenant_id'])->exists()) {
            throw ValidationException::withMessages(['tenant_id' => ['Commission tables are imported for one tenant (tenant_id).']]);
        }

        return ['tenant_id' => (string) $params['tenant_id']];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        try {
            $d = $this->resolve($row, $params);
            $key = implode('/', [$d['carrier_id'], $d['partner_id'] ?? '*', $d['product_id'] ?? '*', $d['line_code'] ?? '*', $d['beneficiary_type'], $d['effective_from']]);
            if (isset($seen[$key])) {
                return ['status' => 'ERROR', 'error' => 'Rule repeated in the file'];
            }
            $seen[$key] = true;
            $dupe = CommissionRuleVersion::where(['carrier_id' => $d['carrier_id'], 'partner_id' => $d['partner_id'], 'product_id' => $d['product_id'],
                'line_code' => $d['line_code'], 'beneficiary_type' => $d['beneficiary_type']])->whereDate('effective_from', $d['effective_from'])->first();

            return $dupe ? ['status' => 'DUPLICATE', 'key' => $key, 'matches' => $dupe->id] : ['status' => 'NEW', 'key' => $key];
        } catch (\Throwable $e) {
            return ['status' => 'ERROR', 'error' => $this->error($e)];
        }
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $actor ?? throw ValidationException::withMessages(['actor' => ['A signed-in approver is required.']]);

        return $this->commissions->createRule($this->resolve($row, $params), $actor)->id;
    }

    public function finish(array $params): void {}

    private function resolve(array $row, array $params): array
    {
        $carrier = $this->carrier($row);
        $partner = filled($row['broker'] ?? null) ? $this->broker(['legal_name' => $row['broker']]) : null;
        $productId = null;
        if (filled($row['product_code'] ?? null)) {
            $productId = InsuranceProduct::where('carrier_id', $carrier->id)->where('code', $row['product_code'])->orderByDesc('version')->value('id')
                ?? throw ValidationException::withMessages(['product_code' => ["Unknown product {$row['product_code']} for this insurer"]]);
        }
        $basis = strtoupper(trim((string) ($row['basis_type'] ?? '')));
        $beneficiary = strtoupper(trim((string) ($row['beneficiary_type'] ?? '')));
        if (! in_array($basis, self::BASIS_TYPES, true)) {
            throw ValidationException::withMessages(['basis_type' => ["Unknown basis_type {$basis}"]]);
        }
        if (! in_array($beneficiary, self::BENEFICIARIES, true)) {
            throw ValidationException::withMessages(['beneficiary_type' => ["Unknown beneficiary_type {$beneficiary}"]]);
        }
        $rate = trim((string) ($row['rate_percent'] ?? ''));
        $fixed = trim((string) ($row['fixed_amount'] ?? ''));
        if ($basis === 'FIXED_AMOUNT' ? ! is_numeric($fixed) : ! is_numeric($rate)) {
            throw ValidationException::withMessages(['rate' => ['The rate (or fixed amount) comes from the source agreement; it is never defaulted']]);
        }
        if ($rate !== '' && ((float) $rate < 0 || (float) $rate > 100)) {
            throw ValidationException::withMessages(['rate_percent' => ['rate_percent must be between 0 and 100']]);
        }
        $agreementId = null;
        if (filled($row['source_agreement_number'] ?? null)) {
            $agreementId = DB::table('carrier_broker_agreements')->where('carrier_id', $carrier->id)->where('agreement_number', $row['source_agreement_number'])->value('id')
                ?? throw ValidationException::withMessages(['source_agreement_number' => ['Unknown agreement for this insurer']]);
        }
        if ($agreementId === null && blank($row['source_document'] ?? null)) {
            throw ValidationException::withMessages(['source' => ['A source agreement or source document is required (PENDING_PRIVATE_SOURCE gate)']]);
        }
        if (blank($row['effective_from'] ?? null)) {
            throw ValidationException::withMessages(['effective_from' => ['effective_from is required']]);
        }
        $clawback = $row['clawback_rule'] ?? null;

        return ['tenant_id' => $params['tenant_id'], 'carrier_id' => $carrier->id, 'partner_id' => $partner?->id, 'product_id' => $productId,
            'line_code' => filled($row['line_code'] ?? null) ? strtoupper((string) $row['line_code']) : null, 'agreement_id' => $agreementId,
            'effective_from' => (string) $row['effective_from'], 'effective_until' => ($row['effective_until'] ?? null) ?: null,
            'basis_points' => $rate === '' ? 0 : (int) round((float) $rate * 100), 'holdback_basis_points' => 0,
            'fixed_amount_minor' => $fixed === '' ? null : (int) $fixed, 'currency' => strtoupper((string) ($row['currency'] ?? '') ?: 'XAF'),
            'beneficiary_type' => $beneficiary, 'basis_type' => $basis, 'cima_branch_code' => ($row['cima_branch_code'] ?? null) ?: null,
            'channel' => ($row['channel'] ?? null) ?: null, 'earning_event' => ($row['earning_event'] ?? null) ?: null,
            'clawback_rule' => is_string($clawback) && $clawback !== '' ? ['text' => $clawback] : (is_array($clawback) ? $clawback : null),
            'tax_treatment' => ($row['tax_treatment'] ?? null) ?: null, 'source_document' => ($row['source_document'] ?? null) ?: null,
            'data_status' => 'PENDING_VERIFICATION'];
    }
}
