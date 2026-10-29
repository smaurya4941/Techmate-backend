<?php

namespace App\Console\Commands;

use App\Jobs\DeliverOutboxMessage;
use App\Models\NotificationOutbox;
use Illuminate\Console\Command;

/**
 * Safety net for the outbox: re-dispatches rows that are due but have no live job
 * (process crashed after commit, worker restarted, queue flushed) and frees rows
 * stuck in "sending". Safe to run concurrently with workers: delivery claims atomically.
 */
class DispatchDueOutbox extends Command
{
    protected $signature = 'techmate:outbox-dispatch';

    protected $description = 'Dispatch pending notification outbox rows that are due.';

    public function handle(): int
    {
        $stuck = NotificationOutbox::query()->where('status', 'sending')
            ->where('claimed_at', '<', now()->subMinutes(10))
            ->update(['status' => 'pending']);

        $count = 0;
        NotificationOutbox::query()->where('status', 'pending')->where('available_at', '<=', now())
            ->orderBy('id')->limit(500)->pluck('id')
            ->each(function (int $id) use (&$count) {
                DeliverOutboxMessage::dispatch($id);
                $count++;
            });

        $this->info("Dispatched {$count} message(s); released {$stuck} stuck.");

        return self::SUCCESS;
    }
}
