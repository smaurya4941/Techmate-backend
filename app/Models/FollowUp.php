<?php

namespace App\Models;

use Database\Factories\FollowUpFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUp extends Model
{
    /** @use HasFactory<FollowUpFactory> */
    use HasFactory;

    public const STATUSES = ['open', 'in_progress', 'completed', 'cancelled'];

    public const ACTIVE_STATUSES = ['open', 'in_progress'];

    /** @var list<string> */
    protected $fillable = ['lead_id', 'assigned_to', 'created_by', 'title', 'description', 'due_at', 'remind_before_minutes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    /** Not yet completed or cancelled. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /** Overdue is derived, never stored: active and past its due time. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->active()->where('due_at', '<', now());
    }

    /** Active and due within the current business day (app timezone). */
    public function scopeDueToday(Builder $query): Builder
    {
        return $query->active()->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->where('due_at', '>=', now());
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->active()->where('due_at', '>', now()->endOfDay());
    }

    public function scopeRecentlyCompleted(Builder $query, int $days = 7): Builder
    {
        return $query->where('status', 'completed')->where('completed_at', '>=', now()->subDays($days));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isOverdue(): bool
    {
        return $this->isActive() && $this->due_at->isPast();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
