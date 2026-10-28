<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadProduct;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Persists website enquiries and quote requests as leads. Everything a visitor
 * must not control (type, status, priority, assignment, reference) is set here.
 * A quote request is a lead of type "quotation"; it never creates a Quotation.
 */
class LeadSubmissionService
{
    private const FIELDS = [
        'customer_name', 'email', 'phone', 'company_name', 'city', 'state',
        'postal_code', 'country_code', 'subject', 'message',
    ];

    public function __construct(private readonly SubmissionNotifier $notifier) {}

    /** @param  array<string, mixed>  $data  validated input */
    public function createEnquiry(array $data): Lead
    {
        $lead = $this->persist($data, 'general', 'Enquiry submitted through the website.', []);

        $this->notifier->lead($lead, 'enquiry');

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     * @param  list<array{product_id: ?int, name: string, code: ?string, quantity: int, notes: ?string}>  $items
     */
    public function createQuoteRequest(array $data, array $items): Lead
    {
        $lead = $this->persist(
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
            }
        );

        $this->notifier->lead($lead, 'quote request', array_map(
            fn (array $i) => ['name' => $i['name'], 'code' => $i['code'], 'quantity' => $i['quantity']],
            $items
        ));

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $activityMetadata
     * @param  (Closure(Lead): void)|null  $withRelated  runs inside the same transaction
     */
    private function persist(array $data, string $type, string $activity, array $activityMetadata, ?Closure $withRelated = null): Lead
    {
        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($data, $type, $activity, $activityMetadata, $withRelated) {
                    $lead = new Lead(array_intersect_key($data, array_flip(self::FIELDS)));
                    $lead->lead_type = $type;
                    $lead->source = $data['source'] ?? 'website';
                    $lead->status = 'new';
                    $lead->priority = 'normal';
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

                    return $lead;
                });
            } catch (UniqueConstraintViolationException $e) {
                // Reference collision under concurrency: the whole transaction rolled
                // back, so retrying with a fresh random reference is safe.
                if (++$attempts >= 3) {
                    throw $e;
                }
            }
        }
    }
}
