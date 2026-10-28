<?php

namespace App\Models;

use Database\Factories\LeadActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit record: rows can be created but not edited or deleted
 * through Eloquent. (They are removed only with their lead by the FK cascade.)
 */
class LeadActivity extends Model
{
    /** @use HasFactory<LeadActivityFactory> */
    use HasFactory;

    public const TYPES = ['note', 'call', 'email', 'meeting', 'status_change', 'assignment'];

    /** @var list<string> */
    protected $fillable = [
        'lead_id', 'user_id', 'activity_type', 'description', 'old_status',
        'new_status', 'metadata', 'occurred_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Lead activities are append-only.'));
        static::deleting(fn () => throw new \LogicException('Lead activities cannot be deleted.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
