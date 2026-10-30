<?php

namespace Tests\Feature\Employee;

use App\Actions\AssignShift;
use App\Actions\DeliverRestock;
use App\Actions\RecordInventoryMovement;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use App\Models\Restock;
use App\Models\RestockItem;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\IngredientRunningLow;
use App\Notifications\OrderPlaced;
use App\Notifications\PaymentProofSubmitted;
use App\Notifications\RestockDelivered;
use App\Notifications\RestockRequested;
use App\Notifications\ShiftAssigned;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $baker;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->administrator()->create();
        $this->baker = User::factory()->baker()->create();
        $this->cashier = User::factory()->cashier()->create();
    }

    /**
     * Who received a notification of the given type, by id.
     *
     * @param  class-string  $type
     * @return list<int>
     */
    private function recipientsOf(string $type): array
    {
        return User::query()
            ->whereHas('notifications', fn ($query) => $query->where('type', $type))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public function test_a_storefront_order_notifies_whoever_handles_orders(): void
    {
        // Someone who has left still has the role, and still hears nothing.
        User::factory()->cashier()->create(['is_active' => false]);

        $variant = ProductVariant::factory()
            ->for(Product::factory()->create())
            ->create(['is_available' => true]);

        Session::put('cart', [$variant->id => 1]);

        Livewire::test('pages::storefront.order')
            ->set('customer_name', 'Ana Reyes')
            ->set('customer_phone', '09171234567')
            ->set('outlet_id', Outlet::factory()->create()->id)
            ->set('pickup_date', now()->addDay()->toDateString())
            ->set('pickup_time', '10:30')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(
            [$this->administrator->id, $this->cashier->id],
            $this->recipientsOf(OrderPlaced::class),
        );

        $order = Order::firstOrFail();
        $data = $this->cashier->notifications()->firstOrFail()->data;

        $this->assertSame("New pre-order {$order->reference}", $data['title']);
        $this->assertStringContainsString('Ana Reyes', $data['body']);
        $this->assertSame(route('employee.orders.show', $order), $data['url']);
    }

    public function test_sending_proof_of_payment_notifies_whoever_handles_orders(): void
    {
        $order = Order::factory()->status(OrderStatus::Pending)->for(Outlet::factory())->create();
        OrderItem::factory()->for($order)->for(ProductVariant::factory())->create();

        Livewire::test('pages::storefront.order-status', ['order' => $order->fresh()])
            ->set('gcash_reference', '1234567890123')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $this->assertSame(
            [$this->administrator->id, $this->cashier->id],
            $this->recipientsOf(PaymentProofSubmitted::class),
        );
    }

    public function test_refused_proof_of_payment_notifies_nobody(): void
    {
        $order = Order::factory()->status(OrderStatus::Cancelled)->for(Outlet::factory())->create();

        Livewire::test('pages::storefront.order-status', ['order' => $order])
            ->set('gcash_reference', '1234567890123')
            ->call('submitPayment')
            ->assertHasErrors('payment');

        $this->assertSame([], $this->recipientsOf(PaymentProofSubmitted::class));
    }

    public function test_scheduling_a_restock_notifies_the_kitchen_but_not_the_scheduler(): void
    {
        $kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);
        $variant = ProductVariant::factory()->for(Product::factory()->create())->create();

        Livewire::actingAs($this->administrator)
            ->test('pages::employee.restocks.create')
            ->set('outlet_id', $kiosk->id)
            ->set('scheduled_for', now()->addDay()->toDateString())
            ->set('lines', [['product_variant_id' => $variant->id, 'quantity' => '8']])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$this->baker->id], $this->recipientsOf(RestockRequested::class));

        $data = $this->baker->notifications()->firstOrFail()->data;

        $this->assertStringContainsString('Mall Kiosk', $data['body']);
        $this->assertSame(route('employee.restocks.show', Restock::firstOrFail()), $data['url']);
    }

    public function test_a_delivered_restock_notifies_the_administrators_but_not_who_delivered_it(): void
    {
        $otherAdministrator = User::factory()->administrator()->create();
        $mainBranch = Outlet::factory()->mainBranch()->create();
        $variant = ProductVariant::factory()->for(Product::factory()->create())->create();

        ProductStockMovement::factory()->for($variant, 'productVariant')->for($mainBranch)->quantity(10)->create();

        $restock = Restock::factory()->for(Outlet::factory())->preparing()->create(['requested_by' => $otherAdministrator->id]);
        RestockItem::factory()->for($restock)->requesting(5)->prepared(5)->create(['product_variant_id' => $variant->id]);

        app(DeliverRestock::class)->handle($restock, $this->administrator);

        $this->assertSame([$otherAdministrator->id], $this->recipientsOf(RestockDelivered::class));
    }

    public function test_assigning_a_shift_tells_the_employee_where_to_find_it(): void
    {
        $shift = Shift::factory()->for(Outlet::factory())->create();

        app(AssignShift::class)->handle($shift, $this->baker, $this->administrator);

        $this->assertSame([$this->baker->id], $this->recipientsOf(ShiftAssigned::class));
        $this->assertSame(route('employee.schedule'), $this->baker->notifications()->firstOrFail()->data['url']);

        // Assigning the same shift again changes nothing, so says nothing.
        app(AssignShift::class)->handle($shift, $this->baker, $this->administrator);

        $this->assertSame(1, $this->baker->notifications()->count());
    }

    public function test_an_administrator_putting_themselves_on_a_shift_is_not_notified(): void
    {
        $shift = Shift::factory()->for(Outlet::factory())->create();

        app(AssignShift::class)->handle($shift, $this->administrator, $this->administrator);

        $this->assertSame([], $this->recipientsOf(ShiftAssigned::class));
    }

    public function test_an_ingredient_falling_to_its_reorder_level_is_announced_once(): void
    {
        $otherBaker = User::factory()->baker()->create();
        $ingredient = Ingredient::factory()->reorderAt('5')->create(['name' => 'Ube']);
        $record = app(RecordInventoryMovement::class);

        $record->handle($ingredient, $this->baker, InventoryMovementType::Received, 8000);
        $record->handle($ingredient, $this->baker, InventoryMovementType::Usage, -2000);

        // Still above the line.
        $this->assertSame([], $this->recipientsOf(IngredientRunningLow::class));

        // Exactly on it counts as low.
        $record->handle($ingredient, $this->baker, InventoryMovementType::Usage, -1000);

        $this->assertSame(
            [$this->administrator->id, $otherBaker->id],
            $this->recipientsOf(IngredientRunningLow::class),
        );
        $this->assertSame('Ube is running low', $otherBaker->notifications()->firstOrFail()->data['title']);

        // Already low: drawing more is not news.
        $record->handle($ingredient, $this->baker, InventoryMovementType::Usage, -1000);

        $this->assertSame(1, $otherBaker->notifications()->count());
    }

    public function test_an_ingredient_without_a_reorder_level_is_never_announced(): void
    {
        $ingredient = Ingredient::factory()->reorderAt('0')->create();
        $record = app(RecordInventoryMovement::class);

        $record->handle($ingredient, $this->baker, InventoryMovementType::Received, 3000);
        $record->handle($ingredient, $this->baker, InventoryMovementType::Usage, -3000);

        $this->assertSame([], $this->recipientsOf(IngredientRunningLow::class));
    }

    public function test_the_bell_counts_and_lists_the_employees_own_notifications(): void
    {
        $order = Order::factory()->for(Outlet::factory())->create();

        $this->cashier->notify(new PaymentProofSubmitted($order));
        $this->cashier->notify(new OrderPlaced($order));
        $this->baker->notify(new OrderPlaced($order));
        $this->cashier->notifications()->latest()->first()->markAsRead();

        Livewire::actingAs($this->cashier)
            ->test('notification-bell')
            ->assertSet('unreadCount', 1)
            ->assertSeeText("Payment to check on {$order->reference}")
            ->assertSeeText("New pre-order {$order->reference}");
    }

    public function test_opening_a_notification_marks_it_read_and_goes_to_it(): void
    {
        $order = Order::factory()->for(Outlet::factory())->create();
        $this->cashier->notify(new PaymentProofSubmitted($order));
        $notification = $this->cashier->notifications()->firstOrFail();

        Livewire::actingAs($this->cashier)
            ->test('notification-bell')
            ->call('open', $notification->id)
            ->assertRedirect(route('employee.orders.show', $order));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_one_employee_cannot_open_anothers_notification(): void
    {
        $this->baker->notify(new OrderPlaced(Order::factory()->for(Outlet::factory())->create()));
        $notification = $this->baker->notifications()->firstOrFail();

        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::actingAs($this->cashier)
                ->test('notification-bell')
                ->call('open', $notification->id);
        } finally {
            $this->assertNull($notification->fresh()->read_at);
        }
    }

    public function test_marking_all_read_clears_only_the_employees_own(): void
    {
        $order = Order::factory()->for(Outlet::factory())->create();
        $this->cashier->notify(new OrderPlaced($order));
        $this->cashier->notify(new PaymentProofSubmitted($order));
        $this->administrator->notify(new OrderPlaced($order));

        Livewire::actingAs($this->cashier)
            ->test('notification-bell')
            ->call('markAllAsRead')
            ->assertSet('unreadCount', 0);

        $this->assertSame(0, $this->cashier->unreadNotifications()->count());
        $this->assertSame(1, $this->administrator->unreadNotifications()->count());
    }

    public function test_every_workspace_screen_carries_the_bell(): void
    {
        $this->actingAs($this->baker)
            ->get(route('employee.dashboard'))
            ->assertOk()
            ->assertSee('data-test="notification-bell"', escape: false);
    }
}
