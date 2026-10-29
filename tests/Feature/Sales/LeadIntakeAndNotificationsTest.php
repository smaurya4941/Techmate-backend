<?php

namespace Tests\Feature\Sales;

use App\Jobs\DeliverOutboxMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadProduct;
use App\Models\NotificationOutbox;
use App\Models\Product;
use App\Notifications\RenderedMail;
use App\Services\Outbox;
use App\Services\OutboxMessages;
use App\Services\SiteSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class LeadIntakeAndNotificationsTest extends SalesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['techmate.rate_limits.submit_per_minute' => 1000, 'techmate.rate_limits.submit_per_hour' => 10000]);
    }

    private function enquiry(array $overrides = []): array
    {
        return [
            'customer_name' => 'Asha Verma', 'email' => 'Asha@Example.com', 'phone' => '+91 98765 43210',
            'company_name' => 'Verma Bakery', 'subject' => 'Oven pricing',
            'message' => 'Please share details on the deck oven range.', 'source' => 'contact_page',
            ...$overrides,
        ];
    }

    private function breakMail(): void
    {
        $this->app->bind(OutboxMessages::class, fn () => new class extends OutboxMessages
        {
            public function build(NotificationOutbox $row): ?MailMessage
            {
                throw new \RuntimeException('SMTP unreachable');
            }
        });
    }

    // ---- Lead creation -----------------------------------------------------

    public function test_references_are_sequential_unique_and_stored_with_the_lead(): void
    {
        $refs = collect(range(1, 3))->map(fn ($i) => $this->postJson('/api/v1/enquiries', $this->enquiry(['email' => "p{$i}@example.com"]))
            ->assertCreated()->json('data.reference'));

        $year = now()->utc()->format('Y');
        $this->assertSame(["TMI-{$year}-000001", "TMI-{$year}-000002", "TMI-{$year}-000003"], $refs->all());
        $this->assertSame($refs->all(), Lead::orderBy('id')->pluck('public_reference')->all());
    }

    public function test_a_rolled_back_lead_leaves_no_partial_data_and_no_gap(): void
    {
        LeadProduct::creating(fn () => throw new \RuntimeException('disk full'));
        $product = Product::factory()->published()->create(['slug' => 'combi-oven']);

        $this->postJson('/api/v1/quote-requests', [
            'customer_name' => 'A B', 'email' => 'ab@example.com', 'phone' => '+91 98765 43210',
            'items' => [['product' => $product->slug, 'quantity' => 1]],
        ])->assertStatus(500);

        $this->assertSame(0, Lead::count());
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, NotificationOutbox::count());
        $this->assertSame(0, DB::table('sequences')->where('name', 'like', 'lead:%')->sum('value'), 'The reference is not consumed by a failed save.');
    }

    public function test_a_retried_request_with_the_same_idempotency_key_creates_one_lead(): void
    {
        $key = 'form-7f3a9c2e-1b4d';

        $first = $this->postJson('/api/v1/enquiries', $this->enquiry(), ['Idempotency-Key' => $key])->assertCreated();
        $second = $this->postJson('/api/v1/enquiries', $this->enquiry(), ['Idempotency-Key' => $key])->assertOk();

        $this->assertSame($first->json('data.reference'), $second->json('data.reference'));
        $this->assertSame(1, Lead::count());
        $this->assertSame(2, NotificationOutbox::count(), 'One customer e-mail and one sales alert, not four.');
    }

    public function test_distinct_submissions_are_never_silently_merged(): void
    {
        $this->postJson('/api/v1/enquiries', $this->enquiry(), ['Idempotency-Key' => 'key-aaaaaaaa'])->assertCreated();
        $this->postJson('/api/v1/enquiries', $this->enquiry(), ['Idempotency-Key' => 'key-bbbbbbbb'])->assertCreated();
        $this->postJson('/api/v1/enquiries', $this->enquiry(['email' => 'ASHA@example.com']))->assertCreated();

        $this->assertSame(3, Lead::count());
        $this->assertSame(1, Customer::count(), 'One customer record per normalised e-mail.');
        $this->assertSame('asha@example.com', Customer::first()->email);
        $this->assertSame(3, Customer::first()->leads()->count());
    }

    public function test_the_browser_may_send_the_idempotency_key_cross_origin(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/enquiries', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,idempotency-key',
        ]);

        $response->assertNoContent();
        $this->assertStringContainsStringIgnoringCase('idempotency-key', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_malformed_idempotency_keys_are_rejected(): void
    {
        foreach (['short', str_repeat('a', 65), 'has spaces in it', '<script>alert(1)</script>'] as $bad) {
            $this->postJson('/api/v1/enquiries', $this->enquiry(), ['Idempotency-Key' => $bad])->assertStatus(422);
        }
        $this->assertSame(0, Lead::count());
    }

    public function test_the_key_cannot_be_smuggled_in_the_body(): void
    {
        $this->postJson('/api/v1/enquiries', $this->enquiry(['idempotency_key' => 'body-key-12345']))->assertCreated();
        $this->assertNull(Lead::first()->idempotency_key);
    }

    public function test_source_attribution_is_limited_to_approved_values(): void
    {
        $this->postJson('/api/v1/enquiries', $this->enquiry(['source' => 'product_page', 'source_page' => '/products/combi-oven', 'campaign' => 'diwali-2026']))->assertCreated();

        $lead = Lead::first();
        $this->assertSame(['product_page', '/products/combi-oven', 'diwali-2026'], [$lead->source, $lead->source_page, $lead->campaign]);

        foreach (['https://evil.example/x', '//evil.example', '/path?token=abc&email=a@b.c', 'javascript:alert(1)'] as $badPage) {
            $this->postJson('/api/v1/enquiries', $this->enquiry(['source_page' => $badPage]))->assertStatus(422);
        }
        $this->postJson('/api/v1/enquiries', $this->enquiry(['campaign' => '<b>x</b>']))->assertStatus(422);
    }

    public function test_the_submission_time_and_creation_time_are_both_recorded(): void
    {
        $this->postJson('/api/v1/enquiries', $this->enquiry())->assertCreated();

        $lead = Lead::first();
        $this->assertNotNull($lead->submitted_at);
        $this->assertNotNull($lead->created_at);
        // submitted_at is when the request reached the API; created_at is when the row was committed.
        $this->assertTrue($lead->submitted_at->lte($lead->created_at->copy()->addSecond()));
        $this->assertTrue($lead->submitted_at->diffInSeconds(now(), true) < 60);
    }

    // ---- Notifications -----------------------------------------------------

    public function test_the_customer_confirmation_has_the_reference_company_identity_and_products(): void
    {
        $product = Product::factory()->published()->create(['slug' => 'combi-oven', 'name' => 'Combi Oven']);
        $admin = $this->admin;
        app(SiteSettings::class)->save(['company_name' => 'Techmate Industries Pvt Ltd', 'contact_email' => 'sales@techmate.test', 'contact_phone' => '+91 40 1234 5678'], $admin);

        $ref = $this->postJson('/api/v1/quote-requests', [
            'customer_name' => 'Asha Verma', 'email' => 'asha@example.com', 'phone' => '+91 98765 43210',
            'items' => [['product' => 'combi-oven', 'quantity' => 2]],
        ])->assertCreated()->json('data.reference');

        $row = NotificationOutbox::where('type', 'lead.customer_confirmation')->firstOrFail();
        $mail = app(OutboxMessages::class)->build($row);
        $text = $mail->subject.' '.implode(' ', $mail->introLines).' '.$mail->salutation;

        $this->assertSame('asha@example.com', $row->recipient_email);
        $this->assertStringContainsString($ref, $text);
        $this->assertStringContainsString('Techmate Industries Pvt Ltd', $text);
        $this->assertStringContainsString('2 x Combi Oven', $text);
        $this->assertStringContainsString('sales@techmate.test', $text);
        $this->assertStringContainsString('not a quotation', $text);
        $this->assertStringNotContainsStringIgnoringCase('within 24 hours', $text, 'No unpromised service levels.');
    }

    public function test_the_sales_alert_has_reference_time_products_and_an_admin_link_but_neutralises_markup(): void
    {
        $ref = $this->postJson('/api/v1/enquiries', $this->enquiry([
            'message' => '<script>alert(1)</script> [click](http://evil.example) real request',
            'customer_name' => '<b>Mallory</b>',
        ]))->assertCreated()->json('data.reference');

        $row = NotificationOutbox::where('type', 'lead.sales_alert')->firstOrFail();
        $mail = app(OutboxMessages::class)->build($row);
        $text = implode("\n", $mail->introLines);

        $this->assertSame('sales@example.test', $row->recipient_email);
        $this->assertStringContainsString($ref, $mail->subject);
        $this->assertStringContainsString('Submitted:', $text);
        $this->assertStringContainsString('/admin/leads/'.Lead::first()->id, $mail->actionUrl);
        $this->assertStringNotContainsString('<script>', $text);
        $this->assertStringNotContainsString('<b>', $text);
        $this->assertStringNotContainsString('](http://evil.example)', $text);
        $this->assertStringContainsString('real request', $text);
    }

    public function test_emails_are_queued_after_commit_and_sent_once(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/enquiries', $this->enquiry())->assertCreated();

        Notification::assertSentOnDemandTimes(RenderedMail::class, 2);
        $this->assertSame(['sent', 'sent'], NotificationOutbox::orderBy('id')->pluck('status')->all());
        $this->assertNotNull(NotificationOutbox::first()->sent_at);
    }

    public function test_a_failing_mail_transport_never_loses_the_lead_and_is_retried_then_marked_failed(): void
    {
        $this->breakMail();

        $this->postJson('/api/v1/enquiries', $this->enquiry())->assertCreated();
        $this->assertSame(1, Lead::count());

        $row = NotificationOutbox::where('type', 'lead.customer_confirmation')->firstOrFail();
        $this->assertSame(['pending', 1], [$row->status, $row->attempts]);
        $this->assertTrue($row->available_at->isFuture(), 'Backoff is applied.');

        // Not yet due: the scheduler does not touch it.
        $this->artisan('techmate:outbox-dispatch')->assertSuccessful();
        $this->assertSame(1, $row->fresh()->attempts);

        foreach ([2, 3] as $attempt) {
            $this->travel(20)->minutes();
            $this->artisan('techmate:outbox-dispatch')->assertSuccessful();
            $this->assertSame($attempt, $row->fresh()->attempts);
        }

        $row->refresh();
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->failed_at);
        $this->assertStringContainsString('SMTP unreachable', $row->last_error);

        // Giving up is final until a person retries.
        $this->travel(2)->hours();
        $this->artisan('techmate:outbox-dispatch');
        $this->assertSame(3, $row->fresh()->attempts);
        $this->assertSame(1, Lead::count(), 'Retries never create another lead.');
    }

    public function test_retrying_a_failed_message_sends_it_once_without_creating_a_second_record(): void
    {
        $this->breakMail();
        $this->postJson('/api/v1/enquiries', $this->enquiry())->assertCreated();
        foreach ([1, 2] as $_) {
            $this->travel(20)->minutes();
            $this->artisan('techmate:outbox-dispatch');
        }
        $row = NotificationOutbox::where('type', 'lead.customer_confirmation')->firstOrFail();
        $this->assertSame('failed', $row->status);

        $this->app->forgetInstance(OutboxMessages::class);
        $this->app->bind(OutboxMessages::class, fn () => new OutboxMessages);
        Notification::fake();

        Outbox::retry($row);
        Outbox::retry($row->fresh()); // a double click

        $this->assertSame('sent', $row->fresh()->status);
        Notification::assertSentOnDemandTimes(RenderedMail::class, 1);
        $this->assertSame(2, NotificationOutbox::count());
    }

    public function test_two_workers_cannot_send_the_same_message_twice(): void
    {
        Notification::fake();
        $lead = $this->lead();
        $row = Outbox::queue('lead.assigned', 'exec@example.test', 'test:dup', $lead->id);

        // Sync queue already delivered it; replay the job as a second worker would.
        DeliverOutboxMessage::dispatch($row->id);
        DeliverOutboxMessage::dispatch($row->id);

        Notification::assertSentOnDemandTimes(RenderedMail::class, 1);
        $this->assertSame(1, $row->fresh()->attempts);
    }

    public function test_the_same_dedupe_key_is_only_queued_once(): void
    {
        $lead = $this->lead();

        $this->assertNotNull(Outbox::queue('lead.assigned', 'a@example.test', 'same-key', $lead->id));
        $this->assertNull(Outbox::queue('lead.assigned', 'a@example.test', 'same-key', $lead->id));
        $this->assertSame(1, NotificationOutbox::count());
    }

    public function test_the_scheduler_recovers_rows_left_pending_or_stuck_after_a_crash(): void
    {
        Notification::fake();
        $lead = $this->lead();

        // A row committed but never dispatched (process died), and one stuck mid-send.
        $pending = NotificationOutbox::query()->forceCreate(['type' => 'lead.assigned', 'dedupe_key' => 'crash-1', 'recipient_email' => 'x@example.test', 'lead_id' => $lead->id, 'status' => 'pending', 'available_at' => now()]);
        $stuck = NotificationOutbox::query()->forceCreate(['type' => 'lead.assigned', 'dedupe_key' => 'crash-2', 'recipient_email' => 'y@example.test', 'lead_id' => $lead->id, 'status' => 'sending', 'claimed_at' => now()->subHour(), 'available_at' => now()->subHour()]);

        $this->artisan('techmate:outbox-dispatch')->assertSuccessful();

        $this->assertSame('sent', $pending->fresh()->status);
        $this->assertSame('sent', $stuck->fresh()->status);
    }

    public function test_a_message_whose_record_disappeared_is_skipped_not_retried_forever(): void
    {
        $row = NotificationOutbox::query()->forceCreate(['type' => 'lead.assigned', 'dedupe_key' => 'gone', 'recipient_email' => 'x@example.test', 'lead_id' => null, 'status' => 'pending', 'available_at' => now()]);

        DeliverOutboxMessage::dispatch($row->id);

        $this->assertSame('sent', $row->fresh()->status);
        $this->assertStringContainsString('Skipped', $row->fresh()->last_error);
    }

    public function test_only_super_admins_see_or_retry_notifications_and_the_audit_trail(): void
    {
        $this->breakMail();
        $this->postJson('/api/v1/enquiries', $this->enquiry())->assertCreated();

        $this->actingAs($this->admin)->get('/admin/notifications')->assertOk();
        $this->actingAs($this->admin)->get('/admin/audit-trail')->assertOk();

        foreach ([$this->manager, $this->exec, $this->ops] as $user) {
            $this->actingAs($user)->get('/admin/notifications')->assertForbidden();
            $this->actingAs($user)->get('/admin/audit-trail')->assertForbidden();
        }
    }
}
