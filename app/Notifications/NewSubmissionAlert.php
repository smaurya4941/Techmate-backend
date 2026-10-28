<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Internal alert for the sales inbox. Carries the data staff need to respond;
 * the database record remains the source of truth.
 */
class NewSubmissionAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, ?string>  $details  label => value (rendered as sanitized text)
     * @param  list<string>  $items  one line per requested item
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $reference,
        public readonly array $details,
        public readonly array $items = [],
        public readonly ?string $message = null,
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
        $mail = (new MailMessage)
            ->subject("New {$this->kind}: {$this->reference}")
            ->greeting("New {$this->kind} received")
            ->line("Reference: **{$this->reference}**");

        foreach ($this->details as $label => $value) {
            if ($value !== null && $value !== '') {
                $mail->line("{$label}: ".SafeText::inline($value));
            }
        }

        foreach ($this->items as $item) {
            $mail->line('- '.SafeText::inline($item));
        }

        if ($this->message) {
            $mail->line('Message:')->line(SafeText::block($this->message));
        }

        return $mail->line('Follow up from the admin workspace using the reference above.');
    }
}
