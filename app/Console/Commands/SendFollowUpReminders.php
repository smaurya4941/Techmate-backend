<?php

namespace App\Console\Commands;

use App\Services\FollowUpReminders;
use Illuminate\Console\Command;

class SendFollowUpReminders extends Command
{
    protected $signature = 'techmate:follow-up-reminders';

    protected $description = 'Queue reminders for due soon and overdue follow-up tasks.';

    public function handle(FollowUpReminders $reminders): int
    {
        $result = $reminders->dispatchDue();
        $this->info("Queued {$result['reminders']} reminder(s) and {$result['overdue']} overdue notice(s).");

        return self::SUCCESS;
    }
}
