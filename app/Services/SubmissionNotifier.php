<?php

namespace App\Services;

use App\Models\DistributorApplication;
use App\Models\Lead;
use App\Notifications\NewSubmissionAlert;
use App\Notifications\SubmissionAcknowledgement;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Queues the customer acknowledgement and the internal alert for a saved
 * submission. Called after the database transaction has committed; a queueing
 * failure is logged but never fails the request, because the record is already
 * safely stored. "Queued" is not "delivered": see docs for monitoring.
 */
class SubmissionNotifier
{
    /** @param  list<array{name: string, code: ?string, quantity: int}>  $items */
    public function lead(Lead $lead, string $kind, array $items = []): void
    {
        $this->dispatch(
            $lead->email,
            new SubmissionAcknowledgement($kind, $lead->public_reference, $lead->customer_name),
            new NewSubmissionAlert(
                $kind,
                $lead->public_reference,
                [
                    'Name' => $lead->customer_name,
                    'Company' => $lead->company_name,
                    'Email' => $lead->email,
                    'Phone' => $lead->phone,
                    'Subject' => $lead->subject,
                ],
                array_map(fn (array $i) => "{$i['quantity']} x {$i['name']}".($i['code'] ? " ({$i['code']})" : ''), $items),
                $lead->message,
            ),
        );
    }

    public function distributorApplication(DistributorApplication $application): void
    {
        $this->dispatch(
            $application->email,
            new SubmissionAcknowledgement('distributor application', $application->public_reference, $application->contact_name),
            new NewSubmissionAlert(
                'distributor application',
                $application->public_reference,
                [
                    'Contact' => $application->contact_name,
                    'Company' => $application->company_name,
                    'Email' => $application->email,
                    'Phone' => $application->phone,
                    'Business type' => $application->business_type,
                    'Regions' => implode(', ', $application->regions_served ?? []),
                ],
                [],
                $application->message,
            ),
        );
    }

    private function dispatch(?string $customerEmail, SubmissionAcknowledgement $ack, NewSubmissionAlert $alert): void
    {
        try {
            if ($customerEmail) {
                Notification::route('mail', $customerEmail)->notify($ack);
            }

            if ($internal = config('techmate.sales_notification_email')) {
                Notification::route('mail', $internal)->notify($alert);
            }
        } catch (Throwable $e) {
            Log::error('Could not queue submission notifications.', [
                'reference' => $alert->reference,
                'exception' => $e::class,
            ]);
        }
    }
}
