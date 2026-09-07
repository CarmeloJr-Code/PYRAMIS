<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * PYRAMIS has no public registration, so the first accounts come from here.
     * One employee per role, for signing in during development.
     */
    public function run(): void
    {
        User::factory()->administrator()->create([
            'name' => 'Purple Yam Administrator',
            'email' => 'administrator@purpleyam.test',
        ]);

        User::factory()->baker()->create([
            'name' => 'Purple Yam Baker',
            'email' => 'baker@purpleyam.test',
        ]);

        User::factory()->cashier()->create([
            'name' => 'Purple Yam Cashier',
            'email' => 'cashier@purpleyam.test',
        ]);

        $this->call([
            CatalogSeeder::class,
            OutletSeeder::class,
        ]);
    }
}
