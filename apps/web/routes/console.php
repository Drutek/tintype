<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tintype:reap')->everyMinute()->withoutOverlapping();
Schedule::command('tintype:gc')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('tintype:metrics')->everyMinute()->when(fn () => (bool) config('tintype.cloudwatch_namespace'));
