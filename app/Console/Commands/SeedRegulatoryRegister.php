<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\CameroonInsuranceRegisterSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Seeds the regulatory layer (official DGTCFM/MINFI register) — allowed in
 * production and independent of demo mode. Idempotent and never deletes.
 * Wired into `php artisan optimize` (AppServiceProvider), which deploy.sh
 * runs on every release, ahead of demo:seed.
 */
final class SeedRegulatoryRegister extends Command
{
    protected $signature = 'opesinsure:seed-regulatory {--country=CM : ISO country code} {--year=2026 : Register reference year}';

    protected $description = 'Idempotently seed the official insurance register (insurers, brokers, authorizations, class taxonomy). Never deletes.';

    public function handle(): int
    {
        $country = strtoupper((string) $this->option('country'));
        $year = (int) $this->option('year');
        if ($country !== 'CM' || $year !== CameroonInsuranceRegisterSeeder::YEAR) {
            $this->components->error("No official register dataset for {$country} {$year} (available: CM 2026).");

            return self::FAILURE;
        }

        try {
            $this->call('db:seed', ['--class' => CameroonInsuranceRegisterSeeder::class, '--force' => true]);
        } catch (Throwable $e) {
            // Never fail a deploy's optimize step; the data already present stays intact.
            report($e);
            $this->components->error('Regulatory seeding failed: '.$e->getMessage());

            return $this->laravel->runningUnitTests() ? self::FAILURE : self::SUCCESS;
        }

        return self::SUCCESS;
    }
}
