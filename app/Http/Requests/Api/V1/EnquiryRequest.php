<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class EnquiryRequest extends PublicSubmissionRequest
{
    public const SOURCES = ['website', 'contact_page', 'home_page'];

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->guardRules(),
            ...$this->contactRules(phoneRequired: false),
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'source' => ['nullable', Rule::in(self::SOURCES)],
        ];
    }
}
