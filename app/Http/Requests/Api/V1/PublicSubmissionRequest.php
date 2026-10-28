<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared base for anonymous write endpoints: contact-field rules, a honeypot
 * and explicit rejection of fields only staff may set.
 */
abstract class PublicSubmissionRequest extends FormRequest
{
    /** Workflow / pricing fields a visitor must never be able to supply. */
    private const PRIVILEGED = [
        'status', 'priority', 'assigned_to', 'public_reference', 'reference', 'internal_notes',
        'reviewed_by', 'reviewed_at', 'lead_type', 'closed_at', 'price', 'unit_price', 'discount',
        'tax', 'total', 'subtotal', 'currency_code',
    ];

    public function authorize(): bool
    {
        return true; // Anonymous public endpoint; abuse is handled by throttling.
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => strtoupper($this->input('country_code'))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    protected function guardRules(): array
    {
        $rules = [
            // Honeypot: invisible to humans, so any value means an automated submission.
            'hp_field' => ['prohibited'],
        ];

        foreach (self::PRIVILEGED as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /** @return array<string, array<int, mixed>> */
    protected function contactRules(bool $phoneRequired): array
    {
        return [
            'customer_name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => [$phoneRequired ? 'required' : 'nullable', 'string', new PhoneNumber],
            'company_name' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-]*$/'],
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'consent' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'hp_field.prohibited' => 'The submission could not be accepted.',
            '*.prohibited' => 'This field cannot be set through the public API.',
        ];
    }
}
