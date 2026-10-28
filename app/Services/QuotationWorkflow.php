<?php

namespace App\Services;

use App\Models\LeadActivity;
use App\Models\Quotation;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Status-only oversight. Totals, line items and customer snapshots are never
 * touched here, so an issued quotation cannot be rewritten from the panel.
 */
class QuotationWorkflow
{
    public function changeStatus(Quotation $quotation, string $status, User $actor): Quotation
    {
        Gate::forUser($actor)->authorize('updateStatus', $quotation);

        return DB::transaction(function () use ($quotation, $status, $actor) {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->getKey());

            if (! $quotation->canTransitionTo($status)) {
                throw ValidationException::withMessages([
                    'status' => "A {$quotation->status} quotation cannot move to {$status}.",
                ]);
            }

            $previous = $quotation->status;
            $quotation->forceFill(array_filter([
                'status' => $status,
                'sent_at' => $status === 'sent' ? now() : null,
                'accepted_at' => $status === 'accepted' ? now() : null,
                'rejected_at' => $status === 'rejected' ? now() : null,
            ], fn ($value) => $value !== null))->save();

            LeadActivity::create([
                'lead_id' => $quotation->lead_id,
                'user_id' => $actor->id,
                'activity_type' => 'note',
                'description' => "Quotation {$quotation->quotation_number} marked {$status}.",
                'metadata' => ['quotation_id' => $quotation->id, 'from' => $previous, 'to' => $status],
                'occurred_at' => now(),
            ]);

            AuditLog::record('quotation.status_changed', [
                'quotation_id' => $quotation->id, 'from' => $previous, 'to' => $status,
            ]);

            return $quotation;
        });
    }
}
