<?php

declare(strict_types=1);

namespace App\Application\Kyc\Screening;

use App\Models\Party;

/**
 * REQ-KYC-001 screening hook (sanctions / PEP). An adapter either screens automatically and returns a
 * result, or (MANUAL_AUDITED) returns null so a reviewer records the outcome through ScreeningService::record().
 * No sanctions or PEP list ships with the platform; a real provider adapter is bound by configuration
 * (config kyc.screening.adapter) once the owner has contracted one. REQ-AML-001 (15A) extends this.
 */
interface ScreeningAdapter
{
    public function code(): string;

    /**
     * @param  'SANCTIONS'|'PEP'  $checkType
     * @return array{status: 'CLEAR'|'POSSIBLE_MATCH'|'CONFIRMED_MATCH', list_reference?: ?string, result?: array<string, mixed>}|null
     */
    public function screen(Party $party, string $checkType): ?array;
}
