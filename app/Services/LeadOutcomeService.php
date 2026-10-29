<?php

namespace App\Services;

use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Won / Lost decisions. Won requires a current, accepted quotation (the customer's
 * formal acceptance, with evidence) and converts to an order idempotently. Lost
 * requires a reason. Leads are never deleted; a lost lead can be reopened by a manager.
 */
class LeadOutcomeService
{
    public function __construct(
        private readonly LeadWorkflow $leads,
        private readonly OrderService $orders,
    ) {}

    /** @param  array{delivery_address?: ?string, installation_required?: bool, notes?: ?string}  $options */
    public function markWon(Lead $lead, Quotation $quotation, User $actor, array $options = []): Order
    {
        Gate::forUser($actor)->authorize('markWon', $lead);

        return DB::transaction(function () use ($lead, $quotation, $actor, $options) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            // Retrying a deal that is already won returns its order instead of creating another.
            if ($lead->status === 'won') {
                return Order::query()->where('lead_id', $lead->id)->firstOrFail();
            }

            if (! $lead->isOpen()) {
                throw ValidationException::withMessages(['lead' => "A {$lead->status} lead cannot be marked won."]);
            }

            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->getKey());
            if ($quotation->lead_id !== $lead->id || $quotation->status !== 'accepted' || ! $quotation->is_current) {
                throw ValidationException::withMessages(['quotation' => 'Record the customer\'s acceptance on the current quotation before marking the deal won.']);
            }

            $order = $this->orders->createFromQuotation($lead, $quotation->load('items'), $actor, $options);

            $lead = $this->leads->systemMove($lead, 'won', $actor, "Won with {$quotation->quotation_number}; order {$order->order_number} created.");
            $lead->forceFill([
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'outcome_quotation_id' => $quotation->id,
                'lost_reason' => null,
            ])->save();

            LeadActivity::create([
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'activity_type' => 'outcome',
                'description' => "Deal won. Order {$order->order_number} created from {$quotation->quotation_number}.",
                'metadata' => ['outcome' => 'won', 'quotation_id' => $quotation->id, 'order_id' => $order->id, 'acceptance_method' => $quotation->acceptance_method],
                'occurred_at' => now(),
            ]);
            AuditLog::record('lead.won', ['lead_id' => $lead->id, 'quotation_id' => $quotation->id, 'order_id' => $order->id], $lead);

            return $order;
        });
    }

    public function markLost(Lead $lead, string $reason, ?string $notes, ?Quotation $quotation, User $actor): Lead
    {
        Gate::forUser($actor)->authorize('markLost', $lead);

        if (! array_key_exists($reason, Lead::LOST_REASONS)) {
            throw ValidationException::withMessages(['lost_reason' => 'Choose a lost reason.']);
        }
        if ($reason === 'other' && trim((string) $notes) === '') {
            throw ValidationException::withMessages(['outcome_notes' => 'Explain the reason when choosing Other.']);
        }

        return DB::transaction(function () use ($lead, $reason, $notes, $quotation, $actor) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($lead->status === 'lost') {
                return $lead; // idempotent
            }
            if (! $lead->isOpen()) {
                throw ValidationException::withMessages(['lead' => "A {$lead->status} lead cannot be marked lost."]);
            }
            if ($quotation && $quotation->lead_id !== $lead->id) {
                throw ValidationException::withMessages(['quotation' => 'That quotation belongs to a different lead.']);
            }

            $lead = $this->leads->systemMove($lead, 'lost', $actor, Lead::LOST_REASONS[$reason]);
            $lead->forceFill([
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'lost_reason' => $reason,
                'outcome_notes' => $notes ?: null,
                'outcome_quotation_id' => $quotation?->id,
            ])->save();

            // Pending reminders on a dead lead are noise: close them with a note.
            FollowUp::query()->where('lead_id', $lead->id)->active()->update([
                'status' => 'cancelled', 'completion_note' => 'Lead closed as lost.',
            ]);

            LeadActivity::create([
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'activity_type' => 'outcome',
                'description' => 'Deal lost: '.Lead::LOST_REASONS[$reason].($notes ? " - {$notes}" : ''),
                'metadata' => ['outcome' => 'lost', 'reason' => $reason, 'quotation_id' => $quotation?->id],
                'occurred_at' => now(),
            ]);
            AuditLog::record('lead.lost', ['lead_id' => $lead->id, 'reason' => $reason, 'quotation_id' => $quotation?->id], $lead);

            return $lead;
        });
    }

    /**
     * A lost lead may be reopened by a manager. A genuinely new project from the same customer
     * normally deserves a fresh lead (same customer record) instead of reopening this one.
     */
    public function reopen(Lead $lead, User $actor, string $note): Lead
    {
        Gate::forUser($actor)->authorize('reopen', $lead);

        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Say why the lead is being reopened.']);
        }

        return DB::transaction(function () use ($lead, $actor, $note) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($lead->status !== 'lost') {
                throw ValidationException::withMessages(['status' => 'Only a lost lead can be reopened. A won deal is final.']);
            }

            $reason = $lead->lost_reason;
            $lead = $this->leads->systemMove($lead, 'contacted', $actor, "Reopened: {$note}");
            $lead->forceFill(['closed_at' => null, 'closed_by' => null, 'lost_reason' => null, 'outcome_notes' => null, 'outcome_quotation_id' => null])->save();

            LeadActivity::create([
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'activity_type' => 'outcome',
                'description' => "Lead reopened: {$note}",
                'metadata' => ['outcome' => 'reopened', 'previous_reason' => $reason],
                'occurred_at' => now(),
            ]);
            AuditLog::record('lead.reopened', ['lead_id' => $lead->id, 'previous_reason' => $reason], $lead);

            return $lead;
        });
    }
}
