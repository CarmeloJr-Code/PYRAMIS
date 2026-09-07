<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            // A sellable size or form of a product — "Large (10x14)", "Slice",
            // "Messy Cup (8 oz)" — each carrying its own price.
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'name']);
        });

        // Money must never go negative (docs/database.md: use database constraints
        // where they matter). SQLite cannot add a CHECK to an existing table, so the
        // constraint is Postgres-only and CI — which runs Postgres 17 — is its gate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table product_variants add constraint product_variants_price_non_negative check (price >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
