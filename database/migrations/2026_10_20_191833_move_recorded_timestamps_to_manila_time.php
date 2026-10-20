<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every column the system stamped with the clock while the app ran in UTC.
     *
     * The columns are timestamp without time zone, so they hold whatever
     * wall-clock time PHP handed them: these were stamped in UTC, and now that
     * the app reads them as Malaybalay time they have to move eight hours on
     * to keep meaning the same moment.
     *
     * Deliberately absent: orders.pickup_at and shifts.starts_at/ends_at were
     * typed in by someone in Malaybalay, so they already hold local time, and
     * the date-only columns hold a day rather than a moment.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'users' => ['email_verified_at', 'two_factor_confirmed_at', 'created_at', 'updated_at'],
        'password_reset_tokens' => ['created_at'],
        'failed_jobs' => ['failed_at'],
        'passkeys' => ['last_used_at', 'created_at', 'updated_at'],
        'categories' => ['created_at', 'updated_at'],
        'products' => ['created_at', 'updated_at'],
        'product_variants' => ['created_at', 'updated_at'],
        'outlets' => ['created_at', 'updated_at'],
        'orders' => ['created_at', 'updated_at'],
        'order_items' => ['created_at', 'updated_at'],
        'sales' => ['sold_at', 'voided_at', 'created_at', 'updated_at'],
        'sale_items' => ['created_at', 'updated_at'],
        'ingredients' => ['created_at', 'updated_at'],
        'inventory_movements' => ['occurred_at', 'created_at', 'updated_at'],
        'recipes' => ['created_at', 'updated_at'],
        'recipe_items' => ['created_at', 'updated_at'],
        'production_runs' => ['produced_at', 'created_at', 'updated_at'],
        'product_stock_movements' => ['occurred_at', 'created_at', 'updated_at'],
        'restocks' => ['prepared_at', 'delivered_at', 'cancelled_at', 'created_at', 'updated_at'],
        'restock_items' => ['created_at', 'updated_at'],
        'shifts' => ['created_at', 'updated_at'],
        'shift_assignments' => ['created_at', 'updated_at'],
        'expense_categories' => ['created_at', 'updated_at'],
        'expenses' => ['created_at', 'updated_at'],
        'conversations' => ['created_at', 'updated_at'],
        'messages' => ['created_at', 'updated_at'],
        'conversation_participants' => ['created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->shift(8);
    }

    public function down(): void
    {
        $this->shift(-8);
    }

    /**
     * Move every listed column by a number of hours, nulls left as they are.
     */
    private function shift(int $hours): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            DB::table($table)->update(collect($columns)->mapWithKeys(
                fn (string $column): array => [$column => DB::raw($this->moved($column, $hours))],
            )->all());
        }
    }

    /**
     * The column moved by some hours, in the dialect of the connection: SQLite
     * for the test suite, Postgres everywhere else.
     */
    private function moved(string $column, int $hours): string
    {
        $grammar = DB::connection()->getQueryGrammar();
        $wrapped = $grammar->wrap($column);
        $offset = sprintf('%+d hours', $hours);

        return match (DB::connection()->getDriverName()) {
            'sqlite' => "datetime({$wrapped}, '{$offset}')",
            'pgsql' => "{$wrapped} + interval '{$offset}'",
            default => throw new RuntimeException('Timestamps can only be moved on SQLite or Postgres.'),
        };
    }
};
