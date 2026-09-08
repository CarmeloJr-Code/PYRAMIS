<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            // The bake this movement was consumed by, when there was one. Usage
            // recorded by hand at the stockroom has none, which is the honest
            // difference between "the bakery used this" and "this run used it".
            //
            // Restricted, so a run cannot be deleted out from under the ledger
            // that explains it.
            $table->foreignId('production_run_id')
                ->nullable()
                ->after('recorded_by')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('production_run_id');
        });
    }
};
