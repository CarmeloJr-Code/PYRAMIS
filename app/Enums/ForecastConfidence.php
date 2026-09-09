<?php

namespace App\Enums;

/**
 * How sure an AI reading says it is of itself.
 *
 * This is the model's own hedge, not a statistical interval — a short window or
 * a flat trend are reasons to say "low", and saying so honestly is worth more
 * than sounding certain. The page labels it in those words so it cannot be read
 * as a confidence interval, which BR-008 forbids.
 *
 * Falls back to Low rather than Medium: an unreadable answer about certainty is
 * a reason for less confidence, never more.
 */
enum ForecastConfidence: string
{
    case Low = 'low';

    case Medium = 'medium';

    case High = 'high';

    /**
     * The confidence a value resolves to, whatever the model actually returned.
     */
    public static function fromModel(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Low;
    }

    /**
     * The label shown to a manager.
     */
    public function label(): string
    {
        return match ($this) {
            self::Low => 'Not very sure',
            self::Medium => 'Fairly sure',
            self::High => 'Quite sure',
        };
    }

    /**
     * The badge colour this confidence is drawn in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Low => 'amber',
            self::Medium => 'blue',
            self::High => 'green',
        };
    }
}
