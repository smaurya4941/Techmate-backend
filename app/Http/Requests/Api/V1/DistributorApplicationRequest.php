<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DistributorApplication;
use App\Rules\PhoneNumber;
use Illuminate\Validation\Rule;

class DistributorApplicationRequest extends PublicSubmissionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->guardRules(),
            'contact_name' => ['required', 'string', 'min:2', 'max:150'],
            'company_name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', new PhoneNumber],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'website_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'business_type' => ['required', Rule::in(DistributorApplication::BUSINESS_TYPES)],
            'experience_range' => ['nullable', Rule::in(DistributorApplication::EXPERIENCE_RANGES)],
            'regions_served' => ['required', 'array', 'min:1', 'max:10'],
            'regions_served.*' => ['required', 'string', 'min:2', 'max:100', 'distinct'],
            'product_interests' => ['nullable', 'array', 'max:10'],
            'product_interests.*' => ['required', 'string', 'min:2', 'max:60', 'distinct'],
            'message' => ['nullable', 'string', 'max:3000'],
            'consent' => ['nullable', 'boolean'],
        ];
    }
}
