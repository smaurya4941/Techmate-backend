<?php

namespace App\Services;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\NotificationOutbox;
use App\Models\Order;
use App\Models\Quotation;
use App\Notifications\SafeText;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Renders outbox rows into mail. Everything is read from the stored records at send
 * time (so a retry always reflects the saved lead), and visitor-supplied text goes
 * through SafeText. Returns null when the source record has gone or no longer applies.
 */
class OutboxMessages
{
    public function build(NotificationOutbox $row): ?MailMessage
    {
        return match ($row->type) {
            'lead.customer_confirmation' => $this->customerConfirmation($row),
            'lead.sales_alert' => $this->salesAlert($row),
            'lead.assigned' => $this->leadAssigned($row),
            'followup.reminder', 'followup.overdue' => $this->followUp($row),
            'quotation.sent' => $this->quotationSent($row),
            'order.created' => $this->orderCreated($row),
            default => null,
        };
    }

    private function customerConfirmation(NotificationOutbox $row): ?MailMessage
    {
        $lead = Lead::query()->with('products')->find($row->lead_id);
        if (! $lead) {
            return null;
        }

        $kind = $lead->lead_type === 'quotation' ? 'quote request' : 'enquiry';
        $company = $this->company();

        $mail = (new MailMessage)
            ->subject("We received your {$kind} ({$lead->public_reference})")
            ->greeting('Hello '.SafeText::inline($lead->customer_name).',')
            ->line("Thank you for contacting {$company['name']}. We have received your {$kind}.")
            ->line("Your reference number is **{$lead->public_reference}**. Please quote it in any follow-up.");

        if ($lead->products->isNotEmpty()) {
            $mail->line('What you asked about:');
            foreach ($lead->products as $item) {
                $mail->line("- {$item->quantity} x ".SafeText::inline($item->product_name_snapshot));
            }
        }

        $mail->line('**What happens next:** a member of our sales team will review your request and contact you. This message is an acknowledgement only; it is not a quotation or an order confirmation.');

        $contact = array_filter([$company['email'] ? 'email '.$company['email'] : null, $company['phone'] ? 'call '.$company['phone'] : null]);
        if ($contact) {
            $mail->line('To reach the sales team, '.implode(' or ', $contact).' and quote your reference.');
        }

        return $mail->salutation($company['name'].($company['address'] ? "  \n".SafeText::inline($company['address']) : ''));
    }

    private function salesAlert(NotificationOutbox $row): ?MailMessage
    {
        $lead = Lead::query()->with(['products', 'assignee:id,name'])->find($row->lead_id);
        if (! $lead) {
            return null;
        }

        $kind = $lead->lead_type === 'quotation' ? 'quote request' : 'enquiry';

        $mail = (new MailMessage)
            ->subject("New {$kind}: {$lead->public_reference}")
            ->greeting("New {$kind} received")
            ->line("Reference: **{$lead->public_reference}**")
            ->line('Submitted: '.$this->when($lead->submitted_at ?? $lead->created_at));

        foreach ([
            'Name' => $lead->customer_name, 'Company' => $lead->company_name, 'Email' => $lead->email,
            'Phone' => $lead->phone, 'Subject' => $lead->subject, 'Source' => $lead->source,
            'Assigned to' => $lead->assignee?->name,
        ] as $label => $value) {
            if ($value !== null && $value !== '') {
                $mail->line("{$label}: ".SafeText::inline($value));
            }
        }

        foreach ($lead->products as $item) {
            $mail->line("- {$item->quantity} x ".SafeText::inline($item->product_name_snapshot).($item->product_code_snapshot ? ' ('.SafeText::inline($item->product_code_snapshot).')' : ''));
        }

        if ($lead->message) {
            $mail->line('Message:')->line(SafeText::block($lead->message, 800));
        }

        return $mail->action('Open lead in admin', $this->leadUrl($lead));
    }

    private function leadAssigned(NotificationOutbox $row): ?MailMessage
    {
        $lead = Lead::query()->find($row->lead_id);
        if (! $lead) {
            return null;
        }

        return (new MailMessage)
            ->subject("Lead assigned to you: {$lead->public_reference}")
            ->greeting('A lead has been assigned to you')
            ->line("Reference: **{$lead->public_reference}**")
            ->line('Customer: '.SafeText::inline($lead->customer_name).($lead->company_name ? ' ('.SafeText::inline($lead->company_name).')' : ''))
            ->line('Priority: '.ucfirst($lead->priority))
            ->action('Open lead', $this->leadUrl($lead));
    }

