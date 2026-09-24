<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Vehicles\MotorRiskSchema;
use App\Application\Vehicles\RiskAssetVehicleSync;
use Database\Seeders\VehicleMasterDataSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Seeds the Cameroon Vehicle Institutional Master Data
 * (database/data/cameroon_vehicle_master_2026.json) and reconciles vehicle
 * risk assets against it (make/model ids by code, name or alias). Allowed in
 * production; idempotent; never deletes; admin edits are preserved. Wired
 * into `php artisan optimize` (VehicleMasterServiceProvider).
 */
final class SeedVehicleMasterData extends Command
{
    protected $signature = 'opesinsure:seed-vehicles';

    protected $description = 'Idempotently seed vehicle makes, models, aliases and reference values, then reconcile vehicle risk assets. Never deletes.';

    public function handle(): int
    {
        try {
            $seeder = $this->laravel->make(VehicleMasterDataSeeder::class);
            $seeder->run();
            $reconciled = $this->laravel->make(RiskAssetVehicleSync::class)->reconcileAll();
            if (MotorRiskSchema::upgradeStoredLine()) {
                $this->components->info('MOTOR wizard schema upgraded to vehicle make/model selectors.');
            }
        } catch (Throwable $e) {
            // Never fail a deploy's optimize step; existing master data stays intact.
            report($e);
            $this->components->error('Vehicle master data seeding failed: '.$e->getMessage());

            return $this->laravel->runningUnitTests() ? self::FAILURE : self::SUCCESS;
        }

        $created = array_filter($seeder->counts);
        $this->components->info('Vehicle master data seeded'.($created === [] ? ' (no changes)' : ': '.collect($created)->map(fn ($n, $k) => "$k +$n")->join(', '))."; vehicle records reconciled: $reconciled.");

        return self::SUCCESS;
    }
}
