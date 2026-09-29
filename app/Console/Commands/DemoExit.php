<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Demo\DemoModeSwitch;
use Illuminate\Console\Command;

/**
 * S13 — leave demo mode in one command. Stored in platform_settings (not .env), so it survives deploys and
 * the optimize-time demo:seed step becomes a no-op. Demo rows are hidden, never deleted; demo users are
 * SUSPENDED and their sessions revoked. Reverse with demo:enter.
 */
final class DemoExit extends Command
{
    protected $signature = 'demo:exit
        {--dry-run : Show what would change and change nothing}
        {--confirm : Apply the change}
        {--include-bootstrap-admin : Also suspend admin@opesinsure.local (only if it is not the owner\'s real login)}';

    protected $description = 'Turn demo mode off: stop demo seeding, the fixed OTP and demo sign-in, suspend demo users, hide demo records.';

    public function handle(DemoModeSwitch $switch): int
    {
        $dry = (bool) $this->option('dry-run');
        if ($dry === (bool) $this->option('confirm')) {
            $this->components->error('Pass exactly one of --dry-run or --confirm.');

            return self::INVALID;
        }
        $includeAdmin = (bool) $this->option('include-bootstrap-admin');

        $before = $switch->plan($includeAdmin);
        $this->components->info($dry ? 'DRY RUN — nothing will be changed.' : 'Leaving demo mode.');
        DemoReport::print($this, $before, $dry ? 'would suspend' : 'suspending');

        if ($dry) {
            return self::SUCCESS;
        }

        $after = $switch->exit('artisan demo:exit ('.get_current_user().')', $includeAdmin);
        $leaks = $switch->leaks();
        $this->callSilently('queue:restart'); // long-running workers re-read the stored decision

        $this->newLine();
        $this->components->twoColumnDetail('Rows newly flagged is_demo (created by demo personas)', $after['derived'] === [] ? '0' : json_encode($after['derived']));
        $this->components->twoColumnDetail('Demo mode (stored)', $after['mode']['stored'] === false ? 'OFF' : 'NOT OFF');
        $this->components->twoColumnDetail('Demo users still ACTIVE', (string) collect($after['users'])->where('status', 'ACTIVE')->count());
        $this->components->twoColumnDetail('Demo rows visible to normal reads', $leaks === [] ? '0' : json_encode($leaks));
        if ($after['bootstrap_admin'] && ! $includeAdmin) {
            $this->components->warn('admin@opesinsure.local was left '.$after['bootstrap_admin']['status'].'. If it is not a real login, re-run with --include-bootstrap-admin.');
        }

        if ($after['mode']['stored'] !== false || $leaks !== [] || collect($after['users'])->where('status', 'ACTIVE')->isNotEmpty()) {
            $this->components->error('Demo exit incomplete — see above.');

            return self::FAILURE;
        }
        $this->components->info('Demo mode is off. Demo records are kept but hidden; platform admins see them in the demo data view (GET /api/v1/demo/coverage and /api/v1/demo/records/{table}).');

        return self::SUCCESS;
    }
}
