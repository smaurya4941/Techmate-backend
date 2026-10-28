<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the Next.js frontend to drop its cached public pages after the admin
 * changes catalogue or CMS content. Optional: with no URL configured the
 * frontend's own time-based revalidation (60s) still applies.
 */
class FrontendRevalidator
{
    public static function configured(): bool
    {
        return filled(config('techmate.frontend.revalidate_url')) && filled(config('techmate.frontend.revalidate_secret'));
    }

    /** Queues the ping to run after the HTTP response so admin saves never wait on the frontend. */
    public static function notify(string $reason): void
    {
        if (! self::configured()) {
            return;
        }

        dispatch(fn () => self::send($reason))->afterResponse();
    }

    public static function send(string $reason): void
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['X-Revalidate-Secret' => (string) config('techmate.frontend.revalidate_secret')])
                ->acceptJson()
                ->post((string) config('techmate.frontend.revalidate_url'), ['reason' => $reason]);

            if ($response->failed()) {
                Log::warning('Frontend revalidation was rejected.', ['status' => $response->status(), 'reason' => $reason]);
            }
        } catch (Throwable $e) {
            Log::warning('Frontend revalidation failed.', ['exception' => $e::class, 'reason' => $reason]);
        }
    }
}
