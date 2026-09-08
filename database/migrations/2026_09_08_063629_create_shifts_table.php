<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();

            // Where the shift is worked. Bakers are at the main branch and
            // cashiers are at an outlet, so a shift without a place would not
            // say what it is.
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // What the shift is for — "Morning bake", "Counter". Free text: the
            // spec asks for shifts and assignments, not a taxonomy of them.
            $table->string('name');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();

            // The schedule is read a day or a week at a time, often per place.
            $table->index('starts_at');
            $table->index(['outlet_id', 'starts_at']);
        });

        // Same Postgres-only pattern as the other tables: SQLite cannot ALTER
        // TABLE ADD CONSTRAINT, so CI is the gate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table shifts add constraint shifts_end_after_start check (ends_at > starts_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
