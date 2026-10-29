<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable record of an e-mail the system owes. Created in the same transaction as the
 * business change that requires it; delivered by DeliverOutboxMessage. "sent" means the
 * mail transport accepted the message, not that it reached the recipient's inbox.
 */
class NotificationOutbox extends Model
{
    public const TYPES = [
        'lead.customer_confirmation' => 'Customer confirmation',
        'lead.sales_alert' => 'Sales alert',
        'lead.assigned' => 'Lead assigned',
        'followup.reminder' => 'Follow-up reminder',
        'followup.overdue' => 'Follow-up overdue',
        'quotation.sent' => 'Quotation to customer',
        'order.created' => 'Order created',
    ];

    public const MAX_ATTEMPTS = 3;

    protected $table = 'notification_outbox';

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
