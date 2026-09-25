<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Every command scheduled here must exist — tests/Feature/Wave16Lifecycle/ScheduleTest.php enforces it.
// D10: the weekly POLICY-basis 'settlements:prepare' schedule is retired (double-remittance risk with OBLIGATIONS
// batches); the command remains for manual catch-up and refuses policies already in an OBLIGATIONS batch.
Schedule::command('reconciliation:run')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('policies:expire')->dailyAt('00:15')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();
Schedule::command('policies:notify-expiry')->dailyAt('08:00')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();
Schedule::command('integration:dispatch-outbox')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('payments:poll-pending')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('notifications:dispatch-pending')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('policies:scan-issuance-queue')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('telemetry:prune')->dailyAt('03:00')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();
Schedule::command('renewals:sweep')->dailyAt('01:15')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();

// REQ-POL-008 / REQ-POL-010: in-force premium-to-cover sweep (GRACE → SUSPEND_ON_DEFAULT → LAPSED).
Artisan::command('policies:premium-cover-sweep', function (App\Application\Policies\Lapse\PremiumDefaultSweep $sweep) {
    $s = $sweep->run();
    $this->info("Evaluated: {$s['evaluated']}. Grace: {$s['grace']}. Defaulted: {$s['defaulted']}. Suspended: {$s['suspended']}. Lapsed: {$s['lapsed']}.");
})->purpose('Apply premium-cover rules to overdue instalments: grace, suspension on default, lapse.');
Schedule::command('policies:premium-cover-sweep')->dailyAt('00:45')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();

// REQ-ACC-003 (agent 10-8): open the current + next monthly accounting period for every period-controlled tenant.
Artisan::command('ledger:open-periods', function (App\Application\Ledger\Periods\AccountingPeriodService $periods) {
    $this->info('Periods opened: '.$periods->openUpcoming().'.');
})->purpose('Automatically open the next accounting period per tenant.');
Schedule::command('ledger:open-periods')->dailyAt('00:05')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();

// Batch 10-1 — REQ-COM-001: settled premium earns commission; approved + vested commission becomes payable.
Artisan::command('commissions:advance', function (App\Application\Commissions\Machine\CommissionLifecycleService $service) {
    $s = $service->advance();
    $this->info("Earned: {$s['earned']}. Payable: {$s['payable']}.");
})->purpose('Advance the commission machine: ACCRUED → EARNED on settled premium, APPROVED → PAYABLE once vested.');
Schedule::command('commissions:advance')->dailyAt('02:10')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();

// Agent C14 — REQ-CLM-014: auto-close settled claims that have been inactive (closure checklist must pass).
Artisan::command('claims:auto-close {--days=30 : inactivity window in days}', function (App\Application\Claims\Closure\ClaimAutoCloseSweep $sweep) {
    $s = $sweep->run((int) $this->option('days'));
    $this->info("Evaluated: {$s['evaluated']}. Closed: {$s['closed']}. Blocked: {$s['blocked']}. Skipped: {$s['skipped']}.");
})->purpose('Close inactive settled claims whose closure checklist passes.');
Schedule::command('claims:auto-close')->dailyAt('02:40')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();

// Agent C15 — REQ-REC-003 dunning run (+ REQ-REC-002 legal deadline sweep).
Artisan::command('collections:run', function (App\Application\Collections\CollectionService $service, App\Application\Claims\Recovery\Litigation\LegalMatterService $legal) {
    $s = $service->run();
    $missed = $legal->sweepDeadlines();
    $this->info("Notices: {$s['notices']}. Escalated: {$s['escalated']}. Promises kept/broken: {$s['promises_kept']}/{$s['promises_broken']}. Closed: {$s['closed']}. Legal deadlines missed: {$missed}.");
})->purpose('Advance overdue receivables through the dunning stages, evaluate promises-to-pay, escalate, and flag missed legal deadlines.');
Schedule::command('collections:run')->dailyAt('03:20')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();

// Agent B6 — REQ-OPS-001 scheduler heartbeat, REQ-OPS-002 restore verification, REQ-OPS-004 readiness report.
Artisan::command('ops:heartbeat', function (App\Application\Operations\SystemHealthService $health) {
    $health->beat();
})->purpose('Record the scheduler heartbeat shown by the operations console');
Schedule::command('ops:heartbeat')->everyMinute()->onOneServer();

Artisan::command('ops:verify-restore {--file= : restore this backup instead of the newest in operations.dr.restore.backup_path} {--skip-restore : compare an already-restored scratch database}', function (App\Application\Operations\RestoreVerificationService $service) {
    $x = $service->run(['backup_file' => $this->option('file') ?: null, 'skip_restore' => (bool) $this->option('skip-restore')]);
    $this->line((string) json_encode(['id' => $x->id, 'status' => $x->status, 'actual_rto_minutes' => $x->actual_rto_minutes, 'actual_rpo_minutes' => $x->actual_rpo_minutes, 'targets' => $x->evidence['targets'] ?? null, 'error' => $x->evidence['error'] ?? null], JSON_UNESCAPED_SLASHES));

    return $x->status === App\Application\Operations\RestoreVerificationService::PASSED ? 0 : 1;
})->purpose('Restore the newest backup into the scratch database and verify it (REQ-OPS-002)');

Artisan::command('ops:readiness-report {--path= : output path relative to the project root} {--fail-on-fail : exit 1 when any criterion fails}', function (App\Application\Operations\ProductionReadinessReport $report) {
    $data = $report->build();
    $path = base_path($this->option('path') ?: (string) config('operations.readiness.report_path'));
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, $report->markdown($data));
    $this->info(sprintf('%s: %d pass, %d fail, %d n/a', $path, $data['summary']['pass'], $data['summary']['fail'], $data['summary']['na']));

    return $this->option('fail-on-fail') && $data['summary']['fail'] > 0 ? 1 : 0;
})->purpose('Write the automatable production-readiness report (REQ-OPS-004)');