    private function followUp(NotificationOutbox $row): ?MailMessage
    {
        $followUp = FollowUp::query()->with('lead')->find($row->payload['follow_up_id'] ?? 0);

        // A completed or cancelled task no longer needs a reminder.
        if (! $followUp || ! $followUp->isActive() || ! $followUp->lead) {
            return null;
        }

        $overdue = $row->type === 'followup.overdue';

        return (new MailMessage)
            ->subject(($overdue ? 'Overdue follow-up: ' : 'Follow-up due soon: ').SafeText::inline($followUp->title, 120))
            ->greeting($overdue ? 'A follow-up is overdue' : 'A follow-up is coming up')
            ->line('Task: '.SafeText::inline($followUp->title))
            ->line('Due: '.$this->when($followUp->due_at))
            ->line("Lead: {$followUp->lead->public_reference} - ".SafeText::inline($followUp->lead->customer_name))
            ->action('Open lead', $this->leadUrl($followUp->lead));
    }

    private function quotationSent(NotificationOutbox $row): ?MailMessage
    {
        $quotation = Quotation::query()->find($row->payload['quotation_id'] ?? 0);

        // Only an issued offer may reach a customer: never a draft, a withdrawn or superseded one.
        if (! $quotation || ! in_array($quotation->status, ['sent', 'accepted'], true) || ! $quotation->pdf_path) {
            return null;
        }

        $company = $this->company();
        $disk = Storage::disk('local');
        if (! $disk->exists($quotation->pdf_path)) {
            throw new \RuntimeException('Quotation PDF is missing from storage.');
        }

        $mail = (new MailMessage)
            ->subject("Quotation {$quotation->quotation_number} from {$company['name']}")
            ->greeting('Hello '.SafeText::inline($quotation->customer_name_snapshot).',')
            ->line("Please find attached quotation **{$quotation->quotation_number}** (version {$quotation->version}).")
            ->line('Total: '.$quotation->currency_code.' '.number_format((float) $quotation->grand_total, 2))
            ->line($quotation->valid_until ? 'This offer is valid until '.$quotation->valid_until->format('d M Y').'.' : 'Please see the document for the validity period.')
            ->line('To accept, or to ask for changes, reply to this e-mail or contact the sales team quoting the quotation number. Opening this e-mail does not constitute acceptance.')
            ->attachData($disk->get($quotation->pdf_path), $quotation->quotation_number.'.pdf', ['mime' => 'application/pdf']);

        return $mail->salutation($company['name']);
    }

    private function orderCreated(NotificationOutbox $row): ?MailMessage
    {
        $order = Order::query()->with('lead')->find($row->payload['order_id'] ?? 0);
        if (! $order) {
            return null;
        }

        return (new MailMessage)
            ->subject("New order {$order->order_number} ({$order->lead->public_reference})")
            ->greeting('A deal has been won and an order created')
            ->line("Order: **{$order->order_number}**")
            ->line('Customer: '.SafeText::inline($order->customer_name_snapshot).($order->company_name_snapshot ? ' ('.SafeText::inline($order->company_name_snapshot).')' : ''))
            ->line('Order value: '.$order->currency_code.' '.number_format((float) $order->grand_total, 2))
            ->action('Open order', OrderResource::getUrl('view', ['record' => $order], panel: 'admin'));
    }

    /** @return array{name: string, email: ?string, phone: ?string, address: ?string} */
    private function company(): array
    {
        $settings = SiteSettings::all();

        return [
            'name' => $settings['company_name'] ?: 'Techmate Industries',
            'email' => $settings['contact_email'] ?? null,
            'phone' => $settings['contact_phone'] ?? null,
            'address' => $settings['contact_address'] ?? null,
        ];
    }

    private function when(?\DateTimeInterface $at): string
    {
        return $at ? Carbon::instance($at)->timezone(config('techmate.business_timezone'))->format('d M Y, H:i T') : '-';
    }

    private function leadUrl(Lead $lead): string
    {
        return LeadResource::getUrl('view', ['record' => $lead], panel: 'admin');
    }
}
