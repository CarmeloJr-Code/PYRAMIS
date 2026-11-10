<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who voided a sale and why. A void takes money out of the day's takings,
     * so it has to answer for itself the way the sale it undoes does. Null on
     * a sale never voided, and on one voided before this was asked.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->restrictOnDelete();
            $table->string('void_reason')->nullable()->after('voided_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn('void_reason');
        });
    }
};
