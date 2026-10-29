<?php

namespace App\Services;

use App\Exceptions\GoogleReviewsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the business rating and reviews from Google Places API (New) "Place Details"
 * and normalises them for the public site. Google returns at most five reviews.
 *
 * The API key never leaves the server and Google's raw payload is not forwarded.
 * Results are cached briefly (Google limits how long place content may be stored),
 * and a failure is remembered for a minute so an outage cannot hammer the API.
 */
class GoogleReviewsService
{
    private const ENDPOINT = 'https://places.googleapis.com/v1/places/';

    private const CACHE_KEY = 'google-reviews:v1';

    private const FAILURE_KEY = 'google-reviews:v1:failure';

    private const FAILURE_SECONDS = 60;

    private const FIELD_MASK = 'displayName,rating,userRatingCount,googleMapsUri,reviews';

    private const MAX_TEXT_LENGTH = 2000;

    public function configured(): bool
    {
        return filled(config('services.google_places.api_key')) && filled(config('services.google_places.place_id'));
    }

    /**
     * @return array{businessName: ?string, rating: ?float, totalReviews: int, googleMapsUri: ?string, writeReviewUri: ?string, reviews: list<array<string, mixed>>}
     *
     * @throws GoogleReviewsException
     */
    public function get(): array
    {
        if (! $this->configured()) {
            throw GoogleReviewsException::notConfigured();
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        if (Cache::has(self::FAILURE_KEY)) {
            throw GoogleReviewsException::unavailable();
        }

        try {
            $data = $this->normalise($this->fetch());
        } catch (GoogleReviewsException $e) {
            Cache::put(self::FAILURE_KEY, true, self::FAILURE_SECONDS);

            throw $e;
        }

        Cache::put(self::CACHE_KEY, $data, max(60, (int) config('services.google_places.cache_seconds')));

        return $data;
    }

    /** @return array<string, mixed> */
    private function fetch(): array
    {
        $placeId = (string) config('services.google_places.place_id');

        try {
            $response = Http::timeout((int) config('services.google_places.timeout_seconds'))
                ->retry(1, 200, throw: false)
                ->acceptJson()
                ->withHeaders([
                    'X-Goog-Api-Key' => (string) config('services.google_places.api_key'),
                    'X-Goog-FieldMask' => self::FIELD_MASK,
                ])
                ->get(self::ENDPOINT.rawurlencode($placeId), [
                    'languageCode' => (string) config('services.google_places.language', 'en'),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Google Places request failed to connect.', ['error' => $e->getMessage()]);

            throw GoogleReviewsException::unavailable();
        }

        if ($response->failed()) {
            // Log Google's reason (bad key, billing off, wrong Place ID...) without ever echoing it to visitors.
            Log::error('Google Places returned an error.', [
                'status' => $response->status(),
                'google_status' => $response->json('error.status'),
                'google_message' => $response->json('error.message'),
            ]);

            throw GoogleReviewsException::unavailable();
        }

        $json = $response->json();

        return is_array($json) ? $json : throw GoogleReviewsException::unavailable();
    }

    /**
     * @param  array<string, mixed>  $place
     * @return array{businessName: ?string, rating: ?float, totalReviews: int, googleMapsUri: ?string, writeReviewUri: ?string, reviews: list<array<string, mixed>>}
     */
    private function normalise(array $place): array
    {
        $reviews = [];
        foreach ((array) ($place['reviews'] ?? []) as $review) {
            if (! is_array($review)) {
                continue;
            }

            $rating = (int) ($review['rating'] ?? 0);
            $text = trim((string) ($review['text']['text'] ?? $review['originalText']['text'] ?? ''));
            $author = $review['authorAttribution'] ?? [];

            if ($rating < 1 || $rating > 5) {
                continue;
            }

            $reviews[] = [
                'authorName' => trim((string) ($author['displayName'] ?? '')) ?: 'Google user',
                'authorUri' => $this->httpsUrl($author['uri'] ?? null),
                'authorPhotoUri' => $this->httpsUrl($author['photoUri'] ?? null),
                'rating' => $rating,
                'text' => mb_substr($text, 0, self::MAX_TEXT_LENGTH),
                'relativeDate' => trim((string) ($review['relativePublishTimeDescription'] ?? '')) ?: null,
                'publishTime' => $review['publishTime'] ?? null,
            ];
        }

        $placeId = (string) config('services.google_places.place_id');
        $mapsUri = $this->httpsUrl($place['googleMapsUri'] ?? null) ?? $this->httpsUrl(config('services.google_places.maps_url'));

        return [
            'businessName' => ($place['displayName']['text'] ?? null) ?: null,
            'rating' => isset($place['rating']) ? round((float) $place['rating'], 1) : null,
            'totalReviews' => (int) ($place['userRatingCount'] ?? 0),
            'googleMapsUri' => $mapsUri,
            'writeReviewUri' => 'https://search.google.com/local/writereview?placeid='.rawurlencode($placeId),
            'reviews' => $reviews,
        ];
    }

    private function httpsUrl(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
