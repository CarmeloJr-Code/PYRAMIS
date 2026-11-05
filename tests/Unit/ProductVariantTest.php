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
            'a sheet, large' => ['Large (10x14)', 'rectangular'],
            'a sheet, medium' => ['Medium (7x10)', 'rectangular'],
            'a round' => ['Round (7x3)', 'round'],
            'a heart' => ['Heart (6" dia)', 'heart'],
            // A tincan is a tincan whatever it is shaped like inside.
            'a round tincan' => ['Tincan Round', 'tincan'],
            'a heart tincan' => ['Tincan Heart', 'tincan'],
            'a messy cup' => ['Messy Cup (12 oz)', 'messy-cup'],
            'written in any case' => ['messy cup (8 OZ)', 'messy-cup'],
            'a slice' => ['Slice', null],
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
