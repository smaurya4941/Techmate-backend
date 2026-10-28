<?php

namespace App\Rules;

use App\Models\Brand;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Absolute http(s) URL only: rejects javascript:, data:, file: and similar schemes. */
class HttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Brand::isSafeUrl($value)) {
            $fail('The :attribute must be a valid http(s) URL.');
        }
    }
}
