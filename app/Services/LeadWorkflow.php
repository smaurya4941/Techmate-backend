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
 * transition rules, the audit trail (lead_activities) and the lead update
 * happen together in one transaction. UI actions are thin wrappers: the checks
 * below run on the server regardless of what the interface shows.
 */
class LeadWorkflow
{
    public const MANUAL_ACTIVITY_TYPES = ['note', 'call', 'email', 'meeting'];

    private const CLOSING_STATUSES = ['won', 'lost', 'spam'];

    public function assign(Lead $lead, ?int $assigneeId, User $actor, ?string $note = null): Lead
    {
        Gate::forUser($actor)->authorize('assign', $lead);

        if ($assigneeId !== null && ! User::assignable()->whereKey($assigneeId)->exists()) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose an active sales team member.']);
        }

        return DB::transaction(function () use ($lead, $assigneeId, $actor, $note) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());
            $previous = $lead->assigned_to;

            if ($previous === $assigneeId) {
                return $lead;
            }

            $lead->forceFill(['assigned_to' => $assigneeId])->save();

            $assignee = $assigneeId ? User::find($assigneeId)?->name : null;
            LeadActivity::create([
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'activity_type' => 'assignment',
                'description' => trim(($assignee ? "Assigned to {$assignee}." : 'Unassigned.').' '.$note),
                'metadata' => ['from_user_id' => $previous, 'to_user_id' => $assigneeId],
                'occurred_at' => now(),
            ]);

            AuditLog::record('lead.assigned', ['lead_id' => $lead->id, 'from' => $previous, 'to' => $assigneeId]);

            return $lead;
        });
    }

    public function changeStatus(Lead $lead, string $status, User $actor, ?string $note = null): Lead
    {
        Gate::forUser($actor)->authorize('update', $lead);

        return DB::transaction(function () use ($lead, $status, $actor, $note) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());

            if (! $lead->canTransitionTo($status)) {
                throw ValidationException::withMessages([
                    'status' => "A {$lead->status} lead cannot move to {$status}.",
                ]);
            }

            $previous = $lead->status;
            $lead->forceFill([
                'status' => $status,
                'closed_at' => in_array($status, self::CLOSING_STATUSES, true) ? now() : null,
                'last_contacted_at' => $status === 'contacted' ? now() : $lead->last_contacted_at,
            ])->save();

            LeadActivity::create([
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'activity_type' => 'status_change',
                'description' => $note,
                'old_status' => $previous,
                'new_status' => $status,
                'occurred_at' => now(),
            ]);

            AuditLog::record('lead.status_changed', ['lead_id' => $lead->id, 'from' => $previous, 'to' => $status]);

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
            $activity = LeadActivity::create([
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'activity_type' => $type,
                'description' => $description,
                'occurred_at' => $occurredAt,
            ]);

            if ($type !== 'note') {
                $lead->forceFill(['last_contacted_at' => $occurredAt])->save();
            }

            return $activity;
        });
    }

    /** @param  array{title: string, description?: ?string, due_at: mixed, assigned_to?: ?int}  $data */
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

        return DB::transaction(function () use ($lead, $data, $actor, $assigneeId) {
            $followUp = new FollowUp([
                'lead_id' => $lead->id,
                'assigned_to' => $assigneeId,
                'created_by' => $actor->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'due_at' => $data['due_at'],
            ]);
            $followUp->forceFill(['status' => 'pending'])->save();

            return $followUp;
        });
    }

    public function completeFollowUp(FollowUp $followUp, User $actor): FollowUp
    {
        return $this->closeFollowUp($followUp, 'completed', $actor);
    }

    public function cancelFollowUp(FollowUp $followUp, User $actor): FollowUp
    {
        return $this->closeFollowUp($followUp, 'cancelled', $actor);
    }

    private function closeFollowUp(FollowUp $followUp, string $status, User $actor): FollowUp
    {
        Gate::forUser($actor)->authorize('update', $followUp);

        return DB::transaction(function () use ($followUp, $status, $actor) {
            $followUp = FollowUp::query()->lockForUpdate()->findOrFail($followUp->getKey());

            if ($followUp->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This follow-up is already closed.']);
            }

            $followUp->forceFill([
                'status' => $status,
                'completed_at' => $status === 'completed' ? now() : null,
            ])->save();

            LeadActivity::create([
                'lead_id' => $followUp->lead_id,
                'user_id' => $actor->id,
                'activity_type' => 'note',
                'description' => ucfirst($status)." follow-up: {$followUp->title}",
                'metadata' => ['follow_up_id' => $followUp->id],
                'occurred_at' => now(),
            ]);

            return $followUp;
        });
    }
}
