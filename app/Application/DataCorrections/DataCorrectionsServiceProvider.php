<?php

declare(strict_types=1);

namespace App\Application\DataCorrections;

use App\Application\DataCorrections\Console\TimestampAffectedReportCommand;
use Illuminate\Support\ServiceProvider;

/** Owner decision 19 — audited historical data corrections (currently: read-only timestamp affected-row report). */
final class DataCorrectionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TimestampAffectedReportCommand::class]);
        }
    }
}
