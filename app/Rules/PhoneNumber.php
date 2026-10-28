<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Lenient international phone check: optional leading +, digits with common
 * separators, 7-15 digits in total (E.164 upper bound). Not a carrier lookup.
 */
class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = is_string($value) ? strlen(preg_replace('/\D/', '', $value)) : 0;

        $valid = is_string($value)
            && preg_match('/^\+?[0-9][0-9\s().\-]{5,28}$/', $value) === 1
            && $digits >= 7
            && $digits <= 15;

        if (! $valid) {
            $fail('The :attribute must be a valid phone number (7-15 digits, optional leading +).');
        }
    }
}
