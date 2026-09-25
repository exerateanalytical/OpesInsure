<?php

declare(strict_types=1);

namespace App\Application\Payments;

/**
 * Payment methods from the Workflow Institutional Data Master v1
 * ("payments_finance"), mapped onto the existing payment providers
 * (config/payments.php, payment_intents.provider: fake, maviance, campay,
 * mtn_momo, orange_money). No new provider codes are created: a method is a
 * business classification, a provider is the technical channel.
 */
final class PaymentMethodReference
{
    /** Workflow method => provider codes that collect it (empty = no online adapter; manual/offline only). */
    public const METHODS = [
        'CASH' => [],
        'MTN_MOMO' => ['mtn_momo', 'maviance', 'campay'],
        'ORANGE_MONEY' => ['orange_money', 'maviance', 'campay'],
        'BANK_TRANSFER' => [],
        'CARD' => [],
        'CHEQUE' => [],
        'DIRECT_DEBIT' => [],
        'OTHER' => [],
    ];

    /** Direct operator adapters: provider => method. Aggregators (maviance, campay) carry the method in their payload. */
    public const DIRECT_PROVIDERS = ['mtn_momo' => 'MTN_MOMO', 'orange_money' => 'ORANGE_MONEY'];

    /** Currencies: only XAF is VERIFIED by the owner. */
    public const CURRENCIES = ['XAF' => 'VERIFIED'];

    /** Masters: financial_institutions.bank is empty until sourced; mobile money is PARTIALLY_KNOWN, carried as UNVERIFIED. */
    public const MASTER_STATUS = [
        'financial_institutions.bank' => 'PENDING_SOURCE',
        'financial_institutions.mobile_money_provider' => 'UNVERIFIED',
    ];

    public static function isMethod(string $code): bool
    {
        return array_key_exists(strtoupper($code), self::METHODS);
    }

    public static function methodForProvider(string $provider): ?string
    {
        return self::DIRECT_PROVIDERS[strtolower($provider)] ?? null;
    }

    /** @return list<string> */
    public static function providersFor(string $method): array
    {
        return self::METHODS[strtoupper($method)] ?? [];
    }

    public static function isVerifiedCurrency(string $currency): bool
    {
        return (self::CURRENCIES[strtoupper($currency)] ?? null) === 'VERIFIED';
    }
}
