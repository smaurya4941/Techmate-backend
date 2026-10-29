<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only audit record (table: audit_logs). */
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    /** @var list<string> */
    protected $fillable = ['event', 'actor_id', 'subject_type', 'subject_id', 'context'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit entries are append-only.'));
        static::deleting(fn () => throw new \LogicException('Audit entries cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
