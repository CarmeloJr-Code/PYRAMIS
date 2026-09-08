<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stock_movements', function (Blueprint $table): void {
            // The sale that took these goods off the shelf, when one did.
            // Voiding a sale credits back against the same id, so the pair reads
            // as the single event it was.
            $table->foreignId('sale_id')
                ->nullable()
                ->after('restock_id')
                ->constrained()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_stock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sale_id');
        });
    }
};
