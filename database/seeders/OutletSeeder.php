<?php

namespace Database\Seeders;

use App\Models\Outlet;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OutletSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the pickup locations.
     *
     * No outlet names or addresses appear in the PRD or docs/, so this is a
     * placeholder for the Administrator to rename at /employee/outlets rather
     * than invented business data.
     */
    public function run(): void
    {
        Outlet::create([
            'name' => 'Main Branch',
            'address' => 'Malaybalay, Bukidnon',
            'phone' => null,
            'is_active' => true,
        ]);
    }
}
