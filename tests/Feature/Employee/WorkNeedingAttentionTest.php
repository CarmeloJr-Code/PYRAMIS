<?php

namespace Tests\Feature\Employee;

use App\Actions\CountWorkNeedingAttention;
use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Outlet;
use App\Models\Restock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The figures on the sidebar: the work waiting in each section for whoever
 * can open it.
 */
class WorkNeedingAttentionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{orders?: int, inventory?: int, restocks?: int, messages?: int}
     */
    private function countFor(User $employee): array
    {
        return app(CountWorkNeedingAttention::class)->handle($employee);
    }

    public function test_orders_count_only_payments_waiting_to_be_checked(): void
    {
        $outlet = Outlet::factory()->create();

        // Proof sent, not checked: counted.
        OrderPayment::factory()->for(Order::factory()->for($outlet)->status(OrderStatus::Pending))->create();
        // No proof yet: waiting on the customer, not the counter.
        Order::factory()->for($outlet)->status(OrderStatus::Pending)->create();
        // Checked and confirmed.
        OrderPayment::factory()->verified()->for(Order::factory()->for($outlet)->status(OrderStatus::Confirmed))->create();
        // Proof on an order since called off.
        OrderPayment::factory()->for(Order::factory()->for($outlet)->status(OrderStatus::Cancelled))->create();

        $this->assertSame(['orders' => 1], $this->countFor(User::factory()->cashier()->create()));
    }

    public function test_inventory_counts_ingredients_at_or_below_their_reorder_level(): void
    {
        $low = Ingredient::factory()->reorderAt('5')->create();
        InventoryMovement::factory()->for($low)->quantity('5')->create();

        $fine = Ingredient::factory()->reorderAt('5')->create();
        InventoryMovement::factory()->for($fine)->quantity('6')->create();

        $retired = Ingredient::factory()->reorderAt('5')->inactive()->create();
        InventoryMovement::factory()->for($retired)->quantity('1')->create();

        $this->assertSame(['inventory' => 1], $this->countFor(User::factory()->baker()->create()));
    }

    public function test_restocks_count_only_those_not_yet_started(): void
    {
        $kiosk = Outlet::factory()->create();

        Restock::factory()->for($kiosk)->create();
        Restock::factory()->for($kiosk)->preparing()->create();
        Restock::factory()->for($kiosk)->delivered()->create();

        $this->assertSame(['restocks' => 1], $this->countFor(User::factory()->baker()->create()));
    }

    public function test_messages_count_what_others_sent_since_the_employee_last_read(): void
    {
        $cashier = User::factory()->cashier()->create();
        $baker = User::factory()->baker()->create();

        $read = Conversation::factory()->withMembers([$cashier, $baker])->create();
        Message::factory()->for($read)->for($baker, 'sender')->create();
        $read->markReadFor($cashier);
        Message::factory()->for($read)->for($baker, 'sender')->count(2)->create();
        Message::factory()->for($read)->for($cashier, 'sender')->create();

        $unopened = Conversation::factory()->withMembers([$cashier, $baker])->create();
        Message::factory()->for($unopened)->for($baker, 'sender')->create();

        // A thread the cashier is not in is none of their business.
        $elsewhere = Conversation::factory()->withMembers([$baker])->create();
        Message::factory()->for($elsewhere)->for(User::factory()->administrator(), 'sender')->create();

        $this->assertSame(['messages' => 3], $this->countFor($cashier));
    }

    public function test_each_role_is_counted_only_the_sections_it_can_open(): void
    {
        $outlet = Outlet::factory()->create();
        OrderPayment::factory()->for(Order::factory()->for($outlet)->status(OrderStatus::Pending))->create();

        $ingredient = Ingredient::factory()->reorderAt('5')->create();
        InventoryMovement::factory()->for($ingredient)->quantity('1')->create();

        Restock::factory()->for($outlet)->create();

        $this->assertSame(['orders' => 1], $this->countFor(User::factory()->cashier()->create()));
        $this->assertSame(['inventory' => 1, 'restocks' => 1], $this->countFor(User::factory()->baker()->create()));
        $this->assertSame(
            ['orders' => 1, 'inventory' => 1, 'restocks' => 1],
            $this->countFor(User::factory()->administrator()->create()),
        );
    }

    public function test_the_sidebar_shows_the_figures(): void
    {
        $cashier = User::factory()->cashier()->create();
        OrderPayment::factory()->for(Order::factory()->for(Outlet::factory())->status(OrderStatus::Pending))->create();

        Restock::factory()->for(Outlet::factory())->create();

        $this->actingAs($cashier)
            ->get(route('employee.dashboard'))
            ->assertOk()
            ->assertSeeInOrder([__('Orders'), '1'], escape: false)
            ->assertDontSeeText(__('Restocking'));
    }
}
