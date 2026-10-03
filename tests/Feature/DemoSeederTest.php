<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\RestockStatus;
use App\Enums\SaleStatus;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Message;
use App\Models\Order;
use App\Models\ProductionRun;
use App\Models\ProductStockMovement;
use App\Models\Restock;
use App\Models\Sale;
use App\Models\ShiftAssignment;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * The simulated history for a development database.
 *
 * Built by running the real actions, so what is worth checking is that the
 * result is a history the application itself could have written: every module
 * has something in it, no ledger is below zero, and nothing is dated after the
 * moment the seed ran.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A Saturday evening, so the fortnight holds payroll, rent and a full
     * order lifecycle.
     */
    private const NOW = '2026-10-03 19:00:00';

    public function test_it_fills_every_module(): void
    {
        $this->seedDemo();

        $this->assertTrue(Sale::query()->where('status', SaleStatus::Completed)->whereNull('order_id')->exists());
        $this->assertTrue(Sale::query()->whereNotNull('order_id')->exists());
        $this->assertTrue(ProductionRun::query()->exists());
        $this->assertTrue(Restock::query()->where('status', RestockStatus::Delivered)->exists());
        $this->assertTrue(Restock::query()->where('status', RestockStatus::Requested)->exists());
        $this->assertTrue(Expense::query()->exists());
        $this->assertTrue(ShiftAssignment::query()->exists());
        $this->assertTrue(Message::query()->exists());

        // Finished orders, and a queue still open for the counter.
        $this->assertTrue(Order::query()->where('status', OrderStatus::Completed)->exists());
        $this->assertTrue(Order::query()->where('status', OrderStatus::Pending)->exists());
    }

    public function test_no_ledger_goes_below_zero(): void
    {
        $this->seedDemo();

        foreach (Ingredient::query()->withStock()->get() as $ingredient) {
            $this->assertGreaterThanOrEqual(0, $ingredient->stockInThousandths(), $ingredient->name);
        }

        $shelves = ProductStockMovement::query()
            ->selectRaw('outlet_id, product_variant_id, SUM(quantity) as units')
            ->groupBy('outlet_id', 'product_variant_id')
            ->get();

        $this->assertNotEmpty($shelves);

        foreach ($shelves as $shelf) {
            $this->assertGreaterThanOrEqual(0, (int) $shelf->units);
        }
    }

    public function test_every_completed_order_was_billed_once_and_nothing_is_dated_ahead(): void
    {
        $this->seedDemo();

        foreach (Order::query()->where('status', OrderStatus::Completed)->withCount('sale')->get() as $order) {
            $this->assertSame(1, $order->sale_count, $order->reference);
        }

        $this->assertTrue(Sale::query()->max('sold_at') <= self::NOW);
        $this->assertTrue(ProductionRun::query()->max('produced_at') <= self::NOW);
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->seedDemo();

        $sales = Sale::query()->count();
        $orders = Order::query()->count();

        $this->seedDemo();

        $this->assertSame($sales, Sale::query()->count());
        $this->assertSame($orders, Order::query()->count());
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);

        try {
            $this->seedDemo();
        } finally {
            $this->assertFalse(Sale::query()->exists());
        }
    }

    private function seedDemo(int $days = 14): void
    {
        $this->travelTo(CarbonImmutable::parse(self::NOW));

        $command = new Command;
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        (new DemoSeeder(days: $days))->setContainer($this->app)->setCommand($command)->__invoke();
    }
}
