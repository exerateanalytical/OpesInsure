<?php

declare(strict_types=1);

namespace Database\Seeders\CanonicalTemplates;

use Illuminate\Database\Seeder;

/**
 * All canonical templates DOC-001..DOC-220, owner-approved and published (2026-09-28). Each part is optional so the
 * deploy works while parts are still being written. Run by `opesinsure:seed-canonical-templates` (deploy optimize hook).
 */
final class CanonicalTemplatesSeeder extends Seeder
{
    public const PARTS = [
        CanonicalTemplatesPartASeeder::class,
        'Database\\Seeders\\CanonicalTemplates\\CanonicalTemplatesPartBSeeder',
        'Database\\Seeders\\CanonicalTemplates\\CanonicalTemplatesPartCSeeder',
        'Database\\Seeders\\CanonicalTemplates\\CanonicalTemplatesPartDSeeder',
    ];

    /** @var array<string, array<string, mixed>> part => report */
    public array $report = [];

    public function run(): void
    {
        foreach (self::PARTS as $part) {
            if (! class_exists($part)) {
                $this->report[class_basename($part)] = ['missing' => true];

                continue;
            }
            // One failing part never blocks the others (each template publication is its own transaction).
            try {
                $seeder = app($part);
                $seeder->run();
                $this->report[class_basename($part)] = $seeder->report ?? [];
            } catch (\Throwable $e) {
                report($e);
                $this->report[class_basename($part)] = ['error' => $e->getMessage()];
                if (app()->runningUnitTests()) {
                    throw $e;
                }
            }
        }
    }
}
