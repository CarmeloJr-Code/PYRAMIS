<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();

            // Restricted, not cascading: an ingredient with a stock history is
            // deactivated, never deleted, so the ledger cannot be erased by
            // removing what it describes.
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();

            $table->string('type')->index();

            // Signed: positive adds stock, negative removes it. Current stock is
            // the sum of this column, never a stored figure someone overwrote
            // (Phase 5 exit criterion).
            $table->decimal('quantity', 12, 3);

            // Who moved it. Restricted so a movement never loses its author.
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();

            $table->string('note')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            // The ledger is always read for one ingredient, newest first.
            $table->index(['ingredient_id', 'occurred_at']);
        });

        // Same Postgres-only pattern as sale_items: SQLite cannot ALTER TABLE
        // ADD CONSTRAINT, so CI is the gate for these.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table inventory_movements add constraint inventory_movements_quantity_non_zero check (quantity <> 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
