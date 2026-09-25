<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/** Official insurer institutional directory: deploy (optimize) seeding hook. Runs after opesinsure:seed-regulatory. */
final class InstitutionDirectoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->optimizes(optimize: 'opesinsure:seed-insurer-directory', key: 'insurer-directory');
    }
}
