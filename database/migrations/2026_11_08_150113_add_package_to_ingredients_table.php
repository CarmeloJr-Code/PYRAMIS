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
            // What the ingredient arrives in from the supplier — a sack, a tray,
            // a bottle — and how many stocked units one of those is. Only a way
            // of counting: the ledger stays in the stocked unit.
            $table->string('package_name', 30)->nullable()->after('cup_weight');
            $table->decimal('package_size', 12, 3)->nullable()->after('package_name');
        });

        // Same Postgres-only pattern as the other ingredient constraints: SQLite
        // cannot ALTER TABLE ADD CONSTRAINT, so CI is the gate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table ingredients add constraint ingredients_package_size_positive check (package_size is null or package_size > 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table ingredients drop constraint if exists ingredients_package_size_positive');
        }

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn(['package_name', 'package_size']);
        });
    }
};
