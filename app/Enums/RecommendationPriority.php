<?php

namespace App\Enums;

/**
 * How pressing a suggestion in an AI reading is.
 *
 * The model chooses this, so nothing here may assume it is well behaved — the
 * page resolves a value with `tryFrom()` and falls back to Medium rather than
 * letting an unrecognised string take the screen down.
 *
 * Separate from ForecastConfidence despite sharing its cases, because the
 * colours run the other way: a high priority is a warning, a high confidence is
 * a reassurance.
 */
enum RecommendationPriority: string
{
    case Low = 'low';

    case Medium = 'medium';

    case High = 'high';

    /**
     * The priority a value resolves to, whatever the model actually returned.
     */
    public static function fromModel(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Medium;
    }

    /**
     * The label shown to a manager.
     */
    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
        };
    }

    /**
     * The badge colour this priority is drawn in.
     */
    public function color(): string
    {
        return match ($this) {
            self::Low => 'zinc',
            self::Medium => 'amber',
            self::High => 'red',
        };
    }
}
