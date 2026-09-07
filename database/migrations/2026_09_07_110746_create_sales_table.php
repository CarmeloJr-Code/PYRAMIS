<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();

            // FR-02: a transaction is associated with the location it happened at.
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();

            // Null for a walk-in counter sale (slice 4b). Unique so a pre-order
            // can never be billed twice — Postgres and SQLite both allow
            // repeated NULLs in a unique index, which is exactly what is wanted.
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();

            // Who took the money. Restricted so a sale never loses its author.
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();

            $table->string('status')->index();
            $table->dateTime('sold_at')->index();
            $table->dateTime('voided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
