<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');

            // What was actually charged. A sale is the record of money that
            // changed hands, so it carries its own price rather than reading
            // through to the catalogue or the originating order.
            $table->decimal('unit_price', 10, 2);

            $table->timestamps();

            $table->unique(['sale_id', 'product_variant_id']);
        });

        // Same Postgres-only pattern as order_items: SQLite cannot ALTER TABLE
        // ADD CONSTRAINT, so CI is the gate for these.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table sale_items add constraint sale_items_quantity_positive check (quantity > 0)');
            DB::statement('alter table sale_items add constraint sale_items_unit_price_non_negative check (unit_price >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
