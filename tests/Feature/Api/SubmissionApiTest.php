<?php

namespace Tests\Feature\Api;

use App\Models\DistributorApplication;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadProduct;
use App\Models\Product;
use App\Models\Quotation;
use App\Notifications\NewSubmissionAlert;
use App\Notifications\SubmissionAcknowledgement;
use Illuminate\Support\Facades\Notification;
use Tests\DatabaseTestCase;

class SubmissionApiTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config([
            'techmate.sales_notification_email' => 'sales@example.test',
            'techmate.rate_limits.submit_per_minute' => 1000,
            'techmate.rate_limits.submit_per_hour' => 10000,
        ]);
    }

    private function enquiry(array $overrides = []): array
    {
        return [
            'customer_name' => 'Asha Verma',
            'email' => 'asha@example.com',
            'phone' => '+91 98765 43210',
            'company_name' => 'Verma Bakery',
            'subject' => 'Oven pricing',
            'message' => 'Please share details on the deck oven range.',
            'source' => 'contact_page',
            ...$overrides,
        ];
    }

    private function quote(array $overrides = []): array
    {
        $product = Product::where('slug', 'combi-oven')->first()
            ?? Product::factory()->published()->create(['name' => 'Combi Oven', 'sku' => 'CO-10', 'slug' => 'combi-oven']);

        return [
            'customer_name' => 'Asha Verma',
            'email' => 'asha@example.com',
            'phone' => '+91 98765 43210',
            'company_name' => 'Verma Bakery',
            'items' => [['product' => $product->slug, 'quantity' => 2, 'notes' => 'Three phase']],
            ...$overrides,
        ];
    }

    private function distributor(array $overrides = []): array
    {
        return [
            'contact_name' => 'Vikram Singh',
            'company_name' => 'Singh Kitchens',
            'email' => 'vikram@example.com',
            'phone' => '+91 98200 12345',
            'business_type' => 'dealer',
            'regions_served' => ['Pune', 'Mumbai'],
            'website_url' => 'https://example.com',
            ...$overrides,
        ];
    }

    // ---- Enquiries -------------------------------------------------------

    public function test_valid_enquiry_persists_lead_and_activity_and_returns_reference_only(): void
    {
        $response = $this->postJson('/api/v1/enquiries', $this->enquiry(['consent' => true]))
            ->assertCreated()
            ->assertJsonPath('message', 'Enquiry submitted successfully.')
            ->assertJsonStructure(['message', 'data' => ['reference']])
            ->assertJsonCount(1, 'data');

        $lead = Lead::firstOrFail();
        $this->assertSame($lead->public_reference, $response->json('data.reference'));
        $this->assertMatchesRegularExpression('/^TM-[A-Z2-9]{10}$/', $lead->public_reference);
        $this->assertSame('general', $lead->lead_type);
        $this->assertSame('new', $lead->status);
        $this->assertSame('normal', $lead->priority);
        $this->assertNull($lead->assigned_to);
        $this->assertNotNull($lead->consent_at);
        $this->assertSame('contact_page', $lead->source);
        $this->assertSame(1, LeadActivity::where('lead_id', $lead->id)->count());
        $this->assertStringNotContainsString('asha@example.com', $response->getContent());
    }

    public function test_enquiry_queues_acknowledgement_and_internal_alert(): void
    {
        $this->postJson('/api/v1/enquiries', $this->enquiry())->assertCreated();

        Notification::assertSentOnDemand(SubmissionAcknowledgement::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'asha@example.com');
        Notification::assertSentOnDemand(NewSubmissionAlert::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'sales@example.test');
    }

    public function test_enquiry_succeeds_when_notifications_cannot_be_queued(): void
    {
        Notification::swap(new class
        {
            public function route(): never
            {
                throw new \RuntimeException('queue down');
            }
        });

        $this->postJson('/api/v1/enquiries', $this->enquiry())->assertCreated();
        $this->assertSame(1, Lead::count());
    }

    public function test_enquiry_validation_errors_use_the_standard_envelope(): void
    {
        $this->postJson('/api/v1/enquiries', $this->enquiry(['email' => 'not-an-email', 'customer_name' => '', 'message' => 'short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'customer_name', 'message'])
            ->assertJsonStructure(['message', 'errors', 'request_id']);

        $this->assertSame(0, Lead::count());
    }

    public function test_enquiry_field_validation_edges(): void
    {
        $bad = [
            ['phone' => 'abc'], ['phone' => '123'], ['country_code' => 'India'], ['source' => 'evil'],
            ['subject' => str_repeat('a', 201)], ['message' => str_repeat('a', 5001)], ['postal_code' => '<script>'],
            ['customer_name' => ['x']],
        ];
        foreach ($bad as $override) {
            $this->postJson('/api/v1/enquiries', $this->enquiry($override))->assertStatus(422);
        }
        $this->assertSame(0, Lead::count());

        // Phone is optional for general enquiries.
        $this->postJson('/api/v1/enquiries', $this->enquiry(['phone' => null, 'country_code' => 'in']))->assertCreated();
        $this->assertSame('IN', Lead::first()->country_code);
    }

    public function test_privileged_and_honeypot_fields_are_rejected(): void
    {
        foreach ([
            ['status' => 'won'], ['priority' => 'urgent'], ['assigned_to' => 1], ['public_reference' => 'TM-HACKED'],
            ['internal_notes' => 'x'], ['lead_type' => 'quotation'], ['hp_field' => 'bot'],
        ] as $override) {
            $this->postJson('/api/v1/enquiries', $this->enquiry($override))->assertStatus(422);
        }
        $this->assertSame(0, Lead::count());
    }

    public function test_references_are_unique_across_submissions(): void
    {
        $refs = [];
        foreach (range(1, 5) as $i) {
            $refs[] = $this->postJson('/api/v1/enquiries', $this->enquiry(['email' => "u{$i}@example.com"]))->json('data.reference');
        }

        $this->assertCount(5, array_unique($refs));
    }

    public function test_enquiry_activity_is_atomic_with_the_lead(): void
    {
        LeadActivity::creating(fn () => throw new \RuntimeException('boom'));

        $this->postJson('/api/v1/enquiries', $this->enquiry())->assertStatus(500)->assertJsonPath('message', 'Something went wrong on our side. Please try again later.');

        $this->assertSame(0, Lead::count());
        $this->assertSame(0, LeadActivity::count());
        Notification::assertNothingSent();
    }

    // ---- Quote requests ----------------------------------------------------

    public function test_quote_request_persists_lead_items_and_snapshots(): void
    {
        $other = Product::factory()->published()->create(['name' => 'Mixer', 'sku' => 'MX-1', 'slug' => 'mixer']);
        $payload = $this->quote();
        $payload['items'][] = ['product' => $other->slug, 'quantity' => 1];
        $payload['items'][] = ['name' => 'Custom 3-door chiller', 'quantity' => 4];

        $response = $this->postJson('/api/v1/quote-requests', $payload)
            ->assertCreated()
            ->assertJsonPath('message', 'Quote request submitted successfully.')
            ->assertJsonCount(1, 'data');

        $lead = Lead::firstOrFail();
        $this->assertSame($lead->public_reference, $response->json('data.reference'));
        $this->assertSame('quotation', $lead->lead_type);
        $this->assertSame('new', $lead->status);
        $this->assertSame(3, $lead->products()->count());

        $line = LeadProduct::where('product_code_snapshot', 'CO-10')->firstOrFail();
        $this->assertSame('Combi Oven', $line->product_name_snapshot);
        $this->assertSame(2, $line->quantity);
        $this->assertSame('Three phase', $line->customer_notes);
        $this->assertNotNull($line->product_id);

        $custom = LeadProduct::where('product_name_snapshot', 'Custom 3-door chiller')->firstOrFail();
        $this->assertNull($custom->product_id);
        $this->assertSame(4, $custom->quantity);

        // Renaming the product later does not rewrite history.
        Product::where('slug', 'combi-oven')->update(['name' => 'Renamed']);
        $this->assertSame('Combi Oven', $line->fresh()->product_name_snapshot);

        $this->assertSame(0, Quotation::count(), 'A quote request must not create an issued quotation.');
        Notification::assertSentOnDemandTimes(SubmissionAcknowledgement::class, 1);
    }

    public function test_quote_request_rejects_ineligible_products(): void
    {
        Product::factory()->create(['slug' => 'draft-product']);
        Product::factory()->published()->create(['slug' => 'deleted-product'])->delete();

        foreach (['draft-product', 'deleted-product', 'missing-product'] as $slug) {
            $this->postJson('/api/v1/quote-requests', $this->quote(['items' => [['product' => $slug, 'quantity' => 1]]]))
                ->assertStatus(422)->assertJsonValidationErrors('items.0.product');
        }
        $this->assertSame(0, Lead::count());
    }

    public function test_quote_request_rejects_bad_quantities_items_and_arrays(): void
    {
        $item = fn (array $o = []) => ['product' => 'combi-oven', 'quantity' => 1, ...$o];
        $payload = $this->quote();

        $bad = [
            ['items' => []],
            ['items' => 'x'],
            ['items' => [$item(['quantity' => 0])]],
            ['items' => [$item(['quantity' => -3])]],
            ['items' => [$item(['quantity' => 1001])]],
            ['items' => [$item(['quantity' => 'many'])]],
            ['items' => [$item(['quantity' => 1.5])]],
            ['items' => [$item(), $item()]], // duplicate product
            ['items' => [['quantity' => 1]]], // neither product nor name
            ['items' => array_fill(0, 21, ['name' => 'Custom', 'quantity' => 1])],
            ['items' => [$item(['price' => '1.00'])]],
        ];
        foreach ($bad as $override) {
            $this->postJson('/api/v1/quote-requests', [...$payload, ...$override])->assertStatus(422);
        }

        $this->assertSame(0, Lead::count());
        $this->assertSame(0, LeadProduct::count());
    }

    public function test_quote_request_requires_contact_details_and_ignores_privileged_input(): void
    {
        $this->postJson('/api/v1/quote-requests', $this->quote(['phone' => null]))->assertStatus(422)->assertJsonValidationErrors('phone');

        foreach ([['status' => 'won'], ['price' => 1], ['total' => 100], ['discount' => 50], ['lead_type' => 'general']] as $override) {
            $this->postJson('/api/v1/quote-requests', $this->quote($override))->assertStatus(422);
        }
        $this->assertSame(0, Lead::count());
    }

    public function test_quote_request_rolls_back_all_rows_on_partial_failure(): void
    {
        LeadProduct::creating(function () {
            static $count = 0;
            if (++$count === 2) {
                throw new \RuntimeException('second item fails');
            }
        });

        $payload = $this->quote();
        $payload['items'][] = ['name' => 'Second custom item', 'quantity' => 1];

        $this->postJson('/api/v1/quote-requests', $payload)->assertStatus(500);

        $this->assertSame(0, Lead::count());
        $this->assertSame(0, LeadProduct::count());
        $this->assertSame(0, LeadActivity::count());
        Notification::assertNothingSent();
    }

    // ---- Distributor applications -------------------------------------------

    public function test_distributor_application_persists_with_safe_defaults(): void
    {
        $response = $this->postJson('/api/v1/distributor-applications', $this->distributor(['product_interests' => ['Ovens'], 'consent' => true]))
            ->assertCreated()
            ->assertJsonPath('message', 'Distributor application submitted successfully.')
            ->assertJsonCount(1, 'data');

        $application = DistributorApplication::firstOrFail();
        $this->assertSame($application->public_reference, $response->json('data.reference'));
        $this->assertMatchesRegularExpression('/^DA-[A-Z2-9]{10}$/', $application->public_reference);
        $this->assertSame('new', $application->status);
        $this->assertNull($application->reviewed_by);
        $this->assertSame(['Pune', 'Mumbai'], $application->regions_served);
        $this->assertNotNull($application->consent_at);
        $this->assertSame(0, Lead::count(), 'Distributor applications are not stored as generic leads.');
        Notification::assertSentOnDemand(NewSubmissionAlert::class);
    }

    public function test_distributor_application_validation_and_privileged_fields(): void
    {
        $bad = [
            ['email' => 'nope'], ['regions_served' => []], ['regions_served' => 'Pune'],
            ['regions_served' => array_fill(0, 11, 'Pune')], ['website_url' => 'javascript:alert(1)'],
            ['website_url' => 'ftp://example.com'], ['business_type' => 'pirate'], ['company_name' => ''],
            ['status' => 'approved'], ['reviewed_by' => 1], ['internal_notes' => 'x'], ['hp_field' => 'bot'],
            ['regions_served' => ['Pune', 'Pune']],
        ];
        foreach ($bad as $override) {
            $this->postJson('/api/v1/distributor-applications', $this->distributor($override))->assertStatus(422);
        }
        $this->assertSame(0, DistributorApplication::count());
    }

    // ---- Deferred workflow ------------------------------------------------------

    public function test_service_requests_are_deferred_and_never_fake_success(): void
    {
        $this->postJson('/api/v1/service-requests', $this->enquiry())->assertNotFound();
        $this->assertSame(0, Lead::count());
    }

    // ---- Rate limiting ------------------------------------------------------------

    public function test_submissions_are_rate_limited_with_standard_429(): void
    {
        config(['techmate.rate_limits.submit_per_minute' => 6]);
        $limit = 6;

        foreach (range(1, $limit) as $i) {
            $this->postJson('/api/v1/enquiries', [])->assertStatus(422);
        }

        $this->postJson('/api/v1/enquiries', [])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many requests. Please try again later.')
            ->assertJsonStructure(['message', 'request_id'])
            ->assertHeader('Retry-After');

        // Other submission routes keep their own budget.
        $this->postJson('/api/v1/quote-requests', [])->assertStatus(422);
    }
}
