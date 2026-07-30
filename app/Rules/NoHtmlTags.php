<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects any value containing an HTML/script tag (`<...>`), so free-text
 * fields (name, description, notes, address, ...) can never store raw
 * markup — closes the stored-HTML gap found in QA without touching normal
 * Arabic/English text, numbers, or punctuation (none of which use `<`/`>`).
 */
class NoHtmlTags implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if ($value !== strip_tags($value)) {
            $fail('حقل :attribute لا يمكن أن يحتوي على وسوم HTML.');
        }
    }
}
