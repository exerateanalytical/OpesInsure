<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\PartyResolver;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * One-time operational tool, not scheduled: PartyResolver already
 * self-heals users.party_id lazily on first resolve, so this only exists to
 * eagerly backfill existing users (e.g. right after the migration lands)
 * instead of waiting for their next login/purchase-status/etc. call.
 */
final class BackfillUserPartyLinks extends Command
{
    protected $signature = 'identity:backfill-party-links';

    protected $description = 'Eagerly resolve users.party_id for existing users via PartyResolver\'s phone-match fallback.';

    public function handle(PartyResolver $resolver): int
    {
        $users = User::whereNull('party_id')->whereNotNull('phone_e164')->get();
        $linked = 0;

        foreach ($users as $user) {
            if ($resolver->forUser($user)) {
                $linked++;
            }
        }

        $this->info("Checked: {$users->count()}. Linked: {$linked}.");

        return self::SUCCESS;
    }
}
