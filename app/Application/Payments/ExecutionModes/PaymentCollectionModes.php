<?php

declare(strict_types=1);

namespace App\Application\Payments\ExecutionModes;

use App\Application\Capabilities\CapabilityResolver;

/**
 * REQ-PAY-014 — what each PAYMENT capability mode (CapabilityCatalogue::CAPABILITIES['PAYMENT']) means for
 * money: who collects from the payer, who holds the funds until settlement, which account is credited,
 * and whether OPES itself prompts the payer. The semantics are frozen onto payment_intents.collection_semantics
 * when the mode is assigned, so a later profile/agreement change never rewrites where an old payment went.
 */
final class PaymentCollectionModes
{
    public const MODES = ['BROKER_COLLECTION', 'INSURER_COLLECTION', 'MOBILE_MONEY', 'BANK', 'EXTERNAL_PROVIDER'];

    /** Short spelling used by the operating-model docs. */
    public const ALIASES = ['EXTERNAL' => 'EXTERNAL_PROVIDER', 'CARRIER_COLLECTION' => 'INSURER_COLLECTION', 'EXTERNAL_GATEWAY' => 'EXTERNAL_PROVIDER'];

    /** mode => [collector, funds_holder, default credited account, OPES prompts payer, remittance to carrier needed] */
    private const SEMANTICS = [
        'BROKER_COLLECTION' => ['BROKER', 'BROKER', 'BROKER_PREMIUM_TRUST_ACCOUNT', false, true],
        'INSURER_COLLECTION' => ['CARRIER', 'CARRIER', 'CARRIER_COLLECTION_ACCOUNT', false, false],
        'MOBILE_MONEY' => ['PLATFORM', 'PAYMENT_SERVICE_PROVIDER', 'PLATFORM_COLLECTION_ACCOUNT', true, true],
        'BANK' => ['BANK', 'BANK', 'BANK_COLLECTION_ACCOUNT', false, true],
        'EXTERNAL_PROVIDER' => ['EXTERNAL_GATEWAY', 'EXTERNAL_GATEWAY', 'EXTERNAL_GATEWAY_SETTLEMENT_ACCOUNT', false, true],
    ];

    public static function normalize(string $mode): ?string
    {
        $mode = strtoupper($mode);
        $mode = self::ALIASES[$mode] ?? $mode;

        return in_array($mode, self::MODES, true) ? $mode : null;
    }

    /**
     * The collection mode for a resolved/pinned PAYMENT capability. A generic execution mode maps to its
     * natural collection mode; with no explicit profile row (source DEFAULT) the payment's own provider
     * decides, so existing mobile-money payments keep working unchanged.
     */
    public static function fromResolved(string $mode, string $source, ?string $provider = null): string
    {
        if ($specific = self::normalize($mode)) {
            return $specific;
        }
        if ($source === CapabilityResolver::SOURCE_DEFAULT) {
            return 'MOBILE_MONEY'; // every PaymentAdapterRegistry provider is a PSP the platform prompts through
        }

        return match ($mode) {
            'CONFIGURED', 'HYBRID' => 'MOBILE_MONEY',
            'REMOTE_API' => 'EXTERNAL_PROVIDER',
            default => 'BROKER_COLLECTION',
        };
    }

    /**
     * @param  array<string,mixed>  $config  capability-mode config (profile / agreement); `credited_account` and
     *                                       `funds_holder` override the defaults
     * @return array{mode:string,collector:string,funds_holder:string,credited_account:string,on_platform_prompt:bool,requires_remittance:bool,requires_reconciliation:bool,provider:?string}
     */
    public static function semantics(string $mode, array $config = [], ?string $provider = null): array
    {
        [$collector, $holder, $account, $onPlatform, $remit] = self::SEMANTICS[$mode];

        return [
            'mode' => $mode,
            'collector' => $collector,
            'funds_holder' => (string) ($config['funds_holder'] ?? $holder),
            'credited_account' => (string) ($config['credited_account'] ?? $account),
            'on_platform_prompt' => $onPlatform,
            'requires_remittance' => $remit,
            'requires_reconciliation' => ! $onPlatform,
            'provider' => $mode === 'MOBILE_MONEY' ? $provider : null,
        ];
    }
}
