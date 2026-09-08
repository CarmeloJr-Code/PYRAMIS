<?php

use App\Enums\ProductStockMovementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_movements', function (Blueprint $table): void {
            $table->id();

            // Restricted throughout, like the ingredient ledger: a record of
            // something that happened must always be able to name its subject.
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();

            // Where the stock is. Finished goods only ever sit somewhere — the
            // main branch until a restock moves them to an outlet — so this is
            // required rather than nullable-means-main-branch.
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();

            $table->string('type')->index();

            // Signed whole units: positive adds, negative removes. Cakes are
            // counted, not weighed, so there is no scaled decimal here.
            $table->integer('quantity');

            // The bake that produced it, when it came from one.
            $table->foreignId('production_run_id')->nullable()->constrained()->restrictOnDelete();

            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('note')->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            // Stock is always read for one place, and often for one size in it.
            $table->index(['outlet_id', 'product_variant_id']);
            $table->index(['outlet_id', 'occurred_at']);
        });

        // Same Postgres-only pattern as the inventory tables: SQLite cannot
        // ALTER TABLE ADD CONSTRAINT, so CI is the gate.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table product_stock_movements add constraint product_stock_movements_quantity_non_zero check (quantity <> 0)');
        }

        $this->backfillProductionRuns();
    }

    /**
     * Give every run already logged the finished stock it produced.
     *
     * Runs recorded their output before this ledger existed, so without this
     * the main branch would open with nothing on the shelf and a history that
     * says otherwise.
     */
    protected function backfillProductionRuns(): void
    {
        $mainBranchId = DB::table('outlets')->where('is_main_branch', true)->value('id');

        if ($mainBranchId === null) {
            return;
        }

        DB::table('production_runs')->orderBy('id')->chunk(200, function ($runs) use ($mainBranchId): void {
            $rows = [];

            foreach ($runs as $run) {
                $rows[] = [
                    'product_variant_id' => $run->product_variant_id,
                    'outlet_id' => $mainBranchId,
                    'type' => ProductStockMovementType::Produced->value,
                    'quantity' => $run->quantity,
                    'production_run_id' => $run->id,
                    'recorded_by' => $run->recorded_by,
                    'note' => null,
                    'occurred_at' => $run->produced_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('product_stock_movements')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_movements');
    }
};
