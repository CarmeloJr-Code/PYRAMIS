<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();

            // Restricted throughout: an expense is a record of money that left
            // the business, and must always be able to name what it was for,
            // where, and who filed it.
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();

            // The operational location it belongs to (FR-08). The main branch is
            // an outlet with a flag, so one column covers both.
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            // The day the money was spent, which is not always the day it was
            // typed in — a receipt from Saturday gets filed on Monday.
            $table->date('spent_on');

            $table->string('description');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // Expenses are read by period, and often narrowed to one place or
            // one kind of spending.
            $table->index('spent_on');
            $table->index(['outlet_id', 'spent_on']);
            $table->index(['expense_category_id', 'spent_on']);
        });

        // Same Postgres-only pattern as the other tables: SQLite cannot ALTER
        // TABLE ADD CONSTRAINT, so CI is the gate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table expenses add constraint expenses_amount_positive check (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
