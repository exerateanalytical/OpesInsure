<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * Reference data, not demo data — runs in every environment including
 * production, same as the other seeders called from DatabaseSeeder::run().
 *
 * Passport's HasApiTokens::createToken() (used by MobileAuthService after a
 * successful OTP verification) resolves "the latest active personal access
 * client" automatically via ClientRepository::personalAccessClient() — it
 * does not need this client's ID anywhere in application code, only that
 * one exists. oauth_clients is stock, untouched Passport (see the
 * integration_clients table for the unrelated partner/client-credentials
 * business layer built in an earlier batch) — no conflict here.
 */
final class MobileOAuthClientSeeder extends Seeder
{
    public function run(): void
    {
        $exists = Passport::client()->newQuery()
            ->where('name', 'OpesInsure Mobile')
            ->where('revoked', false)
            ->get()
            ->contains(fn ($client) => $client->hasGrantType('personal_access'));

        if ($exists) {
            return;
        }

        app(ClientRepository::class)->createPersonalAccessGrantClient('OpesInsure Mobile');
    }
}
