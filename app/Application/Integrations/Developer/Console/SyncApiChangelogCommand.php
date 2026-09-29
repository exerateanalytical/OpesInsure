<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Console;

use App\Application\Integrations\Developer\Portal\ApiChangelogService;
use Illuminate\Console\Command;

/** DEV-014 — records API changes by diffing docs/api/openapi.json against the recorded changelog (run after api:openapi). */
final class SyncApiChangelogCommand extends Command
{
    protected $signature = 'api:changelog {--input= : OpenAPI document (default docs/api/openapi.json)}';

    protected $description = 'Diff the OpenAPI document against api_changelog_entries and record ADDED / CHANGED / REMOVED operations';

    public function handle(ApiChangelogService $changelog): int
    {
        $path = $this->option('input') ?: base_path('docs/api/openapi.json');
        if (! is_file($path)) {
            $this->error("No OpenAPI document at {$path}.");

            return self::FAILURE;
        }
        $n = $changelog->sync((array) json_decode((string) file_get_contents($path), true));
        $this->info("baseline {$n['baseline']}, added {$n['added']}, changed {$n['changed']}, removed {$n['removed']}.");

        return self::SUCCESS;
    }
}
