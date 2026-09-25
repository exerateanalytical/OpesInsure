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
            // Canonical document spec (security profiles, field requirements, shells) onto the same catalogue.
            $spec = $this->laravel->make(\Database\Seeders\CanonicalDocumentSpecSeeder::class);
            $spec->run();
        } catch (Throwable $e) {
            // Never fail a deploy's optimize step; data already present stays intact.
            report($e);
            $this->components->error('Document catalogue seeding failed: '.$e->getMessage());

            return $this->laravel->runningUnitTests() ? self::FAILURE : self::SUCCESS;
        }
        $created = array_filter($seeder->counts);
        $this->components->info('Document catalogue seeded'.($created === [] ? ' (no changes).' : ': '.collect($created)->map(fn ($n, $k) => "$k +$n")->join(', ')));
        $r = $spec->report;
        $this->components->info(isset($r['skipped']) ? 'Canonical document spec skipped: '.$r['skipped']
            : sprintf('Canonical document spec: %d records (%d PENDING_VERIFICATION mapping), %d catalogue types profiled, %d updated.', $r['specs'] ?? 0, $r['pending_verification'] ?? 0, $r['catalogue_types_mapped'] ?? 0, $r['catalogue_types_updated'] ?? 0));

        return self::SUCCESS;
    }
}
