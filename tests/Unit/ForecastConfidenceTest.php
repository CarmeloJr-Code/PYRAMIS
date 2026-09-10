<?php

namespace Tests\Unit;

use App\Enums\ForecastConfidence;
use PHPUnit\Framework\TestCase;

class ForecastConfidenceTest extends TestCase
{
    public function test_it_takes_the_model_at_its_word_when_the_word_is_one_of_ours(): void
    {
        $this->assertSame(ForecastConfidence::Low, ForecastConfidence::fromModel('low'));
        $this->assertSame(ForecastConfidence::Medium, ForecastConfidence::fromModel('medium'));
        $this->assertSame(ForecastConfidence::High, ForecastConfidence::fromModel('high'));
    }

    public function test_an_answer_it_cannot_read_is_a_reason_for_less_confidence(): void
    {
        // Never Medium: an unreadable answer about certainty must not raise it.
        $this->assertSame(ForecastConfidence::Low, ForecastConfidence::fromModel('very sure'));
        $this->assertSame(ForecastConfidence::Low, ForecastConfidence::fromModel('HIGH'));
        $this->assertSame(ForecastConfidence::Low, ForecastConfidence::fromModel(''));
        $this->assertSame(ForecastConfidence::Low, ForecastConfidence::fromModel(null));
    }

    public function test_it_is_labelled_in_words_that_cannot_read_as_an_interval(): void
    {
        // BR-008 forbids presenting a reading as a guaranteed prediction, so
        // the label is a hedge in plain English rather than a percentage.
        $this->assertSame('Not very sure', ForecastConfidence::Low->label());
        $this->assertSame('Fairly sure', ForecastConfidence::Medium->label());
        $this->assertSame('Quite sure', ForecastConfidence::High->label());
    }
}
