<?php

declare(strict_types=1);

namespace App\Application\Demo;

use App\Models\PartyContact;
use App\Models\PaymentIntentRecord;
use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\DemoMobileAccountSeeder;
use Illuminate\Validation\ValidationException;

/**
 * The single answer to "is this a seeded demo persona?" (audit A2).
 *
 * Demo mode stays on for testing, but its fake outcomes — the fake payment
 * adapter and the straight-through DemoPurchaseSettler — must only ever
 * touch the seeded persona accounts (DemoMobileAccountSeeder::otpPhones()),
 * never a real user who happens to sign up on a demo-mode server. Always
 * false while demo mode is off.
 */
final class DemoPersonas
{
    /** @return list<string> */
    public static function phones(): array
    {
        return config('demo.enabled') ? DemoMobileAccountSeeder::otpPhones() : [];
    }

    public static function isUser(?User $user): bool
    {
        if (! $user || ! config('demo.enabled')) {
            return false;
        }
        if ($user->phone_e164 && in_array($user->phone_e164, self::phones(), true)) {
            return true;
        }

        return self::isParty($user->party_id);
    }

    public static function isParty(?string $partyId): bool
    {
        if (! $partyId || ! config('demo.enabled')) {
            return false;
        }

        return PartyContact::where('party_id', $partyId)->where('type', 'PHONE')->whereIn('normalized_value', self::phones())->exists()
            || User::where('party_id', $partyId)->whereIn('phone_e164', self::phones())->exists();
    }

    public static function ownsProposal(?Proposal $proposal): bool
    {
        return $proposal !== null && self::isParty($proposal->party_id);
    }

    public static function ownsPayment(PaymentIntentRecord $payment): bool
    {
        return self::ownsProposal($payment->proposal);
    }

    /**
     * provider=fake is a test double: accepted in local/testing environments,
     * or for a demo persona on a demo server — never for a real user.
     */
    public static function fakeProviderAllowed(?User $user): bool
    {
        return app()->environment('local', 'testing') || self::isUser($user);
    }

    public static function assertProviderAllowed(string $provider, ?User $user): void
    {
        if ($provider === 'fake' && ! self::fakeProviderAllowed($user)) {
            throw ValidationException::withMessages(['provider' => __('wave4.provider_not_configured')]);
        }
    }
}
