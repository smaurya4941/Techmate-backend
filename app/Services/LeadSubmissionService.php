<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadProduct;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persists website enquiries and quote requests as leads. Everything a visitor
 * must not control (type, status, priority, assignment, reference) is set here.
 * A quote request is a lead of type "quotation"; it never creates a Quotation.
 *
 * One transaction covers the customer, the lead, its items, the first activity and the
 * notification outbox rows. E-mail is sent only after commit, by the outbox job.
 */
class LeadSubmissionService
{
    private const FIELDS = [
        'customer_name', 'email', 'phone', 'company_name', 'city', 'state',
        'postal_code', 'country_code', 'subject', 'message', 'source_page', 'campaign',
    ];

    /**
     * @param  array<string, mixed>  $data  validated input
     * @return array{0: Lead, 1: bool} the lead and whether this call created it (false = idempotent replay)
     */
    public function createEnquiry(array $data, ?string $idempotencyKey = null, ?Carbon $submittedAt = null): array
    {
        return $this->persist($data, 'general', 'Enquiry submitted through the website.', [], null, $idempotencyKey, $submittedAt);
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     * @param  list<array{product_id: ?int, name: string, code: ?string, quantity: int, notes: ?string}>  $items
     * @return array{0: Lead, 1: bool}
     */
    public function createQuoteRequest(array $data, array $items, ?string $idempotencyKey = null, ?Carbon $submittedAt = null): array
    {
        return $this->persist(
            $data,
            'quotation',
            'Quote request submitted through the website.',
            ['business_type' => $data['business_type'] ?? null, 'item_count' => count($items)],
            function (Lead $lead) use ($items) {
                foreach ($items as $item) {
                    LeadProduct::create([
                        'lead_id' => $lead->id,
                        'product_id' => $item['product_id'],
                        // Snapshots keep the request intact if the catalogue later changes.
                        'product_name_snapshot' => $item['name'],
                        'product_code_snapshot' => $item['code'],
                        'quantity' => $item['quantity'],
                        'customer_notes' => $item['notes'],
                    ]);
                }
            },
            $idempotencyKey,
            $submittedAt,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $activityMetadata
     * @param  (Closure(Lead): void)|null  $withRelated  runs inside the same transaction
     * @return array{0: Lead, 1: bool}
     */
    private function persist(array $data, string $type, string $activity, array $activityMetadata, ?Closure $withRelated, ?string $idempotencyKey, ?Carbon $submittedAt): array
    {
        if ($idempotencyKey && ($existing = Lead::query()->where('idempotency_key', $idempotencyKey)->first())) {
            return [$existing, false]; // a browser or proxy retry of a request that already succeeded
        }

        $attempts = 0;

        while (true) {
            try {
                $lead = DB::transaction(function () use ($data, $type, $activity, $activityMetadata, $withRelated, $idempotencyKey, $submittedAt) {
                    $customer = Customer::resolve(
                        $data['customer_name'], $data['company_name'] ?? null, $data['email'] ?? null, $data['phone'] ?? null
                    );

                    $lead = new Lead(array_intersect_key($data, array_flip(self::FIELDS)));
                    $lead->lead_type = $type;
                    $lead->source = $data['source'] ?? 'website';
                    $lead->status = 'new';
                    $lead->priority = 'normal';
                    $lead->customer_id = $customer?->id;
                    $lead->idempotency_key = $idempotencyKey;
                    $lead->submitted_at = $submittedAt ?? now();
                    $lead->consent_at = ! empty($data['consent']) ? now() : null;
                    $lead->save();

                    LeadActivity::create([
                        'lead_id' => $lead->id,
                        'activity_type' => 'note',
                        'description' => $activity,
                        'metadata' => ['source' => $lead->source, ...$activityMetadata],
                        'occurred_at' => now(),
                    ]);

                    if ($withRelated) {
                        $withRelated($lead);
                    }

                    // Owed e-mails commit with the lead; delivery happens after commit.
                    Outbox::queue('lead.customer_confirmation', $lead->email, "lead:{$lead->id}:confirmation", $lead->id);
                    Outbox::queue('lead.sales_alert', config('techmate.sales_notification_email'), "lead:{$lead->id}:sales-alert", $lead->id);

                    return $lead;
                });

                return [$lead, true];
            } catch (UniqueConstraintViolationException $e) {
                // Two identical retries racing: the loser's transaction rolled back, so return the winner.
                if ($idempotencyKey && ($existing = Lead::query()->where('idempotency_key', $idempotencyKey)->first())) {
                    return [$existing, false];
                }

                // Otherwise a reference collision: the whole transaction rolled back, so a retry is safe.
                if (++$attempts >= 3) {
                    throw $e;
                }
            }
        }
    }
}
