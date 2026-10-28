<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Focused admin audit trail on the existing Laravel log. Lead history lives in
 * lead_activities; this covers role, settings, assignment and quotation changes.
 * Callers pass identifiers and field names only: never passwords, tokens or
 * customer message bodies.
 */
class AuditLog
{
    /** @param  array<string, mixed>  $context */
    public static function record(string $event, array $context = []): void
    {
        Log::info('admin.'.$event, [
            'actor_id' => Auth::id(),
            ...$context,
        ]);
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
