<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Regulatory\CimaComplianceReport;
use Database\Seeders\CimaRegulatoryDictionarySeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Seeds the CIMA Regulatory Dictionary (database/data/cima_regulatory_master_2026.json),
 * applies default class → branch mappings to unmapped products and DEMO
 * authorizations for demo carriers. Allowed in production; idempotent; never
 * deletes. Wired into `php artisan optimize` (RegulatoryServiceProvider).
 */
final class SeedCimaRegulatoryDictionary extends Command
{
    protected $signature = 'opesinsure:seed-cima {--report : Print products lacking mappings/authorizations}';

    protected $description = 'Idempotently seed the CIMA Regulatory Dictionary (branches, micro branches, reporting, terminology). Never deletes.';

    public function handle(): int
    {
        try {
            $seeder = $this->laravel->make(CimaRegulatoryDictionarySeeder::class);
            $seeder->run();
        } catch (Throwable $e) {
            // Never fail a deploy's optimize step; the data already present stays intact.
            report($e);
            $this->components->error('CIMA dictionary seeding failed: '.$e->getMessage());

            return $this->laravel->runningUnitTests() ? self::FAILURE : self::SUCCESS;
        }

        $created = array_filter($seeder->counts);
        $this->components->info('CIMA dictionary seeded'.($created === [] ? ' (no changes).' : ': '.collect($created)->map(fn ($n, $k) => "$k +$n")->join(', ')));

        if ($this->option('report')) {
            $s = $this->laravel->make(CimaComplianceReport::class)->summary();
            $this->table(['Metric', 'Count'], collect($s['counts'])->map(fn ($v, $k) => [$k, $v])->values()->all());
            $this->line('Unmapped products:');
            foreach ($s['unmapped_products'] as $p) {
                $this->line("  {$p['code']} ({$p['line_code']}, {$p['status']}) — {$p['carrier']}");
            }
            $this->line('Products blocked for publication:');
            foreach ($s['blocked_products'] as $p) {
                $this->line("  {$p['code']} ({$p['status']}".($p['grandfathered'] ? ', grandfathered' : '').") — {$p['carrier']}: ".implode(' | ', $p['reasons']));
            }
        }

        return self::SUCCESS;
    }
}
