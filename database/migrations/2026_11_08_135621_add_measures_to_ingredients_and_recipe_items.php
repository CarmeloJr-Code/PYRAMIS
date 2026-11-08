<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            // How many grams or milliliters one stocked pack, can or piece holds.
            // Null for an ingredient stocked by weight or volume, where the
            // figure is implied (1 kg = 1000 g), and until the business sets it.
            $table->decimal('content_per_unit', 12, 3)->nullable()->after('unit');
            $table->string('content_measure')->nullable()->after('content_per_unit');

            // What one level cup weighs, in the ingredient's grams or
            // milliliters. Spoons are worked out from it.
            $table->decimal('cup_weight', 12, 3)->nullable()->after('content_measure');
        });

        Schema::table('recipe_items', function (Blueprint $table): void {
            // What the quantity is written in — see App\Enums\RecipeMeasure. Every
            // existing line was written in the stocked unit, so that is the
            // default and they read exactly as before.
            $table->string('measure')->default('unit')->after('quantity');
        });

        // Same Postgres-only pattern as the original tables: SQLite cannot
        // ALTER TABLE ADD CONSTRAINT, so CI is the gate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table ingredients add constraint ingredients_content_per_unit_positive check (content_per_unit is null or content_per_unit > 0)');
            DB::statement('alter table ingredients add constraint ingredients_cup_weight_positive check (cup_weight is null or cup_weight > 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table ingredients drop constraint if exists ingredients_content_per_unit_positive');
            DB::statement('alter table ingredients drop constraint if exists ingredients_cup_weight_positive');
        }

        Schema::table('recipe_items', function (Blueprint $table): void {
            $table->dropColumn('measure');
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn(['content_per_unit', 'content_measure', 'cup_weight']);
        });
    }
};
