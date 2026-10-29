<?php

namespace Tests\Feature\Storefront;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Outlet;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class GcashPaymentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A 1×1 PNG, real enough for the image rules without needing GD.
     */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function order(OrderStatus $status = OrderStatus::Pending): Order
    {
        $order = Order::factory()->status($status)->for(Outlet::factory())->create();

        OrderItem::factory()->for($order)->for(ProductVariant::factory())->create([
            'quantity' => 2,
            'unit_price' => '990.00',
        ]);

        return $order->fresh();
    }

    private function screenshot(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.png', base64_decode(self::PNG));
    }

    public function test_a_pending_order_shows_the_qr_and_the_amount_to_send(): void
    {
        $order = $this->order();

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSeeText('Pay with GCash')
            ->assertSee('images/gcash-qr.svg')
            ->assertSeeText('₱1980.00')
            ->assertSeeText('Send payment details');
    }

    public function test_the_customer_can_send_the_reference_number_alone(): void
    {
        $order = $this->order();

        Livewire::test('pages::storefront.order-status', ['order' => $order])
            ->set('gcash_reference', '1234 567 890123')
            ->call('submitPayment')
            ->assertHasNoErrors()
            ->assertSeeText('Payment sent');

        $payment = $order->payment()->firstOrFail();

        $this->assertSame('1234567890123', $payment->gcash_reference);
        $this->assertNotNull($payment->submitted_at);
        $this->assertFalse($payment->hasReceipt());
        $this->assertFalse($payment->isVerified());
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_the_customer_can_send_a_screenshot_alone(): void
    {
        $order = $this->order();

        Livewire::test('pages::storefront.order-status', ['order' => $order])
            ->set('receipt', $this->screenshot())
            ->call('submitPayment')
            ->assertHasNoErrors();

        $payment = $order->payment()->firstOrFail();

        $this->assertNull($payment->gcash_reference);
        $this->assertSame('image/png', $payment->receipt_mime);
        $this->assertSame(self::PNG, $payment->receipt);
    }

    public function test_sending_again_keeps_what_was_not_replaced(): void
    {
        $order = $this->order();

        Livewire::test('pages::storefront.order-status', ['order' => $order])
            ->set('gcash_reference', '1234567890123')
            ->call('submitPayment')
            ->set('receipt', $this->screenshot())
            ->call('submitPayment')
            ->assertHasNoErrors();

        $payment = $order->payment()->firstOrFail();

        $this->assertSame('1234567890123', $payment->gcash_reference);
        $this->assertTrue($payment->hasReceipt());
        $this->assertSame(1, OrderPayment::query()->count());
    }

    public function test_something_has_to_be_sent(): void
    {
        $order = $this->order();

        Livewire::test('pages::storefront.order-status', ['order' => $order])
            ->call('submitPayment')
            ->assertHasErrors(['gcash_reference' => 'required_without', 'receipt' => 'required_without']);

        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_a_malformed_reference_is_refused(): void
    {
        $order = $this->order();

        foreach (['12345', '12345678901234', '12345678901ab'] as $reference) {
            Livewire::test('pages::storefront.order-status', ['order' => $order])
                ->set('gcash_reference', $reference)
                ->call('submitPayment')
                ->assertHasErrors(['gcash_reference' => 'regex']);
        }

        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_a_file_that_is_not_an_image_or_too_large_is_refused(): void
    {
        $order = $this->order();

        Livewire::test('pages::storefront.order-status', ['order' => $order])
            ->set('receipt', UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'))
            ->call('submitPayment')
            ->assertHasErrors(['receipt']);

        Livewire::test('pages::storefront.order-status', ['order' => $order])
            ->set('receipt', UploadedFile::fake()->create('receipt.png', 5000, 'image/png'))
            ->call('submitPayment')
            ->assertHasErrors(['receipt']);

        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_nothing_can_be_sent_once_the_payment_is_verified(): void
    {
        $order = $this->order();
        OrderPayment::factory()->for($order)->verified('1980.00')->create(['gcash_reference' => '1111111111111']);

        Livewire::test('pages::storefront.order-status', ['order' => $order])
            ->assertDontSeeText('Pay with GCash')
            ->set('gcash_reference', '2222222222222')
            ->call('submitPayment')
            ->assertHasErrors(['payment']);

        $this->assertSame('1111111111111', $order->payment()->firstOrFail()->gcash_reference);
    }

    public function test_nothing_can_be_sent_for_an_order_past_pending(): void
    {
        foreach ([OrderStatus::Cancelled, OrderStatus::Confirmed] as $status) {
            $order = $this->order($status);

            Livewire::test('pages::storefront.order-status', ['order' => $order])
                ->assertDontSeeText('Pay with GCash')
                ->set('gcash_reference', '1234567890123')
                ->call('submitPayment')
                ->assertHasErrors(['payment']);
        }

        $this->assertDatabaseCount('order_payments', 0);
    }

    public function test_proof_for_one_order_is_limited_per_hour(): void
    {
        $order = $this->order();
        $component = Livewire::test('pages::storefront.order-status', ['order' => $order]);

        foreach (range(1, 5) as $attempt) {
            $component->set('gcash_reference', '1234567890123')->call('submitPayment')->assertHasNoErrors();
        }

        $component->set('gcash_reference', '9999999999999')
            ->call('submitPayment')
            ->assertHasErrors(['payment']);

        $this->assertSame('1234567890123', $order->payment()->firstOrFail()->gcash_reference);
    }

    public function test_the_order_page_says_payment_comes_next(): void
    {
        $variant = ProductVariant::factory()->create();

        $this->withSession(['cart' => [$variant->id => 1]])
            ->get(route('order'))
            ->assertOk()
            ->assertSeeText('You will pay by GCash on the next screen.');
    }
}
