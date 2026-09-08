<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();

            // What the ingredient is stocked in — see App\Enums\IngredientUnit.
            $table->string('unit');

            // The level at or below which the ingredient counts as low. Zero
            // means the business has not set one, not "reorder immediately".
            $table->decimal('reorder_level', 12, 3)->default(0);

            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Same Postgres-only pattern as sale_items: SQLite cannot ALTER TABLE
        // ADD CONSTRAINT, so CI is the gate for these.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table ingredients add constraint ingredients_reorder_level_non_negative check (reorder_level >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
