<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationEvent;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\QuotationMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of quotations. Draft content is validated and every amount is
 * recalculated here with QuotationMath: client-supplied totals are never read.
 * Status moves follow Quotation::TRANSITIONS, run under a row lock, and each one
 * writes a quotation_event, a lead timeline entry and an audit entry.
 *
 * Lifecycle: draft -> pending_approval -> approved -> sent -> accepted | rejected | expired
 * A revision is a new draft version; the version it replaces becomes superseded.
 */
class QuotationService
{
    private const MONEY = ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'];

    public function __construct(
        private readonly LeadWorkflow $leads,
        private readonly QuotationPdf $pdf,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function createDraft(Lead $lead, array $data, User $actor): Quotation
    {
        Gate::forUser($actor)->authorize('create', [Quotation::class, $lead]);

        $input = $this->validated($data);

        return DB::transaction(function () use ($lead, $input, $actor) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if (! $lead->isOpen()) {
                throw ValidationException::withMessages(['lead' => 'A quotation cannot be prepared for a closed lead.']);
            }

            $active = Quotation::query()->where('lead_id', $lead->id)->where('is_current', true)
                ->whereIn('status', ['draft', 'pending_approval', 'approved', 'sent'])->exists();
            if ($active) {
                throw ValidationException::withMessages(['lead' => 'This lead already has a live quotation. Revise or cancel it instead of starting another.']);
            }

            $quotation = new Quotation(array_merge($this->headerFields($input), [
                'lead_id' => $lead->id,
                'customer_name_snapshot' => $lead->customer_name,
                'company_name_snapshot' => $lead->company_name,
                'customer_email_snapshot' => $lead->email,
                'customer_phone_snapshot' => $lead->phone,
            ]));
            $quotation->forceFill(['status' => 'draft', 'is_current' => true, 'created_by' => $actor->id]);
            $this->applyTotals($quotation, $input);
            $quotation->save();
            $this->replaceItems($quotation, $input['items']);

            $this->event($quotation, 'created', $actor, null, 'draft');
            $this->timeline($quotation, $actor, "Quotation {$quotation->quotation_number} drafted.");
            AuditLog::record('quotation.created', ['quotation_id' => $quotation->id, 'lead_id' => $lead->id], $quotation);

            if (in_array($lead->status, ['new', 'assigned', 'contacted', 'qualified'], true)) {
                $this->leads->systemMove($lead, 'quotation_in_progress', $actor, "Quotation {$quotation->quotation_number} in preparation.");
            }

            return $quotation;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateDraft(Quotation $quotation, array $data, User $actor): Quotation
    {
        Gate::forUser($actor)->authorize('manage', $quotation);

        $input = $this->validated($data);

        return DB::transaction(function () use ($quotation, $input, $actor) {
            $quotation = $this->lock($quotation);

            if (! $quotation->isEditable()) {
                throw ValidationException::withMessages(['status' => 'Only a draft can be edited. Create a revision to change an issued quotation.']);
            }

            $quotation->fill($this->headerFields($input));
            $this->applyTotals($quotation, $input);
            $quotation->save();
            $this->replaceItems($quotation, $input['items']);

            $this->event($quotation, 'edited', $actor, 'draft', 'draft');
            AuditLog::record('quotation.edited', ['quotation_id' => $quotation->id], $quotation);

            return $quotation;
        });
    }

    public function submitForApproval(Quotation $quotation, User $actor): Quotation
    {
        Gate::forUser($actor)->authorize('manage', $quotation);

        return $this->transition($quotation, 'pending_approval', $actor, 'submitted', function (Quotation $q) {
            if ($q->items()->count() === 0) {
                throw ValidationException::withMessages(['items' => 'Add at least one line item before submitting.']);
            }
            $q->forceFill(['submitted_at' => now()]);
        });
    }

    public function approve(Quotation $quotation, User $actor, ?string $note = null): Quotation
    {
        Gate::forUser($actor)->authorize('approve', $quotation);

        return $this->transition($quotation, 'approved', $actor, 'approved', function (Quotation $q) use ($actor, $note) {
            // Four-eyes: the person who prepared the quotation cannot be its only approver.
            if ($q->created_by === $actor->id && ! $actor->isSuperAdmin()) {
                throw ValidationException::withMessages(['status' => 'A quotation must be approved by someone other than its author.']);
            }
            $q->forceFill(['approved_at' => now(), 'approved_by' => $actor->id, 'approval_note' => $note]);
        }, $note);
    }

    /** Sends a pending quotation back to its author for changes. */
    public function returnToDraft(Quotation $quotation, User $actor, string $note): Quotation
    {
        Gate::forUser($actor)->authorize('approve', $quotation);

        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Explain what needs to change.']);
        }

        return $this->transition($quotation, 'draft', $actor, 'returned', fn (Quotation $q) => $q->forceFill(['submitted_at' => null]), $note);
    }

    /**
     * Issues an approved quotation: generates the PDF, marks it sent and queues the customer
     * e-mail. Delivery is tracked separately in the outbox; a failed e-mail never reverts the
     * commercial status.
     */
    public function send(Quotation $quotation, User $actor): Quotation
    {
        Gate::forUser($actor)->authorize('manage', $quotation);

        return DB::transaction(function () use ($quotation, $actor) {
            $quotation = $this->lock($quotation);

            $this->assertCanTransition($quotation, 'sent');

            if (! $quotation->customer_email_snapshot) {
                throw ValidationException::withMessages(['status' => 'The customer has no e-mail address. Add one to the lead or send the PDF manually.']);
            }
            if ($quotation->valid_until && $quotation->valid_until->isPast() && ! $quotation->valid_until->isToday()) {
                throw ValidationException::withMessages(['status' => 'This quotation has passed its validity date. Create a revision with a new date.']);
            }

            $quotation->forceFill(['status' => 'sent', 'sent_at' => now(), 'sent_by' => $actor->id]);
            $stored = $this->pdf->store($quotation->load('items', 'lead'));
            $quotation->forceFill(['pdf_path' => $stored['path'], 'pdf_checksum' => $stored['checksum']])->save();

            $this->event($quotation, 'sent', $actor, 'approved', 'sent');
            $this->timeline($quotation, $actor, "Quotation {$quotation->quotation_number} sent to the customer.");
            Outbox::queue('quotation.sent', $quotation->customer_email_snapshot, "quotation:{$quotation->id}:sent", $quotation->lead_id, ['quotation_id' => $quotation->id]);
            AuditLog::record('quotation.sent', ['quotation_id' => $quotation->id, 'checksum' => $stored['checksum']], $quotation);

            $this->leads->systemMove($quotation->lead, 'quotation_sent', $actor, "Quotation {$quotation->quotation_number} sent.");

            return $quotation;
        });
    }

    /** Queues the already-issued PDF again, e.g. after a failed delivery. Same record, new e-mail. */
    public function resend(Quotation $quotation, User $actor): Quotation
    {
        Gate::forUser($actor)->authorize('manage', $quotation);

        return DB::transaction(function () use ($quotation, $actor) {
            $quotation = $this->lock($quotation);

            if ($quotation->status !== 'sent' || ! $quotation->pdf_path) {
                throw ValidationException::withMessages(['status' => 'Only a sent quotation can be e-mailed again.']);
            }

            Outbox::queue('quotation.sent', $quotation->customer_email_snapshot, "quotation:{$quotation->id}:resend:".now()->timestamp, $quotation->lead_id, ['quotation_id' => $quotation->id]);
            $this->event($quotation, 'resent', $actor, 'sent', 'sent');

            return $quotation;
        });
    }

    /** New draft version copied from an issued one; the old live version becomes superseded. */
    public function revise(Quotation $quotation, User $actor): Quotation
    {
        Gate::forUser($actor)->authorize('manage', $quotation);

        return DB::transaction(function () use ($quotation, $actor) {
            $previous = $this->lock($quotation);

            if (! $previous->canBeRevised()) {
                throw ValidationException::withMessages(['status' => 'Only the current approved, sent, rejected or expired version can be revised. An accepted quotation is final.']);
            }

            $lead = Lead::query()->lockForUpdate()->findOrFail($previous->lead_id);
            if (! $lead->isOpen()) {
                throw ValidationException::withMessages(['status' => 'The lead is closed.']);
            }

            $previousStatus = $previous->status;
            $wasLive = in_array($previousStatus, ['approved', 'sent'], true);

            $revision = $previous->replicate(['quotation_number', 'status', 'sent_at', 'accepted_at', 'rejected_at', 'submitted_at',
                'approved_at', 'approved_by', 'approval_note', 'sent_by', 'pdf_path', 'pdf_checksum', 'acceptance_method',
                'acceptance_reference', 'acceptance_note', 'acceptance_recorded_by', 'rejection_reason']);
            $revision->series_number = $previous->series_number;
            $revision->version = (int) Quotation::query()->where('series_number', $previous->series_number)->max('version') + 1;
            $revision->quotation_number = $revision->series_number.'-V'.$revision->version;
            $revision->forceFill([
                'status' => 'draft', 'is_current' => true, 'supersedes_id' => $previous->id,
                'created_by' => $actor->id, 'issue_date' => now()->toDateString(),
                'valid_until' => null,
            ])->save();

            foreach ($previous->items as $item) {
                $copy = $item->replicate();
                $copy->quotation_id = $revision->id;
                $copy->save();
            }

            $previous->forceFill(['is_current' => false])->save();
            if ($wasLive) {
                $previous->forceFill(['status' => 'superseded'])->save();
                $this->event($previous, 'superseded', $actor, $previousStatus, 'superseded', "Replaced by {$revision->quotation_number}.");
            }

            $this->event($revision, 'created', $actor, null, 'draft', "Revision of {$previous->quotation_number}.");
            $this->timeline($revision, $actor, "Quotation revised: {$revision->quotation_number} replaces {$previous->quotation_number}.");
            AuditLog::record('quotation.revised', ['from' => $previous->id, 'to' => $revision->id], $revision);

            if ($lead->status === 'quotation_sent') {
                $this->leads->systemMove($lead, 'negotiation', $actor, 'Customer requested changes; revision drafted.');
            }

            return $revision;
        });
    }

    /**
     * Records the customer's formal acceptance with its evidence. Opening an e-mail is not
     * acceptance: a method and a reference or note describing the evidence are required.
     *
     * @param  array{method: string, reference?: ?string, note?: ?string}  $evidence
     */
    public function recordAcceptance(Quotation $quotation, array $evidence, User $actor): Quotation
    {
        Gate::forUser($actor)->authorize('manage', $quotation);

        $evidence = Validator::make($evidence, [
            'method' => ['required', Rule::in(array_keys(Quotation::ACCEPTANCE_METHODS))],
            'reference' => ['nullable', 'string', 'max:100', 'required_without:note'],
            'note' => ['nullable', 'string', 'max:2000', 'required_without:reference'],
        ], ['required_without' => 'Record the document reference or describe the evidence of acceptance.'])->validate();

        return $this->transition($quotation, 'accepted', $actor, 'accepted', function (Quotation $q) use ($evidence, $actor) {
            if (! $q->is_current) {
                throw ValidationException::withMessages(['status' => 'Only the current version can be accepted.']);
            }
            if ($q->valid_until && $q->valid_until->isPast() && ! $q->valid_until->isToday()) {
                throw ValidationException::withMessages(['status' => 'This quotation has expired. Revise it before recording acceptance.']);
            }
            $q->forceFill([
                'accepted_at' => now(),
                'acceptance_method' => $evidence['method'],
                'acceptance_reference' => $evidence['reference'] ?? null,
                'acceptance_note' => $evidence['note'] ?? null,
                'acceptance_recorded_by' => $actor->id,
            ]);
        }, $evidence['note'] ?? null);
    }

    public function recordRejection(Quotation $quotation, string $reason, User $actor): Quotation
    {
        Gate::forUser($actor)->authorize('manage', $quotation);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Record why the customer rejected the quotation.']);
        }

        return $this->transition($quotation, 'rejected', $actor, 'rejected',
            fn (Quotation $q) => $q->forceFill(['rejected_at' => now(), 'rejection_reason' => $reason]), $reason);
    }

    public function cancel(Quotation $quotation, string $reason, User $actor): Quotation
    {
        Gate::forUser($actor)->authorize('manage', $quotation);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for cancelling.']);
        }

        return $this->transition($quotation, 'cancelled', $actor, 'cancelled', null, $reason);
    }

