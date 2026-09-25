<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Every command scheduled here must exist — tests/Feature/Wave16Lifecycle/ScheduleTest.php enforces it.
Schedule::command('settlements:prepare')->weeklyOn(1, '02:00')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();
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
