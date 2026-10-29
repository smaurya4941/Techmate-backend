<?php

namespace App\Services;

use App\Jobs\DeliverOutboxMessage;
use App\Models\NotificationOutbox;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Transactional outbox. Call inside the same transaction as the change that requires
 * the e-mail: the row commits (or rolls back) with it, and the delivery job is only
 * dispatched after the commit. If the process dies between commit and dispatch, the
 * scheduled `outbox:dispatch-due` command picks the pending row up.
 */
class Outbox
{
    /**
     * @param  array<string, mixed>  $payload  identifiers only; mail is rendered from stored records at send time
     * @return NotificationOutbox|null null when the same dedupe key was already queued
     */
    public static function queue(string $type, ?string $recipient, string $dedupeKey, ?int $leadId = null, array $payload = []): ?NotificationOutbox
    {
        if (! $recipient) {
            return null;
        }

        try {
            $row = NotificationOutbox::query()->forceCreate([
                'type' => $type,
                'dedupe_key' => $dedupeKey,
                'recipient_email' => $recipient,
                'lead_id' => $leadId,
                'payload' => $payload ?: null,
                'status' => 'pending',
                'available_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        DB::afterCommit(fn () => DeliverOutboxMessage::dispatch($row->id));

        return $row;
    }

    /** Puts a failed message back in the queue without creating a second record. */
    public static function retry(NotificationOutbox $row): void
    {
        $reset = NotificationOutbox::query()->whereKey($row->getKey())->where('status', 'failed')->update([
            'status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'failed_at' => null,
        ]);

        if ($reset) {
            DeliverOutboxMessage::dispatch($row->getKey());
        }
    }
}
