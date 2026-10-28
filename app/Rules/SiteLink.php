<?php

namespace App\Rules;

use App\Models\Brand;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A site-relative path ("/request-quote") or an absolute http(s) URL. */
class SiteLink implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $relative = is_string($value) && preg_match('#^/(?!/)[A-Za-z0-9\-._~/?\#=&%]*$#', $value) === 1;

        if (! $relative && (! is_string($value) || ! Brand::isSafeUrl($value))) {
            $fail('The :attribute must be a site path starting with / or an http(s) URL.');
        }
    }
}