    /** Scheduled: sent quotations past their validity date expire. Returns how many changed. */
    public function expireOverdue(): int
    {
        $count = 0;

        Quotation::query()->where('status', 'sent')->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now(config('techmate.business_timezone'))->toDateString())
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                DB::transaction(function () use ($id, &$count) {
                    $quotation = Quotation::query()->lockForUpdate()->find($id);
                    if (! $quotation || $quotation->status !== 'sent') {
                        return;
                    }
                    $quotation->forceFill(['status' => 'expired'])->save();
                    $this->event($quotation, 'expired', null, 'sent', 'expired');
                    $this->timeline($quotation, null, "Quotation {$quotation->quotation_number} expired.");
                    AuditLog::record('quotation.expired', ['quotation_id' => $quotation->id], $quotation);
                    $count++;
                });
            });

        return $count;
    }

    // ---------------------------------------------------------------- internals

    /**
     * Locks, validates the transition, applies $mutate, saves and records the event.
     *
     * @param  (callable(Quotation): mixed)|null  $mutate
     */
    private function transition(Quotation $quotation, string $to, User $actor, string $event, ?callable $mutate, ?string $note = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $to, $actor, $event, $mutate, $note) {
            $quotation = $this->lock($quotation);
            $from = $quotation->status;

            $this->assertCanTransition($quotation, $to);

            if ($mutate) {
                $mutate($quotation);
            }

            $quotation->forceFill(['status' => $to])->save();

            $this->event($quotation, $event, $actor, $from, $to, $note);
            $this->timeline($quotation, $actor, "Quotation {$quotation->quotation_number} {$this->label($event)}.".($note ? " {$note}" : ''));
            AuditLog::record("quotation.{$event}", ['quotation_id' => $quotation->id, 'from' => $from, 'to' => $to], $quotation);

            return $quotation;
        });
    }

    private function assertCanTransition(Quotation $quotation, string $to): void
    {
        if (! $quotation->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "A {$quotation->status} quotation cannot move to {$to}.",
            ]);
        }
    }

    private function lock(Quotation $quotation): Quotation
    {
        return Quotation::query()->lockForUpdate()->findOrFail($quotation->getKey());
    }

    private function event(Quotation $quotation, string $event, ?User $actor, ?string $from, ?string $to, ?string $note = null): void
    {
        QuotationEvent::create([
            'quotation_id' => $quotation->id,
            'user_id' => $actor?->id,
            'event' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'occurred_at' => now(),
        ]);
    }

    private function timeline(Quotation $quotation, ?User $actor, string $description): void
    {
        LeadActivity::create([
            'lead_id' => $quotation->lead_id,
            'user_id' => $actor?->id,
            'activity_type' => 'quotation',
            'description' => $description,
            'metadata' => ['quotation_id' => $quotation->id, 'version' => $quotation->version],
            'occurred_at' => now(),
        ]);
    }

    private function label(string $event): string
    {
        return match ($event) {
            'submitted' => 'submitted for approval',
            'returned' => 'returned for changes',
            default => $event,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data): array
    {
        return Validator::make($data, [
            'currency_code' => ['required', 'string', 'size:3', 'alpha:ascii'],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'billing_address_snapshot' => ['nullable', 'string', 'max:1000'],
            'terms_and_conditions' => ['nullable', 'string', 'max:5000'],
            'payment_terms' => ['nullable', 'string', 'max:2000'],
            'delivery_terms' => ['nullable', 'string', 'max:2000'],
            'warranty_terms' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'freight_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'items.*.product_name_snapshot' => ['nullable', 'string', 'max:200'],
            'items.*.product_code_snapshot' => ['nullable', 'string', 'max:64'],
            'items.*.description_snapshot' => ['nullable', 'string', 'max:2000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_price' => self::MONEY,
            'items.*.discount_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ], [
            'items.required' => 'Add at least one line item.',
            'items.min' => 'Add at least one line item.',
            '*.regex' => 'Enter an amount with at most two decimals.',
        ])->after(function ($validator) use ($data) {
            foreach ($data['items'] ?? [] as $i => $item) {
                if (empty($item['product_id']) && trim((string) ($item['product_name_snapshot'] ?? '')) === '') {
                    $validator->errors()->add("items.{$i}.product_name_snapshot", 'Choose a product or enter a description for this line.');
                }
            }
        })->validate();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function headerFields(array $input): array
    {
        $validDays = (int) config('techmate.sales.quotation_valid_days');

        return [
            'currency_code' => strtoupper($input['currency_code']),
            'issue_date' => $input['issue_date'],
            'valid_until' => $input['valid_until'] ?? ($validDays > 0 ? Carbon::parse($input['issue_date'])->addDays($validDays)->toDateString() : null),
            'billing_address_snapshot' => $input['billing_address_snapshot'] ?? null,
            'terms_and_conditions' => $input['terms_and_conditions'] ?? null,
            'payment_terms' => $input['payment_terms'] ?? null,
            'delivery_terms' => $input['delivery_terms'] ?? null,
            'warranty_terms' => $input['warranty_terms'] ?? null,
            'notes' => $input['notes'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $input */
    private function applyTotals(Quotation $quotation, array $input): void
    {
        $lines = array_map(fn (array $item) => $this->line($item), $input['items']);
        $totals = QuotationMath::totals($lines, $input['freight_amount'] ?? '0');

        $quotation->forceFill($totals);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{gross: string, discount_amount: string, tax_amount: string, line_subtotal: string, line_total: string}
     */
    private function line(array $item): array
    {
        $discount = (string) ($item['discount_amount'] ?? '0');

        try {
            $line = QuotationMath::line((int) $item['quantity'], (string) $item['unit_price'], $discount ?: '0', (string) ($item['tax_rate'] ?? '0'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }

        return $line + ['discount_amount' => number_format((float) ($discount ?: 0), 2, '.', '')];
    }

    /** @param  list<array<string, mixed>>  $items */
    private function replaceItems(Quotation $quotation, array $items): void
    {
        $quotation->items()->delete();

        foreach (array_values($items) as $index => $item) {
            $product = empty($item['product_id']) ? null : Product::withTrashed()->find($item['product_id']);
            $line = $this->line($item);

            $quotation->items()->create([
                'product_id' => $product?->id,
                // Catalogue identity is snapshotted from the server-side record, not trusted from the form.
                'product_name_snapshot' => $product ? $product->name : trim($item['product_name_snapshot']),
                'product_code_snapshot' => $product ? $product->sku : ($item['product_code_snapshot'] ?? null),
                'description_snapshot' => $item['description_snapshot'] ?? ($product?->short_description),
                'quantity' => (int) $item['quantity'],
                'unit_price' => $item['unit_price'],
                'discount_amount' => $line['discount_amount'],
                'tax_rate' => (string) ($item['tax_rate'] ?? '0'),
                'tax_amount' => $line['tax_amount'],
                'line_subtotal' => $line['line_subtotal'],
                'line_total' => $line['line_total'],
                'sort_order' => $index,
            ]);
        }
    }
}
