<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Vehicles\VehicleDatasetImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * opesinsure:import-vehicle-dataset --path=<local engines.csv | tier-3 .json> [--dry-run] [--accept-license]
 *
 * Imports generations and engine variants for the Cameroon & Africa core
 * makes/models from a LOCAL copy of github.com/gor3a/vehicle-makes-models.
 * The dataset is ODbL v1.0 (attribution + share-alike on publicly used
 * adapted databases; upstream autoevolution.com). A real import needs
 * --accept-license, i.e. someone accountable has approved those terms.
 * --dry-run needs no acceptance and rolls everything back.
 */
final class ImportVehicleDataset extends Command
{
    protected $signature = 'opesinsure:import-vehicle-dataset
        {--path= : Local path to data/csv/engines.csv or a tier-3 JSON file}
        {--dry-run : Parse and match, report counts, write nothing}
        {--accept-license : Confirm the ODbL v1.0 terms (attribution, share-alike) were approved}';

    protected $description = 'Import generations + engine variants (specs) for core makes/models from a local global vehicle dataset file. Never creates makes/models; never downloads.';

    public function handle(VehicleDatasetImporter $importer): int
    {
        $path = (string) $this->option('path');
        if ($path === '') {
            $this->error('--path is required (local engines.csv or tier-3 JSON).');

            return self::INVALID;
        }
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $this->option('accept-license')) {
            $this->error('The dataset is licensed ODbL v1.0 (attribution + share-alike). Re-run with --accept-license once that is approved, or use --dry-run.');

            return self::FAILURE;
        }

        try {
            $counts = $importer->import($path, $dry);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['metric', 'count'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());
        if ($importer->unmatchedModels !== []) {
            $this->line('Dataset models of core makes not in the master (not imported; add via admin/review if wanted):');
            foreach (array_slice($importer->unmatchedModels, 0, 40, true) as $key => $n) {
                $this->line("  $key ($n rows)");
            }
        }
        $this->info($dry ? 'Dry run: nothing written.' : 'Import complete.');

        return self::SUCCESS;
    }
}
