<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Documents\Letterhead\InsurerLogoPack;
use Illuminate\Console\Command;

/**
 * Imports the owner-supplied logo pack of the 29 official insurers (resources/insurer-logos) as public-display
 * letterhead versions (InsurerLogoPack). Idempotent; never overwrites an insurer's own logo unless --force.
 */
final class ImportInsurerLogos extends Command
{
    protected $signature = 'opesinsure:import-insurer-logos
        {--path= : Pack directory (manifest.json + WebP files); defaults to resources/insurer-logos}
        {--dry-run : Report what would change without writing}
        {--force : Replace logos not from this pack and re-apply already imported artwork}';

    protected $description = 'Publish the owner-supplied insurer logo pack as public letterhead logos (idempotent, audited).';

    public function handle(InsurerLogoPack $pack): int
    {
        $rows = $pack->import($this->option('path') ?: null, (bool) $this->option('dry-run'), (bool) $this->option('force'));
        usort($rows, fn ($a, $b) => $a['number'] <=> $b['number']);

        $this->table(['#', 'Company', 'Carrier', 'Action', 'Version', 'Detail'],
            array_map(fn ($r) => [$r['number'], $r['company'], $r['carrier'] ?? '-', $r['action'], $r['version'] ?? '-', $r['detail'] ?? ''], $rows));

        $counts = array_count_values(array_column($rows, 'action'));
        ksort($counts);
        $this->components->info(($this->option('dry-run') ? '[dry run] ' : '').collect($counts)->map(fn ($n, $a) => "{$a}: {$n}")->implode(', '));

        return isset($counts['error']) || isset($counts['unmatched']) ? self::FAILURE : self::SUCCESS;
    }
}
