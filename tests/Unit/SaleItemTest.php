<?php

namespace Tests\Unit;

use App\Models\SaleItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SaleItemTest extends TestCase
{
    /**
     * The same matrix OrderItemTest runs, deliberately repeated: the two lines
     * carry their own copy of the arithmetic, so a fix to one is not a fix to
     * the other.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    public static function lineProvider(): array
    {
        return [
            'a whole peso' => ['25.00', 4, 10000],
            'the usual awkward price' => ['19.99', 3, 5997],
            'a price the float cannot hold exactly' => ['1.15', 1, 115],
            'another of the same' => ['8.30', 7, 5810],
            'a single centavo' => ['0.01', 100, 100],
            'nothing sold' => ['45.00', 0, 0],
            'a large sale' => ['1250.00', 40, 5000000],
        ];
    }

    #[DataProvider('lineProvider')]
    public function test_a_line_comes_to_the_price_times_the_quantity_in_centavos(string $price, int $quantity, int $expected): void
    {
        $item = new SaleItem(['unit_price' => $price, 'quantity' => $quantity]);

        $this->assertSame($expected, $item->subtotalInCentavos());
    }
}
