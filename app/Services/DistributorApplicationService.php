<?php

namespace App\Services;

use App\Models\DistributorApplication;
use Illuminate\Database\UniqueConstraintViolationException;

class DistributorApplicationService
{
    private const FIELDS = [
        'contact_name', 'company_name', 'email', 'phone', 'city', 'state', 'country_code',
        'website_url', 'business_type', 'experience_range', 'regions_served',
        'product_interests', 'message',
    ];

    public function __construct(private readonly SubmissionNotifier $notifier) {}

    /** @param  array<string, mixed>  $data  validated input */
    public function submit(array $data): DistributorApplication
    {
        $attempts = 0;

        while (true) {
            try {
                // Single-row insert: atomic on its own, no explicit transaction required.
                $application = new DistributorApplication(array_intersect_key($data, array_flip(self::FIELDS)));
                $application->status = 'new';
                $application->consent_at = ! empty($data['consent']) ? now() : null;
                $application->save();

                break;
            } catch (UniqueConstraintViolationException $e) {
                if (++$attempts >= 3) {
                    throw $e;
                }
                // Hand out a fresh reference on the next attempt.
            }
        }

        $this->notifier->distributorApplication($application);

        return $application;
    }
}
