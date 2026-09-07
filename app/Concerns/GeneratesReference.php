<?php

namespace App\Concerns;

/**
 * Gives a model a random, human-readable reference.
 *
 * Random rather than sequential so a reference cannot be walked to enumerate
 * other records, and drawn from a Crockford-style alphabet with I, L, O, U, 0
 * and 1 removed so it can be read aloud without ambiguity.
 *
 * The using model supplies its own prefix via referencePrefix().
 */
trait GeneratesReference
{
    /**
     * Characters a reference is built from.
     */
    private const REFERENCE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * How many random characters follow the prefix. Thirty symbols to the
     * tenth power is roughly 2^49.
     */
    private const REFERENCE_LENGTH = 10;

    /**
     * Assign a reference before the row is written, so no code path can
     * persist a record without one.
     */
    public static function bootGeneratesReference(): void
    {
        static::creating(function (self $model): void {
            // getAttribute, not the property: before the row exists the
            // attribute may simply be absent, which the persisted-shape
            // docblock does not describe.
            if (blank($model->getAttribute('reference'))) {
                $model->reference = static::generateReference();
            }
        });
    }

    /**
     * Build a reference no other record of this model is already using.
     */
    public static function generateReference(): string
    {
        do {
            $suffix = '';

            for ($i = 0; $i < self::REFERENCE_LENGTH; $i++) {
                $suffix .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }

            $reference = static::referencePrefix().$suffix;
        } while (static::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * The prefix identifying what kind of record this is.
     */
    abstract protected static function referencePrefix(): string;
}
