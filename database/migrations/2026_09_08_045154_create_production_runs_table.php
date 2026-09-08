<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();

            // What was made. Restricted, like every other record of something
            // that happened: a run must always be able to name its output.
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();

            // The recipe the run was costed against. Kept for the audit trail —
            // what it consumed is in the ledger, not recomputed from here, so a
            // later edit to the recipe cannot rewrite history.
            $table->foreignId('recipe_id')->constrained()->restrictOnDelete();

            // Units produced, not batches: a baker can run part of a batch, and
            // everything else in the system counts this size in units.
            $table->unsignedInteger('quantity');

            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('notes')->nullable();
            $table->dateTime('produced_at')->index();
            $table->timestamps();

            // The log is read per product, newest first.
            $table->index(['product_variant_id', 'produced_at']);
        });

        // Same Postgres-only pattern as the inventory tables: SQLite cannot
        // ALTER TABLE ADD CONSTRAINT, so CI is the gate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table production_runs add constraint production_runs_quantity_positive check (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('production_runs');
    }
};
