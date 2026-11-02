<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An email address that can actually receive mail: well formed with nothing
 * the RFC only tolerates (no quoted names, comments, or bare hosts like
 * ana@localhost), on a domain that answers in DNS. Reserved names such as
 * .test and .invalid never do.
 *
 * Checked through the validator rather than by hand so that tests can switch
 * the DNS lookup off with Validator::fakeDnsLookups().
 */
class EmailAddress implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Validator::make(['email' => $value], ['email' => 'email:strict,dns'])->fails()) {
            $fail(__('Please enter a working email address, like ana@gmail.com.'));
        }
    }
}
