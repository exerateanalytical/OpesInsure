<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Brings demo accounts, roles and the demo scenario up to date. A no-op
 * unless DEMO_MODE_ENABLED is true. Every seeder it calls is idempotent
 * (firstOrCreate / updateOrCreate / keyed upserts), so it is safe on every
 * deploy.
 *
 * Wired into `php artisan optimize` (AppServiceProvider::boot ->
 * optimizes()), which deploy.sh already runs on each release, so demo data
 * is refreshed on deploy without changing the server-side script.
 */
final class SeedDemoData extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Idempotently seed demo accounts and the demo scenario (only when demo mode is enabled).';

    public function handle(): int
    {
        if (! config('demo.enabled')) {
            $this->components->info('Demo mode is off; nothing seeded.');

            return self::SUCCESS;
        }

        try {
            $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
            $this->call('db:seed', ['--class' => DemoScenarioSeeder::class, '--force' => true]);
        } catch (Throwable $e) {
            // Never fail a deploy's optimize step over demo data; say so loudly.
            report($e);
            $this->components->error('Demo seeding failed: '.$e->getMessage());

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }
}
