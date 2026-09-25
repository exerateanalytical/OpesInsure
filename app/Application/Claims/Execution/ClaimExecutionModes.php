<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution;

use App\Application\Capabilities\CapabilityCatalogue;
use App\Application\Capabilities\CapabilityResolver;

/**
 * REQ-CLM-011 — what each claims execution mode (CapabilityCatalogue::CAPABILITIES['CLAIMS_INTAKE']) means:
 * who submits the claim to the carrier, whether OPES queues an outbound carrier submission, and how the
 * carrier's acknowledgement / decision come back (signed API message, maker-checker manual entry, or none
 * because OPES itself decides).
 */
final class ClaimExecutionModes
{
    public const MODES = ['BROKER_ASSISTED', 'MANUAL_CARRIER', 'INSURER_PORTAL', 'API_SYNCHRONIZED', 'FULLY_DIGITAL'];

    public const INBOUND_MANUAL_ENTRY = 'MANUAL_ENTRY';

    public const INBOUND_SIGNED_API = 'SIGNED_API';

    public const INBOUND_NONE = 'NONE';

    /** mode => [submitter, outbound carrier submission, inbound channel, decided by] */
    private const SEMANTICS = [
        'BROKER_ASSISTED' => ['BROKER', true, self::INBOUND_MANUAL_ENTRY, 'CARRIER'],
        'MANUAL_CARRIER' => ['CARRIER_DESK', true, self::INBOUND_MANUAL_ENTRY, 'CARRIER'],
        'INSURER_PORTAL' => ['BROKER_ON_INSURER_PORTAL', true, self::INBOUND_MANUAL_ENTRY, 'CARRIER'],
        'API_SYNCHRONIZED' => ['PLATFORM_API', true, self::INBOUND_SIGNED_API, 'CARRIER'],
        'FULLY_DIGITAL' => ['PLATFORM', false, self::INBOUND_NONE, 'PLATFORM'],
    ];

    public static function normalize(string $mode): ?string
    {
        $mode = strtoupper($mode);

        return in_array($mode, self::MODES, true) ? $mode : null;
    }

    /** The claims mode of a resolved/pinned CLAIMS_INTAKE capability (a generic execution mode maps to its natural claims mode). */
    public static function fromResolved(string $mode, string $source = CapabilityResolver::SOURCE_PROFILE): string
    {
        if ($specific = self::normalize($mode)) {
            return $specific;
        }

        return match ($mode) {
            'CONFIGURED' => 'FULLY_DIGITAL',
            'HYBRID' => 'INSURER_PORTAL',
            'REMOTE_API' => 'API_SYNCHRONIZED',
            default => 'BROKER_ASSISTED',
        };
    }

    /** @return array{mode:string,execution_mode:string,submitter:string,outbound_submission:bool,inbound_channel:string,decided_by:string,maker_checker:bool} */
    public static function semantics(string $mode): array
    {
        [$submitter, $outbound, $inbound, $decider] = self::SEMANTICS[$mode];

        return [
            'mode' => $mode,
            'execution_mode' => CapabilityCatalogue::CAPABILITIES[ClaimExecution::CAPABILITY][$mode],
            'submitter' => $submitter,
            'outbound_submission' => $outbound,
            'inbound_channel' => $inbound,
            'decided_by' => $decider,
            'maker_checker' => $inbound === self::INBOUND_MANUAL_ENTRY,
        ];
    }
}
