<?php

declare(strict_types=1);

namespace App\Application\Agents;

use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Security review 2026-09-27 item 4. An agent's commission is paid only to the MoMo number registered on the partner
 * profile (compliance.momo_phone_e164), never to a number typed into the withdrawal request, and a newly registered
 * number is on a cooling-off period (config payments.payout_destination_cooling_off_hours, default 24h) before any
 * withdrawal to it is accepted. That way a stolen session that passes one step-up cannot re-point the payout and cash
 * out in the same sitting: the owner gets the PAYOUT_DESTINATION_CHANGED alert and has the window to react.
 */
final class PayoutDestinationGuard
{
    /** Stamps momo_phone_changed_at when the registered payout number changes; returns the compliance array to save. */
    public function stampIfChanged(array $before, array $after): array
    {
        $old = $before['momo_phone_e164'] ?? null;
        $new = $after['momo_phone_e164'] ?? null;
        if ($new !== null && $new !== $old) {
            $after['momo_phone_changed_at'] = now()->toIso8601String();
            // The very first registration also cools off: before it, payouts had no registered destination at all.
        }

        return $after;
    }

    /** Asserts a withdrawal may go to $destination. Throws a 422 JSON response otherwise. */
    public function assertWithdrawable(array $compliance, string $destination, ?string $fallbackPhone): void
    {
        $registered = $compliance['momo_phone_e164'] ?? $fallbackPhone;
        if ($registered === null || self::normalise($destination) !== self::normalise($registered)) {
            throw new HttpResponseException(response()->json([
                'message' => __('wave12.payout_destination_not_registered'),
                'code' => 'PAYOUT_DESTINATION_NOT_REGISTERED',
            ], 422));
        }

        $until = $this->coolingOffUntil($compliance);
        if ($until !== null && $until->isFuture()) {
            throw new HttpResponseException(response()->json([
                'message' => __('wave12.payout_destination_cooling_off', ['until' => $until->toIso8601String()]),
                'code' => 'PAYOUT_DESTINATION_COOLING_OFF',
                'cooling_off_until' => $until->toIso8601String(),
            ], 422));
        }
    }

    public function coolingOffUntil(array $compliance): ?CarbonImmutable
    {
        $changedAt = $compliance['momo_phone_changed_at'] ?? null;
        if (! is_string($changedAt) || $changedAt === '') {
            return null;
        }

        return CarbonImmutable::parse($changedAt)->addHours(max(0, (int) config('payments.payout_destination_cooling_off_hours', 24)));
    }

    private static function normalise(string $phone): string
    {
        return (string) preg_replace('/[^0-9]/', '', $phone);
    }
}
