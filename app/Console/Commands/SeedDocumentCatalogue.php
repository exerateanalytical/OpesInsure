<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DocumentCatalogueSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Seeds the canonical Insurance Document Type Registry, document packs, class
 * applicability and the Document Requirement Matrix. Allowed in production;
 * idempotent; never deletes (stale seeded rows are deactivated). Wired into
 * `php artisan optimize` by DocumentCatalogueServiceProvider.
 */
final class SeedDocumentCatalogue extends Command
{
    protected $signature = 'opesinsure:seed-document-catalogue';

    protected $description = 'Idempotently seed the document type registry, packs and requirement matrix. Never deletes.';

    public function handle(): int
    {
        try {
            $seeder = $this->laravel->make(DocumentCatalogueSeeder::class);
            $seeder->run();
        } catch (Throwable $e) {
            // Never fail a deploy's optimize step; data already present stays intact.
            report($e);
            $this->components->error('Document catalogue seeding failed: '.$e->getMessage());

            return $this->laravel->runningUnitTests() ? self::FAILURE : self::SUCCESS;
        }
        $created = array_filter($seeder->counts);
        $this->components->info('Document catalogue seeded'.($created === [] ? ' (no changes).' : ': '.collect($created)->map(fn ($n, $k) => "$k +$n")->join(', ')));

        return self::SUCCESS;
    }
}
