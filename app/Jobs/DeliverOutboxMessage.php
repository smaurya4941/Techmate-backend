<?php

namespace App\Jobs;

use App\Models\NotificationOutbox;
use App\Notifications\RenderedMail;
use App\Services\OutboxMessages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends one outbox row. The row is claimed with a single conditional UPDATE, so two
 * workers (or a worker plus the scheduler) can never send the same message twice.
 * Retries are driven by the row (attempts, available_at), not by the queue's own
 * retry counter, so they survive worker restarts.
 */
class DeliverOutboxMessage implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Minutes to wait before attempt 2, 3, ... */
    private const BACKOFF_MINUTES = [1, 5, 15];

    public int $tries = 1;

    public function __construct(public readonly int $outboxId)
    {
        $this->onQueue(config('techmate.notifications.queue'));
    }

    public function handle(OutboxMessages $messages): void
    {
        $claimed = NotificationOutbox::query()
            ->whereKey($this->outboxId)
            ->where('status', 'pending')
            ->where('available_at', '<=', now())
            ->update(['status' => 'sending', 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);

        if (! $claimed) {
            return; // already sent, being sent by another worker, failed for good, or not due yet
        }

        $row = NotificationOutbox::query()->findOrFail($this->outboxId);

        try {
            $mail = $messages->build($row);

            if ($mail === null) {
                // The record it described no longer exists or no longer applies: nothing to send.
                $row->forceFill(['status' => 'sent', 'sent_at' => now(), 'last_error' => 'Skipped: source record no longer applies.'])->save();

                return;
            }

            Notification::route('mail', $row->recipient_email)->notifyNow(new RenderedMail($mail));

            $row->forceFill(['status' => 'sent', 'sent_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $e) {
            $this->recordFailure($row, $e);
        }
    }

    private function recordFailure(NotificationOutbox $row, Throwable $e): void
    {
        $exhausted = $row->attempts >= NotificationOutbox::MAX_ATTEMPTS;
        $delay = self::BACKOFF_MINUTES[min($row->attempts, count(self::BACKOFF_MINUTES)) - 1] ?? 15;

        $row->forceFill([
            'status' => $exhausted ? 'failed' : 'pending',
            'failed_at' => $exhausted ? now() : null,
            'available_at' => $exhausted ? $row->available_at : now()->addMinutes($delay),
            'last_error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500),
        ])->save();

        Log::error('Outbox delivery attempt failed.', [
            'outbox_id' => $row->id,
            'type' => $row->type,
            'attempt' => $row->attempts,
            'final' => $exhausted,
            'exception' => $e::class,
        ]);

        if (! $exhausted) {
            self::dispatch($row->id)->delay(now()->addMinutes($delay));
        }
    }
}
