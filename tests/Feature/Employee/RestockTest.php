<?php

namespace Tests\Feature\Employee;

use App\Actions\DeliverRestock;
use App\Enums\ProductStockMovementType;
use App\Enums\RestockStatus;
use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use App\Models\Restock;
use App\Models\RestockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class RestockTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $mainBranch;

    private Outlet $kiosk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainBranch = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
        $this->kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);
    }

    /**
     * A size with the given number of units on the main branch's shelf.
     */
    private function stocked(string $product, string $size, int $units): ProductVariant
    {
        $variant = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => $product]))
            ->create(['name' => $size]);

        ProductStockMovement::factory()
            ->for($variant, 'productVariant')
            ->for($this->mainBranch)
            ->quantity($units)
            ->create();

        return $variant;
    }

    /**
     * A restock being prepared, with one line.
     */
    private function preparing(ProductVariant $variant, int $requested, ?int $prepared = null): Restock
    {
        $restock = Restock::factory()->for($this->kiosk)->preparing()->create();

        $item = RestockItem::factory()->for($restock)->requesting($requested);

        if ($prepared !== null) {
            $item = $item->prepared($prepared);
        }

        $item->create(['product_variant_id' => $variant->id]);

        return $restock->fresh(['items.productVariant']);
    }

    public function test_only_administrators_and_bakers_may_open_restocking(): void
    {
        foreach (UserRole::cases() as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('employee.restocks.index'));

            $role === UserRole::Cashier
                ? $response->assertForbidden()
                : $response->assertOk();
        }
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.restocks.index'))->assertRedirect(route('login'));
    }

    public function test_only_an_administrator_may_schedule_one(): void
    {
        // The Phase 7 spec puts schedules with the Administrator and
        // preparation with the Baker.
        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.restocks.create'))
            ->assertForbidden();

        $this->actingAs(User::factory()->administrator()->create())
            ->get(route('employee.restocks.create'))
            ->assertOk();
    }

    public function test_an_administrator_schedules_a_restock(): void
    {
        $variant = $this->stocked('Ube Cake', 'Round (7x3)', 20);
        $administrator = User::factory()->administrator()->create();

        Livewire::actingAs($administrator)
            ->test('pages::employee.restocks.create')
            ->set('outlet_id', $this->kiosk->id)
            ->set('scheduled_for', now()->addDay()->toDateString())
            ->set('notes', 'Weekend stock')
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => '8']])
            ->call('save')
            ->assertHasNoErrors();

        $restock = Restock::first();

        $this->assertNotNull($restock);
        $this->assertSame($this->kiosk->id, $restock->outlet_id);
        $this->assertSame(RestockStatus::Requested, $restock->status);
        $this->assertSame($administrator->id, $restock->requested_by);
        $this->assertStringStartsWith('RS-', $restock->reference);
        $this->assertSame(8, $restock->items()->first()->quantity_requested);
        $this->assertNull($restock->items()->first()->quantity_prepared);

        // Scheduling moves nothing: the goods are still on the main branch.
        $this->assertSame(20, $variant->stockAt($this->mainBranch));
        $this->assertSame(0, $variant->stockAt($this->kiosk));
    }

    public function test_the_main_branch_cannot_be_the_destination(): void
    {
        $variant = $this->stocked('Ube Cake', 'Round (7x3)', 20);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.restocks.create')
            ->set('outlet_id', $this->mainBranch->id)
            ->set('scheduled_for', now()->toDateString())
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => '1']])
            ->call('save')
            ->assertHasErrors('outlet_id');

        $this->assertSame(0, Restock::count());
    }

    public function test_it_refuses_a_closed_outlet_a_duplicate_size_or_no_quantity(): void
    {
        $closed = Outlet::factory()->inactive()->create();
        $variant = $this->stocked('Ube Cake', 'Round (7x3)', 20);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.restocks.create')
            ->set('outlet_id', $closed->id)
            ->set('lines', [
                ['product_variant_id' => $variant->id, 'quantity' => '1'],
                ['product_variant_id' => $variant->id, 'quantity' => ''],
            ])
            ->call('save')
            ->assertHasErrors(['outlet_id', 'lines.0.product_variant_id', 'lines.1.quantity']);

        $this->assertSame(0, Restock::count());
    }

    public function test_a_baker_records_what_was_actually_set_aside(): void
    {
        $variant = $this->stocked('Ube Cake', 'Large (10x14)', 12);
        $restock = $this->preparing($variant, 10);
        $baker = User::factory()->baker()->create();

        $item = $restock->items->first();

        Livewire::actingAs($baker)
            ->test('pages::employee.restocks.show', ['restock' => $restock])
            // Defaults to what was asked for, and a short bake is corrected.
            ->assertSet("prepared.{$item->id}", '10')
            ->set("prepared.{$item->id}", '7')
            ->call('savePrepared')
            ->assertHasNoErrors();

        $this->assertSame(7, $item->fresh()->quantity_prepared);
        $this->assertSame($baker->id, $restock->fresh()->prepared_by);

        // Still nothing has moved — that happens on delivery.
        $this->assertSame(12, $variant->stockAt($this->mainBranch));
    }

    public function test_more_cannot_be_prepared_than_the_main_branch_holds(): void
    {
        $variant = $this->stocked('Ube Cake', 'Large (10x14)', 5);
        $restock = $this->preparing($variant, 10);
        $item = $restock->items->first();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.restocks.show', ['restock' => $restock])
            ->set("prepared.{$item->id}", '6')
            ->call('savePrepared')
            ->assertHasErrors("prepared.{$item->id}");

        $this->assertNull($item->fresh()->quantity_prepared);
    }

    public function test_delivering_moves_the_stock_to_the_outlet(): void
    {
        $variant = $this->stocked('Ube Cake', 'Round (7x3)', 20);
        $restock = $this->preparing($variant, 8, 8);
        $baker = User::factory()->baker()->create();

        Livewire::actingAs($baker)
            ->test('pages::employee.restocks.show', ['restock' => $restock])
            ->call('deliver');

        $restock = $restock->fresh();

        $this->assertSame(RestockStatus::Delivered, $restock->status);
        $this->assertSame($baker->id, $restock->delivered_by);
        $this->assertNotNull($restock->delivered_at);

        // The exit criterion: the goods left the main branch and are now the
        // outlet's to sell.
        $this->assertSame(12, $variant->stockAt($this->mainBranch));
        $this->assertSame(8, $variant->stockAt($this->kiosk));

        // Both halves are on the record, against the restock that moved them.
        $this->assertSame(2, $restock->movements()->count());

        $this->assertDatabaseHas('product_stock_movements', [
            'restock_id' => $restock->id,
            'outlet_id' => $this->mainBranch->id,
            'type' => ProductStockMovementType::Transfer->value,
            'quantity' => -8,
        ]);

        $this->assertDatabaseHas('product_stock_movements', [
            'restock_id' => $restock->id,
            'outlet_id' => $this->kiosk->id,
            'quantity' => 8,
        ]);
    }

    public function test_only_what_was_prepared_travels(): void
    {
        $variant = $this->stocked('Ube Cake', 'Round (7x3)', 20);
        $restock = $this->preparing($variant, 10, 6);

        app(DeliverRestock::class)->handle($restock, User::factory()->baker()->create());

        $this->assertSame(14, $variant->stockAt($this->mainBranch));
        $this->assertSame(6, $variant->stockAt($this->kiosk));
    }

    public function test_a_delivery_the_main_branch_cannot_cover_moves_nothing(): void
    {
        $plenty = $this->stocked('Ube Cake', 'Round (7x3)', 20);
        $short = $this->stocked('Chocolate Cake', 'Large (10x14)', 1);

        $restock = Restock::factory()->for($this->kiosk)->preparing()->create();
        RestockItem::factory()->for($restock)->requesting(5)->prepared(5)->create(['product_variant_id' => $plenty->id]);
        RestockItem::factory()->for($restock)->requesting(4)->prepared(4)->create(['product_variant_id' => $short->id]);

        try {
            app(DeliverRestock::class)->handle($restock->fresh(), User::factory()->baker()->create());
            $this->fail('The delivery should have been refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Chocolate Cake', $exception->getMessage());
        }

        // The first line would have gone through on its own; the delivery is one
        // transaction, so neither shelf moved and the restock is still open.
        $this->assertSame(20, $plenty->stockAt($this->mainBranch));
        $this->assertSame(0, $plenty->stockAt($this->kiosk));
        $this->assertSame(RestockStatus::Preparing, $restock->fresh()->status);
        $this->assertSame(0, ProductStockMovement::where('type', ProductStockMovementType::Transfer)->count());
    }

    public function test_a_restock_cannot_be_delivered_before_it_is_prepared(): void
    {
        $variant = $this->stocked('Ube Cake', 'Round (7x3)', 20);

        $restock = Restock::factory()->for($this->kiosk)->create();
        RestockItem::factory()->for($restock)->requesting(5)->create(['product_variant_id' => $variant->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be delivered');

        app(DeliverRestock::class)->handle($restock->fresh(), User::factory()->baker()->create());
    }

    public function test_a_delivered_restock_cannot_be_moved_again(): void
    {
        $variant = $this->stocked('Ube Cake', 'Round (7x3)', 20);
        $restock = $this->preparing($variant, 4, 4);
        $baker = User::factory()->baker()->create();

        app(DeliverRestock::class)->handle($restock, $baker);

        $delivered = $restock->fresh();

        Livewire::actingAs($baker)
            ->test('pages::employee.restocks.show', ['restock' => $delivered])
            ->call('cancel');

        $this->assertSame(RestockStatus::Delivered, $delivered->fresh()->status);

        // And the stock did not move a second time.
        $this->assertSame(16, $variant->stockAt($this->mainBranch));
        $this->assertSame(4, $variant->stockAt($this->kiosk));
    }

    public function test_cancelling_an_open_restock_moves_nothing(): void
    {
        $variant = $this->stocked('Ube Cake', 'Round (7x3)', 20);
        $restock = $this->preparing($variant, 5, 5);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.restocks.show', ['restock' => $restock])
            ->call('cancel');

        $this->assertSame(RestockStatus::Cancelled, $restock->fresh()->status);
        $this->assertSame(20, $variant->stockAt($this->mainBranch));
        $this->assertSame(0, $variant->stockAt($this->kiosk));
    }

    public function test_the_queue_hides_finished_restocks_unless_asked(): void
    {
        $variant = $this->stocked('Ube Cake', 'Round (7x3)', 20);

        $open = Restock::factory()->for($this->kiosk)->create();
        RestockItem::factory()->for($open)->requesting(2)->create(['product_variant_id' => $variant->id]);

        $done = Restock::factory()->for($this->kiosk)->cancelled()->create();
        RestockItem::factory()->for($done)->requesting(3)->create(['product_variant_id' => $variant->id]);

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.restocks.index')
            ->assertSet('openCount', 1)
            ->assertSee($open->reference)
            ->assertDontSee($done->reference)
            ->set('includeFinished', true)
            ->assertSee($done->reference);
    }
}
