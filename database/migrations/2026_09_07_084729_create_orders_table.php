<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();

            // The customer's only handle on their order — they hold no account
            // (BR-001), so this is random rather than sequential.
            $table->string('reference')->unique();

            // An outlet with orders against it must not disappear.
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();

            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_email')->nullable();
            $table->dateTime('pickup_at');
            $table->text('notes')->nullable();
            $table->string('status')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
