<?php

namespace Tests\Unit;

use App\Enums\RecommendationPriority;
use PHPUnit\Framework\TestCase;

class RecommendationPriorityTest extends TestCase
{
    public function test_it_takes_the_model_at_its_word_when_the_word_is_one_of_ours(): void
    {
        $this->assertSame(RecommendationPriority::Low, RecommendationPriority::fromModel('low'));
        $this->assertSame(RecommendationPriority::Medium, RecommendationPriority::fromModel('medium'));
        $this->assertSame(RecommendationPriority::High, RecommendationPriority::fromModel('high'));
    }

    public function test_a_priority_it_cannot_read_lands_in_the_middle(): void
    {
        // Neither dismissed nor raised to a warning: an unrecognised string
        // must not take the screen down, and must not shout either.
        $this->assertSame(RecommendationPriority::Medium, RecommendationPriority::fromModel('urgent'));
        $this->assertSame(RecommendationPriority::Medium, RecommendationPriority::fromModel('HIGH'));
        $this->assertSame(RecommendationPriority::Medium, RecommendationPriority::fromModel(''));
        $this->assertSame(RecommendationPriority::Medium, RecommendationPriority::fromModel(null));
    }

    public function test_a_high_priority_is_drawn_as_a_warning_not_a_reassurance(): void
    {
        // The colours run the other way from ForecastConfidence, which is why
        // the two enums stay apart despite sharing their cases.
        $this->assertSame('red', RecommendationPriority::High->color());
        $this->assertSame('zinc', RecommendationPriority::Low->color());
    }
}
