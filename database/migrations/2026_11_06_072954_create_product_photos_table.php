<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_photos', function (Blueprint $table): void {
            $table->id();

            // A product's own photo has no size; a size's photo names both.
            // Removing either takes its photos with it.
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();

            // Base64 in the database rather than a file on disk: the storefront
            // and the workspace are separate services whose disks are neither
            // shared nor kept, so the database is the one place both can read.
            $table->string('mime');
            $table->longText('data');

            $table->timestamps();

            // One photo per product and one per size. Nulls count as equal, so
            // a product cannot hold two photos of its own either (Postgres;
            // SQLite ignores the clause, and the code replaces rather than adds).
            $table->unique(['product_id', 'product_variant_id'])->nullsNotDistinct();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_photos');
    }
};
