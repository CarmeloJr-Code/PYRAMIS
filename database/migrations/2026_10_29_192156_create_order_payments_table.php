<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table): void {
            $table->id();

            // One payment per pre-order: GCash is paid once, up front.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

            // What the customer sends as proof — the 13-digit GCash reference,
            // a screenshot of the receipt, or both.
            $table->string('gcash_reference', 13)->nullable();

            // Base64 in the database rather than a file on disk: the storefront
            // and the workspace are separate services whose disks are neither
            // shared nor kept, so the database is the one place both can read.
            $table->longText('receipt')->nullable();
            $table->string('receipt_mime')->nullable();
            $table->dateTime('submitted_at')->nullable();

            // What the cashier saw arrive, and who checked it.
            $table->decimal('amount', 10, 2)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
