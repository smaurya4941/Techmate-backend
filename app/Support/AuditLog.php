<?php

namespace App\Support;

use App\Models\AuditEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Business audit trail: persisted in audit_logs (append-only, super-admin readable)
 * and mirrored to the application log. Callers pass identifiers, field names and
 * before/after values only: never passwords, tokens or customer message bodies.
 */
class AuditLog
{
    /**
     * @param  array<string, mixed>  $context
     * @param  Model|null  $subject  the affected record, when there is one
     */
    public static function record(string $event, array $context = [], ?Model $subject = null): void
    {
        Log::info('admin.'.$event, ['actor_id' => Auth::id(), ...$context]);

        try {
            AuditEntry::create([
                'event' => $event,
                'actor_id' => Auth::id(),
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'context' => $context ?: null,
            ]);
        } catch (Throwable $e) {
            // The audit table must never take a business action down with it; the log mirror remains.
            Log::error('Could not persist audit entry.', ['event' => $event, 'exception' => $e::class]);
        }
    }

    /** @param  array<string, mixed>  $context */
    public static function failure(string $event, array $context = []): void
    {
        Log::warning('admin.'.$event, [
            'actor_id' => Auth::id(),
            ...$context,
        ]);
    }
}
