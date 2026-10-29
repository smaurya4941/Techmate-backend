<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carrier for a MailMessage already rendered from stored records by OutboxMessages.
 * Sent synchronously (notifyNow) from the outbox job, which owns queuing and retries.
 */
class RenderedMail extends Notification
{
    public function __construct(private readonly MailMessage $message) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->message;
    }
}
