<?php

use Illuminate\Support\Facades\Schedule;

// Every command scheduled here must exist — tests/Feature/Wave16Lifecycle/ScheduleTest.php enforces it.
Schedule::command('settlements:prepare')->weeklyOn(1, '02:00')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();
Schedule::command('reconciliation:run')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('policies:expire')->dailyAt('00:15')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();
Schedule::command('policies:notify-expiry')->dailyAt('08:00')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();
Schedule::command('integration:dispatch-outbox')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('payments:poll-pending')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('notifications:dispatch-pending')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('telemetry:prune')->dailyAt('03:00')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();
