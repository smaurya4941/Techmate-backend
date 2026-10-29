<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleReviewsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.google_places.api_key' => 'test-key',
            'services.google_places.place_id' => 'PLACE123',
            'services.google_places.maps_url' => 'https://maps.google.com/?cid=1',
        ]);
    }

    private function googlePayload(): array
    {
        return [
            'displayName' => ['text' => 'Techmate Industries', 'languageCode' => 'en'],
            'rating' => 4.46,
            'userRatingCount' => 302,
            'googleMapsUri' => 'https://maps.google.com/?cid=999',
            'reviews' => [
                [
                    'rating' => 5,
                    'text' => ['text' => 'Great service.', 'languageCode' => 'en'],
                    'relativePublishTimeDescription' => 'a month ago',
                    'publishTime' => '2026-09-01T10:00:00Z',
                    'authorAttribution' => [
                        'displayName' => 'A Customer',
                        'uri' => 'https://www.google.com/maps/contrib/1',
                        'photoUri' => 'http://insecure.example/p.jpg',
                    ],
                ],
                ['rating' => 0, 'text' => ['text' => 'invalid rating is dropped']],
            ],
        ];
    }

    public function test_it_returns_normalised_data_and_sends_key_and_field_mask_to_google(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response($this->googlePayload())]);

        $this->getJson('/api/v1/google-reviews')
            ->assertOk()
            ->assertJsonPath('businessName', 'Techmate Industries')
            ->assertJsonPath('rating', 4.5)
            ->assertJsonPath('totalReviews', 302)
            ->assertJsonPath('googleMapsUri', 'https://maps.google.com/?cid=999')
            ->assertJsonCount(1, 'reviews')
            ->assertJsonPath('reviews.0.authorName', 'A Customer')
            ->assertJsonPath('reviews.0.text', 'Great service.')
            ->assertJsonPath('reviews.0.relativeDate', 'a month ago')
            ->assertJsonPath('reviews.0.authorPhotoUri', null);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Goog-Api-Key', 'test-key')
            && str_contains($request->header('X-Goog-FieldMask')[0], 'userRatingCount')
            && str_contains($request->url(), '/v1/places/PLACE123'));
    }

    public function test_response_never_exposes_the_api_key(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response($this->googlePayload())]);

        $this->assertStringNotContainsString('test-key', $this->getJson('/api/v1/google-reviews')->getContent());
    }

    public function test_it_caches_so_google_is_called_once(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response($this->googlePayload())]);

        $this->getJson('/api/v1/google-reviews')->assertOk();
        $this->getJson('/api/v1/google-reviews')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_it_answers_503_not_configured_without_calling_google(): void
    {
        config(['services.google_places.api_key' => null]);
        Http::fake();

        $this->getJson('/api/v1/google-reviews')->assertStatus(503)->assertJsonPath('code', 'not_configured');

        Http::assertNothingSent();
    }

    public function test_google_errors_become_a_controlled_503_without_leaking_details(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'key secret detail']], 403)]);

        $response = $this->getJson('/api/v1/google-reviews')->assertStatus(503)->assertJsonPath('code', 'unavailable');

        $this->assertStringNotContainsString('secret detail', $response->getContent());
    }

    public function test_a_failure_is_remembered_briefly_to_protect_the_quota(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response([], 500)]);

        $this->getJson('/api/v1/google-reviews')->assertStatus(503);
        $this->getJson('/api/v1/google-reviews')->assertStatus(503);

        Http::assertSentCount(1);
    }
}
