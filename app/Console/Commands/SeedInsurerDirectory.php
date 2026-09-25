<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\CameroonInsurerDirectorySeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Idempotently enriches the official insurer register with the owner-supplied
 * institutional directory (contacts, website, HQ, branches, verification).
 * Never creates or deletes carriers. Wired into `optimize` (deploy).
 */
final class SeedInsurerDirectory extends Command
{
    protected $signature = 'opesinsure:seed-insurer-directory';

    protected $description = 'Enrich the official insurer register with the institutional directory (contacts, HQ, branches). Never deletes.';

    public function handle(CameroonInsurerDirectorySeeder $seeder): int
    {
        try {
            $seeder->run();
        } catch (Throwable $e) {
            report($e);
            $this->components->error('Insurer directory seeding failed: '.$e->getMessage());

            return $this->laravel->runningUnitTests() ? self::FAILURE : self::SUCCESS;
        }

        foreach (['all', 'stats'] as $key) {
            \Illuminate\Support\Facades\Cache::forget('public_site.directory.'.$key);
        }

        $r = $seeder->report;
        $this->components->info("Insurer directory: {$r['matched']} matched, {$r['offices']} offices, {$r['addresses']} head-office addresses.");
        foreach ($r['unmatched'] as $u) {
            $this->components->warn("Unmatched (not created): {$u}");
        }

        return self::SUCCESS;
    }
}
