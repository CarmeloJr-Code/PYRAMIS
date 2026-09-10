<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the headings expenses are filed under.
     *
     * No expense categories appear in the PRD or docs/, so these are the
     * generic operating headings any bakery has, for the Administrator to
     * rename or retire at /employee/expenses/categories — the same placeholder
     * reasoning as OutletSeeder, rather than inventing a chart of accounts the
     * business never gave.
     */
    public function run(): void
    {
        // These are placeholders the Administrator is expected to rename or
        // retire, so seeding them again after that would be putting back
        // exactly what somebody deliberately changed.
        if (ExpenseCategory::query()->exists()) {
            return;
        }

        $categories = [
            'Ingredients and supplies',
            'Packaging',
            'Utilities',
            'Rent',
            'Transport and delivery',
            'Equipment and maintenance',
            'Wages and benefits',
            'Other',
        ];

        foreach ($categories as $name) {
            ExpenseCategory::create([
                'name' => $name,
                'is_active' => true,
            ]);
        }
    }
}
