<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The reference data a live PYRAMIS needs before anyone can use it.
 *
 * Separate from DatabaseSeeder, which also creates one employee per role for
 * signing in during development. Those accounts share a known password and
 * belong to nobody, which is exactly right for a development database and
 * exactly wrong for the bakery's — production accounts are provisioned one at
 * a time with `php artisan make:employee`, so the password is minted, printed
 * once, and never written down in a repository.
 *
 * Every seeder below leaves an already-populated table alone, so this is safe
 * to run on every deploy: it fills what is empty and never argues with what the
 * business has since changed.
 */
class ProductionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the reference data, and only that.
     */
    public function run(): void
    {
        $this->call([
            CatalogSeeder::class,
            ExpenseCategorySeeder::class,
            IngredientSeeder::class,
            OutletSeeder::class,
        ]);
    }
}
