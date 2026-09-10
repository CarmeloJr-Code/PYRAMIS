<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the Purple Yam Malaybalay catalogue.
     *
     * Transcribed from the business's own menu boards in
     * docs/reference/menu/. Prices are in Philippine pesos.
     *
     * @var array<string, array<string, array<string, float>>>
     */
    protected array $menu = [
        'Ube Cakes' => [
            'Ube Cake' => [
                'Large (10x14)' => 990.00,
                'Medium (7x10)' => 660.00,
                'Round (7x3)' => 500.00,
                'Heart (6" dia)' => 500.00,
                'Tincan Round' => 300.00,
                'Tincan Heart' => 300.00,
                'Messy Cup (12 oz)' => 70.00,
                'Messy Cup (8 oz)' => 40.00,
            ],
            'Ube Custard Cake' => [
                'Round' => 460.00,
                'Slice' => 70.00,
            ],
            'Chobe Cake' => [
                'Tincan/Tub' => 340.00,
            ],
        ],
        'Chocolate Cakes' => [
            'Chocolate Cake' => [
                'Large (10x14)' => 1300.00,
                'Medium (7x10)' => 800.00,
                'Round (7x3)' => 550.00,
                'Heart (6" dia)' => 550.00,
                'Tincan Round' => 340.00,
                'Tincan Heart' => 340.00,
                'Messy Cup (12 oz)' => 70.00,
                'Messy Cup (8 oz)' => 50.00,
            ],
        ],
        'Bars' => [
            'Ube Calamansi Bar' => [
                'Layer' => 465.00,
            ],
            'Ube Creamcheese Bar' => [
                'Layer' => 535.00,
            ],
            'Ube Brazo de Mercedes' => [
                '12 oz' => 130.00,
                '8 oz' => 70.00,
            ],
        ],
        'Merchandise' => [
            'Tote Bag' => [
                'Large (1 large cake)' => 43.00,
                'Medium (up to 2 medium cakes)' => 40.00,
                'Small (4 tincans max cap)' => 23.00,
                'Tiny (2 tincans max cap)' => 20.00,
            ],
        ],
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Only ever seeds an empty catalogue. Once the business has a product
        // list — renamed, repriced, withdrawn — this is no longer the truth
        // about it, and writing the menu board back over a curated catalogue
        // on the next deploy would undo somebody's work.
        if (Product::query()->exists()) {
            return;
        }

        foreach ($this->menu as $categoryName => $products) {
            $category = Category::create([
                'name' => $categoryName,
                'slug' => Str::slug($categoryName),
            ]);

            foreach ($products as $productName => $variants) {
                $product = $category->products()->create([
                    'name' => $productName,
                    'slug' => Str::slug($productName),
                    'is_active' => true,
                ]);

                foreach ($variants as $variantName => $price) {
                    $product->variants()->create([
                        'name' => $variantName,
                        'price' => $price,
                        'is_available' => true,
                    ]);
                }
            }
        }
    }
}
