<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPhoto>
 */
class ProductPhotoFactory extends Factory
{
    /**
     * A product's own photo. The bytes stand in for a JPG; nothing reads them
     * as an image once the upload rules have let them in.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'mime' => 'image/jpeg',
            'data' => base64_encode(fake()->sha256()),
        ];
    }

    /**
     * Indicate that the photo is of one size of the product.
     */
    public function forSize(ProductVariant $size): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_id' => $size->product_id,
            'product_variant_id' => $size->id,
        ]);
    }
}
