<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table): void {
            $table->id();

            // One recipe per sellable size. A Round cake and a Slice of it draw
            // different amounts, and everything else in the system — orders,
            // sales, availability — is already counted per variant.
            //
            // Cascading here, unlike the inventory ledger: a recipe describes a
            // variant and means nothing without it, and it is not a record of
            // anything that happened.
            $table->foreignId('product_variant_id')->unique()->constrained()->cascadeOnDelete();

            // How many units one batch of this recipe makes. The business works
            // in batches — "good for 7 round pans" — so the quantities below are
            // per batch, and what a run of N units needs is scaled from here.
            $table->unsignedInteger('yield_quantity');

            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Same Postgres-only pattern as the inventory tables: SQLite cannot
        // ALTER TABLE ADD CONSTRAINT, so CI is the gate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table recipes add constraint recipes_yield_quantity_positive check (yield_quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
