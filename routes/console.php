<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('settlements:prepare')->weeklyOn(5, '00:00')->timezone('Africa/Douala')->withoutOverlapping()->onOneServer();
Schedule::command('reconciliation:run')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('policies:notify-expiry')->dailyAt('08:00')->timezone('Africa/Douala')->onOneServer();
Schedule::command('integration:dispatch-outbox')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('payments:poll-pending')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('notifications:dispatch-pending')->everyMinute()->withoutOverlapping()->onOneServer();
