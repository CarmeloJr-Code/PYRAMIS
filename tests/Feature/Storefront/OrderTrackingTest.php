<?php

namespace Tests\Feature\Storefront;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An order with one line, for a named product and outlet.
     */
    protected function order(string $productName = 'Ube Cake', ?OrderStatus $status = null): Order
    {
        $order = Order::factory()
            ->status($status ?? OrderStatus::Pending)
            ->for(Outlet::factory()->create(['name' => 'Main Branch']))
            ->create();

        $variant = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => $productName]))
            ->create(['name' => 'Large', 'price' => '990.00']);

        OrderItem::factory()->for($order)->for($variant)->create([
            'quantity' => 2,
            'unit_price' => '990.00',
        ]);

        return $order->fresh();
    }

    public function test_a_guest_can_track_an_order_by_reference(): void
    {
        $order = $this->order();

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSeeText($order->reference)
            ->assertSeeText('Ube Cake')
            ->assertSeeText('Main Branch')
            ->assertSeeText('Pending')
            ->assertSeeText('1,980.00');
    }

    public function test_an_unknown_reference_is_not_found(): void
    {
        $this->get(route('orders.show', ['order' => 'PY-2222222222']))->assertNotFound();
    }

    public function test_the_status_shown_is_the_stored_one(): void
    {
        $order = $this->order(status: OrderStatus::Ready);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSeeText('Ready for pickup');
    }

    public function test_it_never_exposes_another_order_or_the_workspace(): void
    {
        // BR-012 — the tracking page is a customer surface and must not leak
        // anything employee-side, nor any other customer's order.
        $mine = $this->order('Ube Cake');
        $theirs = $this->order('Chocolate Cake');

        $this->get(route('orders.show', $mine))
            ->assertOk()
            ->assertDontSeeText($theirs->reference)
            ->assertDontSeeText($theirs->customer_name)
            ->assertDontSee(route('employee.dashboard'))
            ->assertDontSee(route('employee.outlets.index'));
    }

    public function test_the_storefront_links_to_the_lookup(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('orders.track'));

        $this->get(route('orders.track'))
            ->assertOk()
            ->assertSeeText('Track your order');
    }

    public function test_a_reference_leads_to_its_order(): void
    {
        $order = $this->order();

        Livewire::test('pages::storefront.track')
            ->set('reference', $order->reference)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('orders.show', $order));
    }

    public function test_a_reference_is_forgiven_how_it_was_typed(): void
    {
        $order = $this->order();
        $suffix = substr($order->reference, 3);

        foreach ([
            Str::lower($order->reference),
            ' '.$order->reference.' ',
            'PY'.$suffix,
            $suffix,
            implode(' ', str_split($suffix, 5)),
        ] as $typed) {
            Livewire::test('pages::storefront.track')
                ->set('reference', $typed)
                ->call('submit')
                ->assertHasNoErrors()
                ->assertRedirect(route('orders.show', $order));
        }
    }

    public function test_an_unknown_reference_is_explained_not_followed(): void
    {
        Livewire::test('pages::storefront.track')
            ->set('reference', 'PY-2222222222')
            ->call('submit')
            ->assertHasErrors('reference')
            ->assertNoRedirect();
    }

    public function test_a_reference_is_required(): void
    {
        Livewire::test('pages::storefront.track')
            ->set('reference', '')
            ->call('submit')
            ->assertHasErrors(['reference' => 'required'])
            ->assertNoRedirect();
    }

    public function test_too_many_misses_stop_further_lookups(): void
    {
        $order = $this->order();

        for ($i = 0; $i < 10; $i++) {
            $this->lookUp('PY-2222222222')->assertHasErrors('reference');
        }

        // Even the right reference is refused now, or the limit would still
        // confirm a lucky guess.
        $this->lookUp($order->reference)
            ->assertHasErrors('reference')
            ->assertSee('Too many references')
            ->assertNoRedirect();

        $this->travel(61)->minutes();

        $this->lookUp($order->reference)->assertRedirect(route('orders.show', $order));
    }

    public function test_finding_an_order_costs_nothing_against_the_limit(): void
    {
        $order = $this->order();

        for ($i = 0; $i < 20; $i++) {
            $this->lookUp($order->reference)->assertHasNoErrors();
        }

        $this->lookUp('PY-2222222222')
            ->assertHasErrors('reference')
            ->assertDontSee('Too many references');
    }

    public function test_the_address_still_counts_when_the_browser_limit_is_dodged(): void
    {
        for ($i = 0; $i < 50; $i++) {
            Session::setId(Str::random(40));

            $this->lookUp('PY-2222222222')->assertDontSee('Too many references');
        }

        Session::setId(Str::random(40));

        $this->lookUp('PY-2222222222')->assertSee('Too many references');
    }

    /**
     * Submit a reference on the lookup page.
     */
    protected function lookUp(string $reference): Testable
    {
        return Livewire::test('pages::storefront.track')
            ->set('reference', $reference)
            ->call('submit');
    }
}
