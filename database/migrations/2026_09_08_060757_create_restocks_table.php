<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restocks', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();

            // Where the goods are going. The source is always the main branch
            // under BR-004, so it is not a column: an outlet never supplies
            // another outlet, and storing the obvious would invite the two to
            // disagree.
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();

            $table->string('status')->index();

            // What the Administrator scheduled it for. A date, not a timestamp:
            // a restock is planned for a day, not a minute.
            $table->date('scheduled_for')->index();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->string('notes')->nullable();

            $table->dateTime('prepared_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            // The queue is read per outlet, in the order it is worked through.
            $table->index(['outlet_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restocks');
    }
};
