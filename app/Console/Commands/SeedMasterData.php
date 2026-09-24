<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\MasterDataSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Seeds institutional master data from every database/data/master_data/*.json.
 * Allowed in production; idempotent; never deletes; preserves admin edits.
 * Runs on every deploy through `php artisan optimize` (MasterDataServiceProvider).
 */
final class SeedMasterData extends Command
{
    protected $signature = 'opesinsure:seed-master-data {--path= : Alternative directory of master data JSON files}';

    protected $description = 'Idempotently seed institutional master data (all non-vehicle insurable objects and core reference data). Never deletes.';

    public function handle(): int
    {
        $seeder = $this->laravel->make(MasterDataSeeder::class);
        try {
            $seeder->run($this->option('path') ?: null);
        } catch (Throwable $e) {
            report($e);
            $this->components->error('Master data seeding failed: '.$e->getMessage());

            // Never fail a deploy's optimize step; data already present stays intact.
            return $this->laravel->runningUnitTests() ? self::FAILURE : self::SUCCESS;
        }

        // Upgrade stored non-motor wizard schemas seeded before master data existed
        // (fields/steps only; the stored rating `required` keys are kept).
        foreach (\App\Application\Catalogue\NonMotorRiskSchemas::all() as $code => $schema) {
            $line = \App\Models\InsuranceLine::where('code', $code)->first();
            $stored = $line?->risk_schema ?? [];
            if ($line && (int) ($stored['version'] ?? 1) < $schema['version']) {
                $line->update(['risk_schema' => ['required' => $stored['required'] ?? $schema['required'], 'version' => $schema['version'], 'steps' => $schema['steps'], 'fields' => $schema['fields']]]);
                $this->components->info("Risk schema $code upgraded to master-data fields (v{$schema['version']}).");
            }
        }

        $changed = array_filter($seeder->counts, fn ($c) => array_sum($c) > 0);
        $this->components->info('Master data seeded'.($changed === [] ? ' (no changes).' : ': '.collect($changed)->map(fn ($c, $d) => "$d +{$c['created']}/~{$c['updated']}/@{$c['aliases']}")->join(', ')));
        foreach ($seeder->warnings as $w) {
            $this->components->warn($w);
        }

        return self::SUCCESS;
    }
}
