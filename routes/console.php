<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The scheduler must actually run in each environment (a one-minute cron entry running
| `php artisan schedule:run`, or `php artisan schedule:work` locally) together with a queue
| worker (`php artisan queue:work`). See docs/sales-workflow.md#operations.
*/
Schedule::command('techmate:outbox-dispatch')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('techmate:follow-up-reminders')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('techmate:expire-quotations')->dailyAt('00:30')->timezone(config('techmate.business_timezone'))->onOneServer();
