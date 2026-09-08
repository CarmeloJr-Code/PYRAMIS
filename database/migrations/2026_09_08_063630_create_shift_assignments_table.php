<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_assignments', function (Blueprint $table): void {
            $table->id();

            // Cascading: an assignment describes a shift and means nothing
            // without it, and dropping a shift should not leave orphans behind.
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();

            // Restricted: an employee with work on the roster cannot be deleted
            // out from under it.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // One employee, one place on a shift. Assigning twice is a
            // double-click, not two people.
            $table->unique(['shift_id', 'user_id']);

            // An employee's own schedule is the other way the table is read.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
    }
};
