<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();

            // Restricted: an ingredient a recipe still calls for cannot be
            // deleted out from under it. Ingredients are deactivated instead.
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();

            // Per batch, in the ingredient's own stocked unit — the same
            // decimal(12,3) the ledger holds, so a requirement and a movement
            // are directly comparable.
            $table->decimal('quantity', 12, 3);

            $table->timestamps();

            // An ingredient appears once in a recipe; two lines for the same one
            // is a data-entry mistake, not a larger requirement.
            $table->unique(['recipe_id', 'ingredient_id']);
        });

        // Same Postgres-only pattern as the inventory tables: SQLite cannot
        // ALTER TABLE ADD CONSTRAINT, so CI is the gate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table recipe_items add constraint recipe_items_quantity_positive check (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_items');
    }
};
