<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Customer-facing receipt. Deliberately minimal: reference and greeting only,
 * so no submitted details are re-sent over email.
 */
class SubmissionAcknowledgement extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $kind,
        public readonly string $reference,
        public readonly string $customerName,
    ) {
        $this->onQueue(config('techmate.notifications.queue'));
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("We received your {$this->kind} ({$this->reference})")
            ->greeting('Hello '.SafeText::inline($this->customerName).',')
            ->line("Thank you for contacting Techmate Industries. We have received your {$this->kind}.")
            ->line("Your reference number is **{$this->reference}**. Please quote it in any follow-up.")
            ->line('A member of our team will review it and get back to you. This is an automatic acknowledgement, not a quotation or an approval.')
            ->salutation('Techmate Industries');
    }
}
