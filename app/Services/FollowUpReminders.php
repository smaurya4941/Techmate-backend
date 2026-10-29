<?php

namespace App\Services;

use App\Models\FollowUp;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled reminder pass. Two notifications per task at most:
 *   1. a heads-up `remind_before_minutes` before it is due;
 *   2. one "overdue" notice once the due time has passed.
 * A conditional UPDATE claims each flag, so overlapping scheduler runs or parallel
 * workers can never notify twice; the outbox dedupe key is a second guard. Completed or
 * cancelled tasks are skipped, and rescheduling re-arms both flags.
 */
class FollowUpReminders
{
    /** @return array{reminders: int, overdue: int} */
    public function dispatchDue(): array
    {
        return ['reminders' => $this->reminders(), 'overdue' => $this->overdue()];
    }

    private function reminders(): int
    {
        $sent = 0;

        FollowUp::query()->active()
            ->whereNotNull('remind_before_minutes')->whereNull('reminder_sent_at')
            ->where('due_at', '>', now())
            ->with('assignee:id,email,is_active')
            ->orderBy('id')
            ->each(function (FollowUp $followUp) use (&$sent) {
                if ($followUp->due_at->copy()->subMinutes($followUp->remind_before_minutes)->isFuture()) {
                    return;
                }

                $sent += $this->claimAndQueue($followUp, 'reminder_sent_at', 'followup.reminder', 'reminder');
            });

        return $sent;
    }

    private function overdue(): int
    {
        $sent = 0;

        FollowUp::query()->active()
            ->whereNull('overdue_notified_at')->where('due_at', '<', now())
            ->with('assignee:id,email,is_active')
            ->orderBy('id')
            ->each(function (FollowUp $followUp) use (&$sent) {
                $sent += $this->claimAndQueue($followUp, 'overdue_notified_at', 'followup.overdue', 'overdue');
            });

        return $sent;
    }

    private function claimAndQueue(FollowUp $followUp, string $flag, string $type, string $suffix): int
    {
        return DB::transaction(function () use ($followUp, $flag, $type, $suffix) {
            $claimed = FollowUp::query()->whereKey($followUp->id)->active()->whereNull($flag)->update([$flag => now()]);

            if (! $claimed) {
                return 0;
            }

            $assignee = $followUp->assignee;
            if ($assignee && $assignee->is_active) {
                Outbox::queue($type, $assignee->email, "followup:{$followUp->id}:{$suffix}:{$followUp->due_at->timestamp}", $followUp->lead_id, ['follow_up_id' => $followUp->id]);
            }

            return 1;
        });
    }
}
