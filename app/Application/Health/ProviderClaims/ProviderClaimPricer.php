<?php

declare(strict_types=1);

namespace App\Application\Health\ProviderClaims;

/**
 * REQ-HLT-003 — explanation of benefits for one invoice line against the contracted tariff line (Batch 13A).
 * allowed = min(billed, qty × contracted price); copay = min(allowed, qty × tariff copay);
 * insurer share = round((allowed − copay) × insurer share %); member share = allowed − insurer share; rejected = billed − allowed.
 * Pure arithmetic in minor units; no I/O.
 */
final class ProviderClaimPricer
{
    public const REASONS = ['ABOVE_TARIFF', 'NO_CONTRACTED_TARIFF', 'NOT_ELIGIBLE', 'NOT_COVERED', 'NOT_MEDICALLY_NECESSARY', 'DUPLICATE', 'NO_PREAUTH', 'BENEFIT_LIMIT', 'DOCUMENTATION', 'OTHER'];

    /**
     * @param  object|null  $tariff  provider_tariff_lines row (contracted_price_minor, copay_minor, insurer_share_percent) or null
     * @return array{allowed_minor:int, copay_minor:int, insurer_share_minor:int, member_share_minor:int, rejected_minor:int, decision:string, reason_code:?string, explanation:string}
     */
    public function price(int $quantity, int $unitPriceMinor, ?object $tariff, ?int $allowedCapMinor = null, ?string $rejectReason = null, ?string $note = null): array
    {
        $billed = $quantity * $unitPriceMinor;
        if ($rejectReason !== null || $tariff === null) {
            $reason = $rejectReason ?? 'NO_CONTRACTED_TARIFF';

            return $this->eob($billed, 0, 0, 0, $reason, $note ?? ($tariff === null ? 'No approved contracted tariff for this service on the service date.' : 'Line rejected.'));
        }
        $cap = $quantity * (int) $tariff->contracted_price_minor;
        $allowed = min($billed, $cap);
        $reason = $billed > $cap ? 'ABOVE_TARIFF' : null;
        $explanation = $billed > $cap ? "Billed above the contracted price ({$quantity} × {$tariff->contracted_price_minor})." : 'Priced at the contracted tariff.';
        if ($allowedCapMinor !== null && $allowedCapMinor < $allowed) {
            $allowed = max(0, $allowedCapMinor);
            $reason = 'OTHER';
            $explanation = $note ?? 'Allowed amount reduced on review.';
        }
        $copay = min($allowed, $quantity * (int) $tariff->copay_minor);
        $insurer = (int) round(($allowed - $copay) * ((float) $tariff->insurer_share_percent) / 100);

        return $this->eob($billed, $allowed, $copay, $insurer, $reason, $note ?? $explanation);
    }

    private function eob(int $billed, int $allowed, int $copay, int $insurer, ?string $reason, string $explanation): array
    {
        $decision = $allowed === 0 ? 'REJECTED' : ($allowed < $billed ? 'PARTIALLY_APPROVED' : 'APPROVED');

        return [
            'allowed_minor' => $allowed, 'copay_minor' => $copay, 'insurer_share_minor' => $insurer, 'member_share_minor' => $allowed - $insurer,
            'rejected_minor' => $billed - $allowed, 'decision' => $decision, 'reason_code' => $reason, 'explanation' => $explanation,
        ];
    }
}
