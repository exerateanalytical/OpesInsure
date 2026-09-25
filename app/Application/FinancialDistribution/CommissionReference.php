<?php

declare(strict_types=1);

namespace App\Application\FinancialDistribution;

use Illuminate\Validation\ValidationException;

/**
 * Commission reference codes from the Workflow Institutional Data Master v1
 * ("commission"). Existing commission rules keep working unchanged: the
 * rate is still basis_points on the premium; basis type, earning event and
 * settlement cycle are optional keys in commission_rule_versions.conditions
 * and are validated here when present. Clawback reasons classify the free
 * reason_code of commission_movements without restricting legacy codes.
 */
final class CommissionReference
{
    public const BASIS_TYPES = ['WRITTEN_PREMIUM', 'COLLECTED_PREMIUM', 'NET_PREMIUM', 'GROSS_PREMIUM', 'FIXED'];

    public const EARNING_EVENTS = ['POLICY_ISSUED', 'PREMIUM_COLLECTED', 'INSTALLMENT_PAID', 'RENEWAL_PAID'];

    public const CLAWBACK_REASONS = ['CANCELLATION', 'REFUND', 'PAYMENT_REVERSAL', 'POLICY_VOID', 'CORRECTION'];

    /** Workflow cycle => master-data finance.settlement_frequency code. */
    public const SETTLEMENT_CYCLES = [
        'DAILY' => 'DAILY', 'WEEKLY' => 'WEEKLY', 'BIWEEKLY' => 'FORTNIGHTLY',
        'MONTHLY' => 'MONTHLY', 'QUARTERLY' => 'QUARTERLY', 'CUSTOM' => 'CUSTOM',
    ];

    /**
     * Validates and normalises the optional reference keys of a rule's conditions.
     *
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>
     */
    public static function normaliseConditions(array $conditions): array
    {
        $errors = [];
        foreach (['basis_type' => self::BASIS_TYPES, 'earning_event' => self::EARNING_EVENTS] as $key => $allowed) {
            if (! array_key_exists($key, $conditions)) {
                continue;
            }
            $value = is_string($conditions[$key]) ? strtoupper($conditions[$key]) : null;
            if (! in_array($value, $allowed, true)) {
                $errors["conditions.$key"] = __('validation.in', ['attribute' => $key]);
            } else {
                $conditions[$key] = $value;
            }
        }
        if (array_key_exists('settlement_cycle', $conditions)) {
            $cycle = self::settlementFrequency(is_string($conditions['settlement_cycle']) ? $conditions['settlement_cycle'] : '');
            if ($cycle === null) {
                $errors['conditions.settlement_cycle'] = __('validation.in', ['attribute' => 'settlement_cycle']);
            } else {
                $conditions['settlement_cycle'] = $cycle;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $conditions;
    }

    /** Workflow cycle or stored frequency => stored finance.settlement_frequency code. */
    public static function settlementFrequency(string $cycle): ?string
    {
        $cycle = strtoupper($cycle);

        return self::SETTLEMENT_CYCLES[$cycle] ?? (in_array($cycle, self::SETTLEMENT_CYCLES, true) ? $cycle : null);
    }

    public static function isClawbackReason(string $code): bool
    {
        return in_array(strtoupper($code), self::CLAWBACK_REASONS, true);
    }
}
