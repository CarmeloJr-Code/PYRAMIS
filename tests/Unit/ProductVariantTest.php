<?php

namespace Tests\Unit;

use App\Models\ProductVariant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductVariantTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function shapeProvider(): array
    {
        return [
            'a sheet, large' => ['Large (10x14)', 'large'],
            'a sheet, medium' => ['Medium (7x10)', 'medium'],
            'a round' => ['Round (7x3)', 'round'],
            'a heart' => ['Heart (6" dia)', 'heart'],
            // A tincan is named before the cake shape it holds.
            'a round tincan' => ['Tincan Round', 'tincan-round'],
            'a heart tincan' => ['Tincan Heart', 'tincan-heart'],
            'a messy cup' => ['Messy Cup (12 oz)', 'messy-cup'],
            'written in any case' => ['messy cup (8 OZ)', 'messy-cup'],
            'a slice' => ['Slice', 'slice'],
            'a tincan of no stated shape' => ['Tincan/Tub', 'tincan-round'],
            'a layer' => ['Layer', null],
            'a cup size alone' => ['8 oz', null],
        ];
    }

    #[DataProvider('shapeProvider')]
    public function test_a_size_is_known_by_its_shape(string $name, ?string $expected): void
    {
        $this->assertSame($expected, (new ProductVariant(['name' => $name]))->shape());
    }
}
