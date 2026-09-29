<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Operations\Launch\LaunchPreflight;
use Illuminate\Console\Command;

/** Launch 2026-10-02 (S7): PASS / WARN / FAIL for everything go-live needs, with the exact fix. Exit 1 on any FAIL. */
final class LaunchPreflightCommand extends Command
{
    protected $signature = 'launch:preflight
        {--send : also send a test e-mail to SUPPORT_EMAIL (else MAIL_FROM_ADDRESS)}
        {--wait=20 : seconds to wait for the queue heartbeat job}
        {--no-queue-probe : do not dispatch the queue heartbeat job}
        {--backups= : backup directory (default OPS_DR_BACKUP_PATH, else <app root>/../../backups)}
        {--locale=en : en | fr}
        {--json : print JSON instead of text}';

    protected $description = 'Check everything a real launch needs (PASS / WARN / FAIL with the exact fix).';

    public function handle(LaunchPreflight $preflight): int
    {
        app()->setLocale(in_array($this->option('locale'), ['en', 'fr'], true) ? (string) $this->option('locale') : 'en');
        $results = $preflight->run([
            'probe_queue' => ! $this->option('no-queue-probe'),
            'queue_wait' => (int) $this->option('wait'),
            'send_mail' => (bool) $this->option('send'),
            'backup_path' => $this->option('backups') ?: null,
        ]);
        $summary = LaunchPreflight::summary($results);

        if ($this->option('json')) {
            $this->line((string) json_encode(['summary' => $summary, 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($results as $r) {
                $tag = match ($r['status']) { LaunchPreflight::PASS => '<fg=green>PASS</>', LaunchPreflight::WARN => '<fg=yellow>WARN</>', default => '<fg=red>FAIL</>' };
                $this->line(sprintf('[%s] %-28s %s', $tag, __('launch_preflight.checks.'.$r['key']), $r['detail']));
                if ($r['fix'] !== null) {
                    $this->line('       → '.$r['fix']);
                }
            }
            $this->newLine();
            $this->line(__('launch_preflight.summary', ['pass' => $summary['PASS'], 'warn' => $summary['WARN'], 'fail' => $summary['FAIL']]));
        }

        return $summary['FAIL'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
