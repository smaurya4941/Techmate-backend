<?php

namespace App\Services;

use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Support\AuditLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Every staff-side lead mutation goes through here so that authorization,
 * transition rules, the audit trail (lead_activities + audit_logs) and the lead update
 * happen together in one transaction. UI actions are thin wrappers: the checks
 * below run on the server regardless of what the interface shows.
 */
class LeadWorkflow
{
    public const MANUAL_ACTIVITY_TYPES = ['note', 'call', 'email', 'meeting'];

    public function assign(Lead $lead, ?int $assigneeId, User $actor, ?string $note = null): Lead
    {
        Gate::forUser($actor)->authorize('assign', $lead);

        if ($assigneeId !== null && ! User::assignable()->whereKey($assigneeId)->exists()) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose an active sales team member.']);
        }

        return DB::transaction(function () use ($lead, $assigneeId, $actor, $note) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if (! $lead->isOpen()) {
                throw ValidationException::withMessages(['assigned_to' => 'A closed lead cannot be reassigned. Reopen it first.']);
            }

            $previous = $lead->assigned_to;

            if ($previous === $assigneeId) {
                return $lead;
            }

            $lead->forceFill(['assigned_to' => $assigneeId])->save();

            $assignee = $assigneeId ? User::find($assigneeId) : null;
            $previousName = $previous ? User::find($previous)?->name : null;

            LeadActivity::create([
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'activity_type' => 'assignment',
                'description' => trim(($assignee ? "Assigned to {$assignee->name}" : 'Unassigned')
                    .($previousName ? " (previously {$previousName})." : '.').' '.$note),
                'metadata' => ['from_user_id' => $previous, 'to_user_id' => $assigneeId],
                'occurred_at' => now(),
            ]);

            if ($assignee && $lead->status === 'new') {
                $this->move($lead, 'assigned', $actor, null);
            }

            if ($assignee) {
                Outbox::queue('lead.assigned', $assignee->email, "lead:{$lead->id}:assigned:{$assignee->id}:".now()->timestamp, $lead->id);
            }

            AuditLog::record('lead.assigned', ['lead_id' => $lead->id, 'from' => $previous, 'to' => $assigneeId], $lead);

            return $lead;
        });
    }

    /** Hand-picked status change. Quotation stages follow quotations; closing needs LeadOutcomeService. */
    public function changeStatus(Lead $lead, string $status, User $actor, ?string $note = null): Lead
    {
        Gate::forUser($actor)->authorize('update', $lead);

        return DB::transaction(function () use ($lead, $status, $actor, $note) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if (! in_array($status, Lead::MANUAL_TRANSITIONS[$lead->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "A {$lead->status} lead cannot be moved to {$status} from here."
                        .(in_array($status, ['won', 'lost'], true) ? ' Use the Won / Lost outcome action.' : ''),
                ]);
            }

            return $this->move($lead, $status, $actor, $note);
        });
    }

    /**
     * Trusted-code status move (quotation sent, outcome recorded...). The caller has already
     * authorized the business action; this still enforces the legal transition map.
     */
    public function systemMove(Lead $lead, string $status, ?User $actor, ?string $note = null): Lead
    {
        return DB::transaction(function () use ($lead, $status, $actor, $note) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if ($lead->status === $status) {
                return $lead;
            }

            if (! $lead->canTransitionTo($status)) {
                throw ValidationException::withMessages(['status' => "A {$lead->status} lead cannot move to {$status}."]);
            }

            return $this->move($lead, $status, $actor, $note);
        });
    }

    public function changePriority(Lead $lead, string $priority, User $actor): Lead
    {
        Gate::forUser($actor)->authorize('update', $lead);

        if (! in_array($priority, Lead::PRIORITIES, true)) {
            throw ValidationException::withMessages(['priority' => 'Choose a valid priority.']);
        }

        return DB::transaction(function () use ($lead, $priority, $actor) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());
            $previous = $lead->priority;

            if ($previous === $priority) {
                return $lead;
            }

            $lead->forceFill(['priority' => $priority])->save();

            LeadActivity::create([
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'activity_type' => 'note',
                'description' => 'Priority changed from '.ucfirst($previous).' to '.ucfirst($priority).'.',
                'metadata' => ['priority_from' => $previous, 'priority_to' => $priority],
                'occurred_at' => now(),
            ]);
            AuditLog::record('lead.priority_changed', ['lead_id' => $lead->id, 'from' => $previous, 'to' => $priority], $lead);

            return $lead;
        });
    }

    public function logActivity(Lead $lead, string $type, string $description, User $actor, ?CarbonInterface $occurredAt = null): LeadActivity
    {
        Gate::forUser($actor)->authorize('update', $lead);
        Gate::forUser($actor)->authorize('create', LeadActivity::class);

        if (! in_array($type, self::MANUAL_ACTIVITY_TYPES, true)) {
            throw ValidationException::withMessages(['activity_type' => 'Only notes, calls, emails and meetings can be logged manually.']);
        }

        $occurredAt ??= now();
        if ($occurredAt->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => 'An activity cannot occur in the future.']);
        }

        return DB::transaction(function () use ($lead, $type, $description, $actor, $occurredAt) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            $activity = LeadActivity::create([
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'activity_type' => $type,
                'description' => $description,
                'occurred_at' => $occurredAt,
            ]);

            if (in_array($type, LeadActivity::CONTACT_TYPES, true)) {
                $lead->forceFill([
                    'last_contacted_at' => $lead->last_contacted_at && $lead->last_contacted_at->gt($occurredAt) ? $lead->last_contacted_at : $occurredAt,
                    // First response: the earliest staff contact, even if it is logged back-dated later.
                    'first_response_at' => $lead->first_response_at && $lead->first_response_at->lt($occurredAt) ? $lead->first_response_at : $occurredAt,
                ])->save();

                if (in_array($lead->status, ['new', 'assigned'], true)) {
                    $this->move($lead, 'contacted', $actor, 'Contact logged.');
                }
            }

            return $activity;
        });
    }

    /**
     * @param  array{title: string, description?: ?string, due_at: mixed, assigned_to?: ?int, remind_before_minutes?: ?int}  $data
     */
    public function scheduleFollowUp(Lead $lead, array $data, User $actor): FollowUp
    {
        Gate::forUser($actor)->authorize('update', $lead);
        Gate::forUser($actor)->authorize('create', FollowUp::class);

        $assigneeId = $data['assigned_to'] ?? $lead->assigned_to ?? $actor->id;

        // Without assignment rights, a follow-up may only go to yourself or the lead's owner.
        if (! Gate::forUser($actor)->allows('assign', $lead) && ! in_array($assigneeId, [$actor->id, $lead->assigned_to], true)) {
            throw ValidationException::withMessages(['assigned_to' => 'You can only assign follow-ups to yourself or the lead owner.']);
        }

        if (! User::assignable()->whereKey($assigneeId)->exists()) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose an active sales team member.']);
        }

        if (! $lead->isOpen()) {
            throw ValidationException::withMessages(['title' => 'Follow-ups cannot be added to a closed lead.']);
        }

        return DB::transaction(function () use ($lead, $data, $actor, $assigneeId) {
            $followUp = new FollowUp([
                'lead_id' => $lead->id,
                'assigned_to' => $assigneeId,
                'created_by' => $actor->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'due_at' => $data['due_at'],
                'remind_before_minutes' => ! empty($data['remind_before_minutes']) ? (int) $data['remind_before_minutes'] : null,
            ]);
            $followUp->forceFill(['status' => 'open'])->save();

            $this->followUpActivity($followUp, $actor, "Follow-up scheduled: {$followUp->title}.", 'scheduled');

            return $followUp;
        });
    }

    public function startFollowUp(FollowUp $followUp, User $actor): FollowUp
    {
        Gate::forUser($actor)->authorize('update', $followUp);

        return DB::transaction(function () use ($followUp) {
            $followUp = FollowUp::query()->lockForUpdate()->findOrFail($followUp->getKey());

            if ($followUp->status !== 'open') {
                throw ValidationException::withMessages(['status' => 'Only an open follow-up can be started.']);
            }

            $followUp->forceFill(['status' => 'in_progress'])->save();

            return $followUp;
        });
    }

    public function rescheduleFollowUp(FollowUp $followUp, mixed $dueAt, User $actor): FollowUp
    {
        Gate::forUser($actor)->authorize('update', $followUp);

        return DB::transaction(function () use ($followUp, $dueAt, $actor) {
            $followUp = FollowUp::query()->lockForUpdate()->findOrFail($followUp->getKey());

            if (! $followUp->isActive()) {
                throw ValidationException::withMessages(['due_at' => 'This follow-up is already closed.']);
            }

            // Reminders re-arm for the new time; the dedupe key includes the due time.
            $followUp->forceFill(['due_at' => $dueAt, 'reminder_sent_at' => null, 'overdue_notified_at' => null])->save();
            $this->followUpActivity($followUp, $actor, "Follow-up rescheduled: {$followUp->title}.", 'rescheduled');

            return $followUp;
        });
    }

    public function completeFollowUp(FollowUp $followUp, User $actor, ?string $note = null): FollowUp
    {
        return $this->closeFollowUp($followUp, 'completed', $actor, $note);
    }

    public function cancelFollowUp(FollowUp $followUp, User $actor, ?string $note = null): FollowUp
    {
        return $this->closeFollowUp($followUp, 'cancelled', $actor, $note);
    }

    private function closeFollowUp(FollowUp $followUp, string $status, User $actor, ?string $note): FollowUp
    {
        Gate::forUser($actor)->authorize('update', $followUp);

        return DB::transaction(function () use ($followUp, $status, $actor, $note) {
            $followUp = FollowUp::query()->lockForUpdate()->findOrFail($followUp->getKey());

            if (! $followUp->isActive()) {
                throw ValidationException::withMessages(['status' => 'This follow-up is already closed.']);
            }

            $followUp->forceFill([
                'status' => $status,
                'completed_at' => $status === 'completed' ? now() : null,
                'completion_note' => $note,
            ])->save();

            $this->followUpActivity($followUp, $actor, ucfirst($status)." follow-up: {$followUp->title}".($note ? " - {$note}" : ''), $status);

            return $followUp;
        });
    }

    private function followUpActivity(FollowUp $followUp, User $actor, string $description, string $event): void
    {
        LeadActivity::create([
            'lead_id' => $followUp->lead_id,
            'user_id' => $actor->id,
            'activity_type' => 'follow_up',
            'description' => $description,
            'metadata' => ['follow_up_id' => $followUp->id, 'event' => $event],
            'occurred_at' => now(),
        ]);
    }

    /** Persists a status change and its timeline entry. Call inside a transaction on a locked lead. */
    private function move(Lead $lead, string $status, ?User $actor, ?string $note): Lead
    {
        $previous = $lead->status;

        $lead->forceFill(['status' => $status])->save();

        LeadActivity::create([
            'lead_id' => $lead->id,
            'user_id' => $actor?->id,
            'activity_type' => 'status_change',
            'description' => $note,
            'old_status' => $previous,
            'new_status' => $status,
            'occurred_at' => now(),
        ]);

        AuditLog::record('lead.status_changed', ['lead_id' => $lead->id, 'from' => $previous, 'to' => $status], $lead);

        return $lead;
    }
}
