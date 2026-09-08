<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restock_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();

            // What the Administrator asked for.
            $table->unsignedInteger('quantity_requested');

            // What the Baker actually set aside. Null until the run is prepared,
            // and the figure that moves on delivery — a bake can come up short,
            // and the ledger has to record what really travelled.
            $table->unsignedInteger('quantity_prepared')->nullable();

            $table->timestamps();

            // A size appears once per restock; two lines for the same one is a
            // data-entry mistake, not a larger request.
            $table->unique(['restock_id', 'product_variant_id']);
        });

        // Same Postgres-only pattern as the other ledgers: SQLite cannot ALTER
        // TABLE ADD CONSTRAINT, so CI is the gate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table restock_items add constraint restock_items_quantity_requested_positive check (quantity_requested > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('restock_items');
    }
};
