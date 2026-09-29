<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\CanonicalTemplates\CanonicalTemplatesSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Publishes the owner-approved canonical document templates DOC-001..DOC-220 (idempotent: an unchanged template is
 * skipped, a changed one becomes a new published version). Wired into `php artisan optimize` (deploy) by
 * DocumentCatalogueServiceProvider, after the document catalogue step it depends on.
 */
final class SeedCanonicalTemplates extends Command
{
    protected $signature = 'opesinsure:seed-canonical-templates';

    protected $description = 'Idempotently publish the canonical document templates DOC-001..DOC-220. Never deletes.';

    public function handle(): int
    {
        try {
            $seeder = $this->laravel->make(CanonicalTemplatesSeeder::class);
            $seeder->run();
        } catch (Throwable $e) {
            report($e);
            $this->components->error('Canonical template seeding failed: '.$e->getMessage());

            return $this->laravel->runningUnitTests() ? self::FAILURE : self::SUCCESS;
        }
        foreach ($seeder->report as $part => $r) {
            if (! empty($r['error'])) {
                $this->components->error("{$part}: ".$r['error']);

                continue;
            }
            $this->components->info(! empty($r['missing']) ? "{$part}: not present"
                : sprintf('%s: %d published, %d unchanged%s', $part, $r['published'] ?? 0, $r['unchanged'] ?? 0, empty($r['skipped']) ? '' : ', skipped: '.implode('; ', $r['skipped'])));
        }

        return self::SUCCESS;
    }
}
