<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Restricted, not cascading: a variant that has been ordered must
            // stay resolvable so the line can still name what was bought.
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // Price at the moment of ordering. Catalogue prices change; an order
            // must remember what was actually charged (docs/database.md).
            $table->decimal('unit_price', 10, 2);

            $table->timestamps();

            $table->unique(['order_id', 'product_variant_id']);
        });

        // Quantities and money must never go negative. SQLite cannot add a CHECK
        // to an existing table, so these are Postgres-only and CI is their gate —
        // the same asymmetry as product_variants.price.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table order_items add constraint order_items_quantity_positive check (quantity > 0)');
            DB::statement('alter table order_items add constraint order_items_unit_price_non_negative check (unit_price >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
