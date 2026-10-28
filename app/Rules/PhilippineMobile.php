<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A Philippine mobile number, as a customer would type it: 09XX XXX XXXX or
 * +63 9XX XXX XXXX, with spaces, dashes, dots or brackets anywhere between.
 */
class PhilippineMobile implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^(?:09|\+639)\d{9}$/', self::strip($value)) !== 1) {
            $fail(__('Please enter a Philippine mobile number, like 0917 123 4567.'));
        }
    }

    /**
     * The one way a valid number is stored: 09XXXXXXXXX, so every order
     * reads alike to the cashier however the customer typed it.
     */
    public static function normalize(string $value): string
    {
        $digits = self::strip($value);

        return str_starts_with($digits, '+63') ? '0'.substr($digits, 3) : $digits;
    }

    /**
     * The number without the separators people type between its groups.
     */
    private static function strip(string $value): string
    {
        return preg_replace('/[\s\-.()]/', '', $value) ?? '';
    }
}
