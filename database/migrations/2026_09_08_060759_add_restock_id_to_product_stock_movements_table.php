<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stock_movements', function (Blueprint $table): void {
            // The restock this movement was part of, when it came from one. A
            // delivery writes two rows against the same restock — one off the
            // main branch's shelf, one onto the outlet's — so the pair can
            // always be read back as the single transfer it was.
            $table->foreignId('restock_id')
                ->nullable()
                ->after('production_run_id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_stock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('restock_id');
        });
    }
};
