<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Notifications\NewSubmissionAlert;
use App\Notifications\SubmissionAcknowledgement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Route;
use Tests\DatabaseTestCase;

class ApiFoundationTest extends DatabaseTestCase
{
    public function test_v1_routes_are_registered_under_the_api_prefix(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->methods()[0].' '.$r->uri())->all();

        foreach ([
            'GET api/v1/categories', 'GET api/v1/categories/{slug}/products', 'GET api/v1/brands',
            'GET api/v1/products', 'GET api/v1/products/{slug}', 'POST api/v1/enquiries',
            'POST api/v1/quote-requests', 'POST api/v1/distributor-applications',
        ] as $route) {
            $this->assertContains($route, $uris);
        }
    }

    public function test_no_public_route_lists_leads_applications_or_quotations(): void
    {
        foreach (['leads', 'quotations', 'enquiries', 'quote-requests', 'distributor-applications', 'service-requests'] as $path) {
            $this->getJson("/api/v1/{$path}")->assertStatus(in_array($path, ['enquiries', 'quote-requests', 'distributor-applications']) ? 405 : 404);
        }
    }

    public function test_private_user_route_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_unknown_routes_return_json_404_without_internals(): void
    {
        $response = $this->getJson('/api/v1/nope')->assertNotFound()->assertJsonPath('message', 'Resource not found.');

        $this->assertArrayNotHasKey('trace', $response->json());
        $this->assertArrayNotHasKey('exception', $response->json());
        $this->assertNotEmpty($response->json('request_id'));
    }

    public function test_requests_get_a_correlation_id_header(): void
    {
        $this->getJson('/api/v1/products')->assertHeader('X-Request-Id');
        $this->getJson('/api/v1/products', ['X-Request-Id' => 'client-supplied-id-123'])->assertHeader('X-Request-Id', 'client-supplied-id-123');

        $unsafe = $this->getJson('/api/v1/products', ['X-Request-Id' => "bad id\r\nX-Evil: 1"]);
        $this->assertStringNotContainsString('bad id', (string) $unsafe->headers->get('X-Request-Id'));
    }

    public function test_unexpected_errors_are_generic_and_logged_without_leaking(): void
    {
        Route::get('/api/v1/_boom', fn () => throw new \RuntimeException('SELECT * FROM secrets /var/www/app.php'));

        $response = $this->getJson('/api/v1/_boom')->assertStatus(500);

        $this->assertSame('Something went wrong on our side. Please try again later.', $response->json('message'));
        $this->assertStringNotContainsString('secrets', $response->getContent());
        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertNotEmpty($response->json('request_id'));
    }

    public function test_read_endpoints_are_rate_limited(): void
    {
        $limit = config('techmate.rate_limits.read_per_minute');

        foreach (range(1, $limit) as $i) {
            $this->getJson('/api/v1/brands');
        }

        $this->getJson('/api/v1/brands')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_cors_allows_only_configured_origins(): void
    {
        $allowed = $this->call('OPTIONS', '/api/v1/enquiries', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertSame('http://localhost:3000', $allowed->headers->get('Access-Control-Allow-Origin'));

        $denied = $this->call('OPTIONS', '/api/v1/enquiries', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertNull($denied->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_notifications_are_queued_with_retry_policy_and_sanitized_content(): void
    {
        $this->assertContains(ShouldQueue::class, class_implements(SubmissionAcknowledgement::class));
        $this->assertContains(ShouldQueue::class, class_implements(NewSubmissionAlert::class));

        $alert = new NewSubmissionAlert('enquiry', 'TM-ABC', ['Name' => '<b>[evil](http://x.test)</b>'], [], '<script>alert(1)</script> hello');
        $this->assertSame(3, $alert->tries);
        $this->assertTrue($alert->afterCommit);

        $rendered = (string) $alert->toMail(new \stdClass)->render();
        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringNotContainsString('href="http://x.test"', $rendered);
    }

    public function test_hidden_product_fields_never_appear_in_listing(): void
    {
        Product::factory()->published()->create(['created_by' => null]);

        $row = $this->getJson('/api/v1/products')->json('data.0');

        foreach (['status', 'created_by', 'updated_by', 'sort_order', 'deleted_at', 'category_id', 'brand_id', 'primary_image_path'] as $field) {
            $this->assertArrayNotHasKey($field, $row);
        }
    }
}
