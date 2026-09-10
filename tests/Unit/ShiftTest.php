<?php

namespace Tests\Unit;

use App\Models\Shift;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShiftTest extends TestCase
{
    /**
     * @return list<array{0: int, 1: string}>
     */
    public static function minutesProvider(): array
    {
        return [
            'a whole hour drops the minutes' => [60, '1h'],
            'a full day' => [480, '8h'],
            'an hour and a half' => [90, '1h 30m'],
            'under an hour still says the hour' => [45, '0h 45m'],
            'an overnight shift keeps counting past the day' => [600, '10h'],
            'nothing rostered' => [0, '0h'],
        ];
    }

    #[DataProvider('minutesProvider')]
    public function test_a_stretch_of_minutes_reads_as_hours_and_minutes(int $minutes, string $expected): void
    {
        $this->assertSame($expected, Shift::formatMinutes($minutes));
    }
}
