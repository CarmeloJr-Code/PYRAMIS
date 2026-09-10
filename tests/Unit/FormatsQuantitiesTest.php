<?php

namespace Tests\Unit;

use App\Concerns\FormatsQuantities;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormatsQuantitiesTest extends TestCase
{
    /**
     * The trait on nothing in particular, so the arithmetic is tested without
     * a model, a connection or a ledger behind it.
     */
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class
        {
            use FormatsQuantities;
        };
    }

    /**
     * @return list<array{0: int, 1: string}>
     */
    public static function formattedProvider(): array
    {
        return [
            'a whole number keeps no decimals' => [12000, '12'],
            'a half shows one place' => [1500, '1.5'],
            'a thousandth shows all three' => [1001, '1.001'],
            'trailing zeros are trimmed to what carries meaning' => [1250, '1.25'],
            'nothing is nothing' => [0, '0'],
            'a negative keeps its sign' => [-2500, '-2.5'],
            'thousands are separated for reading' => [1234567, '1,234.567'],
        ];
    }

    #[DataProvider('formattedProvider')]
    public function test_a_quantity_is_formatted_for_reading(int $thousandths, string $expected): void
    {
        $this->assertSame($expected, $this->subject::formatQuantity($thousandths));
    }

    public function test_a_stored_quantity_carries_no_thousands_separator(): void
    {
        // A separator here would be a bug rather than a courtesy: this figure
        // goes back to the database and into comparisons.
        $this->assertSame('1234.567', $this->subject::quantityFromThousandths(1234567));
        $this->assertSame('12.000', $this->subject::quantityFromThousandths(12000));
        $this->assertSame('-2.500', $this->subject::quantityFromThousandths(-2500));
    }

    public function test_a_decimal_quantity_becomes_the_integer_thousandths_worked_in(): void
    {
        $this->assertSame(1500, $this->subject::quantityToThousandths('1.5'));
        $this->assertSame(1500, $this->subject::quantityToThousandths(1.5));
        $this->assertSame(0, $this->subject::quantityToThousandths('0'));
        $this->assertSame(-2500, $this->subject::quantityToThousandths('-2.5'));
    }

    public function test_a_quantity_finer_than_a_thousandth_is_rounded_to_one(): void
    {
        $this->assertSame(1235, $this->subject::quantityToThousandths('1.2345'));
        $this->assertSame(1234, $this->subject::quantityToThousandths('1.2344'));
    }

    public function test_a_quantity_survives_the_round_trip(): void
    {
        // The float step is where a quantity would quietly lose a thousandth,
        // so the two halves are checked against each other rather than apart.
        $this->assertSame(
            '2.345',
            $this->subject::quantityFromThousandths(
                $this->subject::quantityToThousandths('2.345'),
            ),
        );
    }
}
