<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of a quotation version: created, submitted, approved, sent,
 * accepted, rejected, expired, revised... Delivery outcomes live in notification_outbox.
 */
class QuotationEvent extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['quotation_id', 'user_id', 'event', 'from_status', 'to_status', 'note', 'metadata', 'occurred_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Quotation events are append-only.'));
        static::deleting(fn () => throw new \LogicException('Quotation events cannot be deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
