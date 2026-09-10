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
        // Only ever seeds an empty list. The name and address here are
        // placeholders; once the Administrator has corrected them, or added the
        // real pickup outlets, this must not put the placeholder back.
        if (Outlet::query()->exists()) {
            return;
        }

        $mainBranch = Outlet::create([
            'name' => 'Main Branch',
            'address' => 'Malaybalay, Bukidnon',
            'phone' => null,
            'is_active' => true,
        ]);

        // Set directly rather than through create(): is_main_branch is
        // deliberately not mass-assignable, because which outlet runs
        // production is structural rather than something a form may change.
        $mainBranch->is_main_branch = true;
        $mainBranch->save();
    }
}
