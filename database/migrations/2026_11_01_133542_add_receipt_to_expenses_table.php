<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // The photo of the receipt, as proof of what was typed in. Base64 in
            // the database rather than a file on disk: the services' disks are
            // neither shared nor kept, so the database is the one place it
            // survives a deploy. Nullable for expenses filed before it was asked.
            $table->longText('receipt')->nullable();
            $table->string('receipt_mime')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['receipt', 'receipt_mime']);
        });
    }
};
