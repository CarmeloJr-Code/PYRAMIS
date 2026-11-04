<?php

namespace Tests\Feature\Employee;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Texts through UniSMS (BR-014): an employee hears about their shifts, a
 * customer hears that their order is ready, and a text that fails never undoes
 * the change it was about.
 */
class SmsNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY = 'https://unismsapi.com/api/sms';

    private Outlet $outlet;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
        $this->administrator = User::factory()->administrator()->create();
    }

    /**
     * Point the channel at the gateway with a key, and answer every text with
     * the given response.
     */
    private function gateway(mixed $response = null): void
    {
        config(['services.unisms.key' => 'sk-test', 'services.unisms.sender_id' => 'PurpleYam']);

        Http::preventStrayRequests();
        Http::fake([self::GATEWAY => $response ?? Http::response(['message' => ['status' => 'pending']], 201)]);
    }

    /**
     * The texts sent, as recipient => content.
     *
     * @return array<string, string>
     */
    private function texts(): array
    {
        return Http::recorded()
            ->mapWithKeys(fn (array $pair) => [$pair[0]['recipient'] => $pair[0]['content']])
            ->all();
    }

    private function readyOrder(): Order
    {
        $order = Order::factory()
            ->status(OrderStatus::Preparing)
            ->for($this->outlet)
            ->create(['customer_phone' => '09171234567']);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.orders.show', ['order' => $order])
            ->call('advance');

        return $order->fresh();
    }

    public function test_an_order_made_ready_texts_the_customer_how_to_collect_it(): void
    {
        $this->gateway();

        $order = $this->readyOrder();

        $this->assertSame(OrderStatus::Ready, $order->status);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::GATEWAY
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk-test:'))
            && $request['recipient'] === '+639171234567'
            && $request['sender_id'] === 'PurpleYam'
            && $request['content'] === "Purple Yam Malaybalay: Your order {$order->reference} is ready for pickup at Main Branch. Show this reference when you collect it. ".route('orders.show', $order));
    }

    public function test_no_other_status_change_texts_the_customer(): void
    {
        $this->gateway();
        $cashier = User::factory()->cashier()->create();

        $confirmed = Order::factory()->status(OrderStatus::Confirmed)->for($this->outlet)->create();
        $ready = Order::factory()->status(OrderStatus::Ready)->for($this->outlet)->create();
        $preparing = Order::factory()->status(OrderStatus::Preparing)->for($this->outlet)->create();

        // Confirmed → Preparing, Ready → Completed, and a cancel.
        Livewire::actingAs($cashier)->test('pages::employee.orders.show', ['order' => $confirmed])->call('advance');
        Livewire::actingAs($cashier)->test('pages::employee.orders.show', ['order' => $ready])->call('advance');
        Livewire::actingAs($cashier)->test('pages::employee.orders.show', ['order' => $preparing])->call('cancel');

        $this->assertSame(OrderStatus::Preparing, $confirmed->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $ready->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $preparing->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_refused_text_is_reported_and_the_order_stays_ready(): void
    {
        Exceptions::fake();
        $this->gateway(Http::response(['errors' => 'Invalid recipient'], 422));

        $order = $this->readyOrder();

        $this->assertSame(OrderStatus::Ready, $order->status);

        // Reported with the number masked, never in full.
        Exceptions::assertReported(fn (RuntimeException $exception) => str_contains($exception->getMessage(), '*********4567')
            && ! str_contains($exception->getMessage(), '9171234567'));
    }

    public function test_an_unreachable_gateway_is_reported_and_the_order_stays_ready(): void
    {
        Exceptions::fake();
        $this->gateway(Http::failedConnection());

        $order = $this->readyOrder();

        $this->assertSame(OrderStatus::Ready, $order->status);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_with_no_key_the_text_is_logged_rather_than_sent(): void
    {
        config(['services.unisms.key' => null]);
        Http::preventStrayRequests();
        Http::fake();
        Log::spy();

        $order = $this->readyOrder();

        $this->assertSame(OrderStatus::Ready, $order->status);
        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->once()->with(
            'SMS not sent: no UniSMS key is configured.',
            Mockery::on(fn (array $context) => $context['recipient'] === '*********4567'
                && str_contains($context['content'], $order->reference)),
        );
    }

    public function test_assigning_a_shift_texts_the_employee_what_when_and_where(): void
    {
        $this->gateway();
        $shift = Shift::factory()->for($this->outlet)->on('2026-11-05', '06:00', '14:00')->create(['name' => 'Morning bake']);
        $baker = User::factory()->baker()->create(['phone' => '09181112222']);

        Livewire::actingAs($this->administrator)
            ->test('pages::employee.workforce.show', ['shift' => $shift])
            ->set('employeeId', $baker->id)
            ->call('assign')
            ->assertHasNoErrors();

        $this->assertSame(
            ['+639181112222' => 'Purple Yam: You are on Morning bake, Thu 05 Nov, 6:00am-2:00pm at Main Branch.'],
            $this->texts(),
        );
    }

    public function test_an_employee_with_no_number_is_not_texted_but_still_assigned(): void
    {
        $this->gateway();
        $shift = Shift::factory()->for($this->outlet)->create();
        $baker = User::factory()->baker()->create(['phone' => null]);

        Livewire::actingAs($this->administrator)
            ->test('pages::employee.workforce.show', ['shift' => $shift])
            ->set('employeeId', $baker->id)
            ->call('assign');

        $this->assertDatabaseHas('shift_assignments', ['shift_id' => $shift->id, 'user_id' => $baker->id]);
        $this->assertSame(1, $baker->notifications()->count());
        Http::assertNothingSent();
    }

    public function test_a_failed_text_still_leaves_the_employee_on_the_shift(): void
    {
        Exceptions::fake();
        $this->gateway(Http::response([], 500));
        $shift = Shift::factory()->for($this->outlet)->create();
        $baker = User::factory()->baker()->withPhone()->create();

        Livewire::actingAs($this->administrator)
            ->test('pages::employee.workforce.show', ['shift' => $shift])
            ->set('employeeId', $baker->id)
            ->call('assign')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('shift_assignments', ['shift_id' => $shift->id, 'user_id' => $baker->id]);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_moving_a_shift_texts_everyone_on_it_but_the_administrator_and_closed_accounts(): void
    {
        $this->gateway();
        $shift = Shift::factory()->for($this->outlet)->on('2026-11-05', '06:00', '14:00')->create(['name' => 'Morning bake']);
        $baker = User::factory()->baker()->create(['phone' => '09181112222']);
        $closed = User::factory()->cashier()->create(['phone' => '09183334444', 'is_active' => false]);
        $this->administrator->update(['phone' => '09185556666']);

        foreach ([$baker, $closed, $this->administrator] as $employee) {
            ShiftAssignment::factory()->for($shift)->for($employee)->create();
        }

        Livewire::actingAs($this->administrator)
            ->test('pages::employee.workforce.manage', ['shift' => $shift])
            ->set('starts_at', '07:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['+639181112222' => 'Purple Yam: Your shift has changed. It is now Morning bake, Thu 05 Nov, 7:00am-2:00pm at Main Branch.'],
            $this->texts(),
        );
        $this->assertSame(1, $baker->notifications()->count());
    }

    public function test_moving_a_shift_to_another_outlet_texts_the_new_place(): void
    {
        $this->gateway();
        $kiosk = Outlet::factory()->create(['name' => 'Kiosk']);
        $shift = Shift::factory()->for($this->outlet)->create();
        $baker = User::factory()->baker()->create(['phone' => '09181112222']);
        ShiftAssignment::factory()->for($shift)->for($baker)->create();

        Livewire::actingAs($this->administrator)
            ->test('pages::employee.workforce.manage', ['shift' => $shift])
            ->set('outlet_id', $kiosk->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringEndsWith('at Kiosk.', $this->texts()['+639181112222']);
    }

    public function test_renaming_a_shift_or_changing_its_note_texts_nobody(): void
    {
        $this->gateway();
        $shift = Shift::factory()->for($this->outlet)->create();
        ShiftAssignment::factory()->for($shift)->for(User::factory()->baker()->withPhone())->create();

        Livewire::actingAs($this->administrator)
            ->test('pages::employee.workforce.manage', ['shift' => $shift])
            ->set('name', 'Renamed')
            ->set('notes', 'Bring the ube')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed', $shift->fresh()->name);
        Http::assertNothingSent();
    }

    public function test_taking_someone_off_a_shift_texts_them(): void
    {
        $this->gateway();
        $shift = Shift::factory()->for($this->outlet)->on('2026-11-05', '06:00', '14:00')->create(['name' => 'Morning bake']);
        $baker = User::factory()->baker()->create(['phone' => '09181112222']);
        $assignment = ShiftAssignment::factory()->for($shift)->for($baker)->create();

        Livewire::actingAs($this->administrator)
            ->test('pages::employee.workforce.show', ['shift' => $shift])
            ->call('unassign', $assignment->id);

        $this->assertDatabaseMissing('shift_assignments', ['id' => $assignment->id]);
        $this->assertSame(
            ['+639181112222' => 'Purple Yam: You have been taken off Morning bake, Thu 05 Nov, 6:00am-2:00pm at Main Branch.'],
            $this->texts(),
        );
        $this->assertSame(route('employee.schedule'), $baker->notifications()->firstOrFail()->data['url']);
    }

    public function test_an_administrator_taking_themselves_off_is_not_texted(): void
    {
        $this->gateway();
        $shift = Shift::factory()->for($this->outlet)->create();
        $this->administrator->update(['phone' => '09185556666']);
        $assignment = ShiftAssignment::factory()->for($shift)->for($this->administrator)->create();

        Livewire::actingAs($this->administrator)
            ->test('pages::employee.workforce.show', ['shift' => $shift])
            ->call('unassign', $assignment->id);

        $this->assertDatabaseMissing('shift_assignments', ['id' => $assignment->id]);
        Http::assertNothingSent();
    }

    public function test_an_assignment_on_another_shift_texts_nobody(): void
    {
        $this->gateway();
        $mine = Shift::factory()->for($this->outlet)->create();
        $theirs = ShiftAssignment::factory()
            ->for(Shift::factory()->for($this->outlet))
            ->for(User::factory()->baker()->withPhone())
            ->create();

        Livewire::actingAs($this->administrator)
            ->test('pages::employee.workforce.show', ['shift' => $mine])
            ->call('unassign', $theirs->id);

        $this->assertDatabaseHas('shift_assignments', ['id' => $theirs->id]);
        Http::assertNothingSent();
    }
}
