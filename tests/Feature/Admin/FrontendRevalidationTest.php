<?php

namespace Tests\Feature\Admin;

use App\Models\Page;
use App\Models\Product;
use App\Services\FrontendRevalidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class FrontendRevalidationTest extends AdminTestCase
{
    public function test_nothing_is_sent_when_the_webhook_is_not_configured(): void
    {
        config(['techmate.frontend.revalidate_url' => null, 'techmate.frontend.revalidate_secret' => null]);
        Http::fake();

        Product::factory()->published()->create();

        Http::assertNothingSent();
        $this->assertFalse(FrontendRevalidator::configured());
    }

    public function test_content_changes_ping_the_frontend_with_the_shared_secret(): void
    {
        config([
            'techmate.frontend.revalidate_url' => 'https://frontend.example.test/api/revalidate',
            'techmate.frontend.revalidate_secret' => 'test-secret',
        ]);
        Http::fake(['frontend.example.test/*' => Http::response(['revalidated' => true])]);

        $product = Product::factory()->published()->create();
        $product->update(['name' => 'Changed']);
        Page::factory()->published()->create();

        // afterResponse() callbacks run at termination; in tests, flush them explicitly.
        $this->app->terminate();

        Http::assertSent(fn ($request) => $request->url() === 'https://frontend.example.test/api/revalidate'
            && $request->hasHeader('X-Revalidate-Secret', 'test-secret'));
    }

    public function test_a_failing_frontend_never_breaks_the_admin_save(): void
    {
        config([
            'techmate.frontend.revalidate_url' => 'https://frontend.example.test/api/revalidate',
            'techmate.frontend.revalidate_secret' => 'test-secret',
        ]);
        Http::fake(['frontend.example.test/*' => Http::response('boom', 500)]);

        FrontendRevalidator::send('test');
        Http::fake(fn () => throw new ConnectionException('down'));
        FrontendRevalidator::send('test');

        $this->assertTrue(true, 'send() swallowed the failures');
    }
}
