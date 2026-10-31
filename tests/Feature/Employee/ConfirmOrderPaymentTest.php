<?php

namespace Tests\Feature\Employee;

use App\Actions\ConfirmOrderPayment;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Outlet;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ConfirmOrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A pending order for ₱1,980.00.
     */
    protected function order(OrderStatus $status = OrderStatus::Pending): Order
    {
        $order = Order::factory()->status($status)->for(Outlet::factory())->create();

        OrderItem::factory()->for($order)->for(ProductVariant::factory())->create([
            'quantity' => 2,
            'unit_price' => '990.00',
        ]);

        return $order->fresh();
    }

    public function test_the_exact_amount_confirms_the_order(): void
    {
        $order = $this->order();
        OrderPayment::factory()->for($order)->create(['gcash_reference' => '1234567890123']);
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.orders.show', ['order' => $order])
            ->assertSet('gcash_reference', '1234567890123')
            ->set('amount', '1980')
            ->call('confirmPayment')
            ->assertHasNoErrors()
            ->assertSeeText('Paid ₱1,980.00 by GCash');

        $payment = $order->payment()->firstOrFail();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertSame('1980.00', $payment->amount);
        $this->assertSame($cashier->id, $payment->verified_by);
        $this->assertNotNull($payment->verified_at);
    }

    public function test_the_cashier_can_confirm_a_payment_the_customer_never_reported(): void
    {
        $order = $this->order();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->assertSeeText('The customer has not sent their payment details yet.')
            ->set('amount', '1980.00')
            ->set('gcash_reference', '1234567890123')
            ->call('confirmPayment')
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
        $this->assertNull($order->payment()->firstOrFail()->submitted_at);
    }

    public function test_a_wrong_amount_leaves_the_order_pending(): void
    {
        $order = $this->order();
        OrderPayment::factory()->for($order)->create();

        foreach (['1979.99' => 'short', '2000.00' => 'over'] as $amount => $difference) {
            Livewire::actingAs(User::factory()->cashier()->create())
                ->test('pages::employee.orders.show', ['order' => $order])
                ->set('amount', $amount)
                ->call('confirmPayment')
                ->assertHasErrors(['amount'])
                ->assertSeeText($difference.' by');
        }

        $payment = $order->payment()->firstOrFail();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertNull($payment->amount);
        $this->assertNull($payment->verified_at);
    }

    public function test_the_amount_and_reference_are_validated(): void
    {
        $order = $this->order();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->set('amount', '')
            ->set('gcash_reference', '123')
            ->call('confirmPayment')
            ->assertHasErrors(['amount' => 'required', 'gcash_reference' => 'regex'])
            ->set('amount', '1980.001')
            ->call('confirmPayment')
            ->assertHasErrors(['amount' => 'decimal']);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_an_order_past_pending_cannot_be_paid_again(): void
    {
        $order = $this->order(OrderStatus::Cancelled);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->assertDontSeeText('Confirm payment')
            ->set('amount', '1980.00')
            ->set('gcash_reference', '1234567890123')
            ->call('confirmPayment');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_confirming_twice_confirms_once(): void
    {
        $order = $this->order();
        $action = app(ConfirmOrderPayment::class);

        $action->handle($order, User::factory()->cashier()->create(), '1980.00', '1234567890123');

        $this->expectException(RuntimeException::class);

        $action->handle($order, User::factory()->cashier()->create(), '1980.00', '1234567890123');
    }

    public function test_a_baker_cannot_confirm_a_payment(): void
    {
        $order = $this->order();

        Livewire::actingAs(User::factory()->baker()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->assertForbidden();

        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_a_pending_order_cannot_be_confirmed_without_a_verified_payment(): void
    {
        $order = $this->order();
        OrderPayment::factory()->for($order)->create();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->assertDontSeeText('Mark as Confirmed')
            ->call('advance');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        $this->expectException(RuntimeException::class);

        $order->transitionTo(OrderStatus::Confirmed);
    }

    public function test_the_queue_flags_payments_waiting_to_be_checked(): void
    {
        $waiting = $this->order();
        OrderPayment::factory()->for($waiting)->create();
        $unpaid = $this->order();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.index')
            ->assertSeeTextInOrder([$waiting->reference, 'Payment to check', $unpaid->reference]);
    }

    public function test_the_receipt_is_served_to_staff_who_manage_orders(): void
    {
        $order = $this->order();
        $payment = OrderPayment::factory()->for($order)->withReceipt()->create();

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.orders.receipt', $order))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertContent(base64_decode($payment->receipt));
    }

    public function test_the_receipt_is_refused_to_a_baker_and_a_guest(): void
    {
        $order = $this->order();
        OrderPayment::factory()->for($order)->withReceipt()->create();

        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.orders.receipt', $order))
            ->assertForbidden();

        auth()->logout();

        $this->get(route('employee.orders.receipt', $order))->assertRedirect(route('login'));
    }

    public function test_an_order_with_no_screenshot_has_no_receipt(): void
    {
        $order = $this->order();
        OrderPayment::factory()->for($order)->create();

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.orders.receipt', $this->order()))
            ->assertNotFound();

        $this->get(route('employee.orders.receipt', $order))->assertNotFound();
    }

    public function test_the_fields_restrict_what_can_be_typed_into_them(): void
    {
        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $this->order()])
            ->assertSeeHtml('keep($el, /[^\d\s]/g)')
            ->assertSeeHtml('max="99999999.99"');
    }
}
