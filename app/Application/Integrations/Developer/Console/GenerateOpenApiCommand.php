<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Console;

use App\Application\Integrations\Developer\OpenApiGenerator;
use Illuminate\Console\Command;

/** REQ-API-006 — writes docs/api/openapi.json from the route table. */
final class GenerateOpenApiCommand extends Command
{
    protected $signature = 'api:openapi {--output= : Output path (default docs/api/openapi.json)}';

    protected $description = 'Generate the OpenAPI document for /api/v1 from routes, validation rules and permission scopes';

    public function handle(OpenApiGenerator $generator): int
    {
        $path = $this->option('output') ?: base_path('docs/api/openapi.json');
        $doc = $generator->generate();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        $ops = array_sum(array_map('count', $doc['paths']));
        $this->info("Wrote {$path}: ".count($doc['paths'])." paths, {$ops} operations.");

        return self::SUCCESS;
    }
}
