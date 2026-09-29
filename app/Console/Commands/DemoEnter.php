<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Demo\DemoEnvironment;
use App\Application\Demo\DemoModeSwitch;
use Illuminate\Console\Command;

/** S13 — reverse of demo:exit, for a staging-like demo host. Refused in production unless DEMO_ALLOW_IN_PRODUCTION. */
final class DemoEnter extends Command
{
    protected $signature = 'demo:enter {--dry-run : Show what would change} {--confirm : Apply the change} {--seed : Run demo:seed afterwards}';

    protected $description = 'Turn demo mode back on (demo users reactivated, demo records visible, demo:seed active again).';

    public function handle(DemoModeSwitch $switch, DemoEnvironment $env): int
    {
        $dry = (bool) $this->option('dry-run');
        if ($dry === (bool) $this->option('confirm')) {
            $this->components->error('Pass exactly one of --dry-run or --confirm.');

            return self::INVALID;
        }
        if ($env->isProduction() && ! config('demo.allow_in_production')) {
            $this->components->error('Refusing to enter demo mode on APP_ENV=production (DEMO_ALLOW_IN_PRODUCTION is not true).');

            return self::FAILURE;
        }

        $plan = $switch->plan();
        DemoReport::print($this, $plan, $dry ? 'would reactivate' : 'reactivating');
        if ($dry) {
            return self::SUCCESS;
        }

        $switch->enter('artisan demo:enter ('.get_current_user().')');
        $this->callSilently('queue:restart');
        if ($this->option('seed')) {
            $this->call('demo:seed');
        }
        $this->components->info('Demo mode is on.');

        return self::SUCCESS;
    }
}
