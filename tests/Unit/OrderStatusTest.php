<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_it_covers_the_lifecycle_the_spec_defines(): void
    {
        $this->assertSame(
            ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'],
            array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::cases()),
        );
    }

    public function test_the_workflow_runs_forward_one_step_at_a_time(): void
    {
        $this->assertSame(OrderStatus::Confirmed, OrderStatus::Pending->next());
        $this->assertSame(OrderStatus::Preparing, OrderStatus::Confirmed->next());
        $this->assertSame(OrderStatus::Ready, OrderStatus::Preparing->next());
        $this->assertSame(OrderStatus::Completed, OrderStatus::Ready->next());
        $this->assertNull(OrderStatus::Completed->next());
        $this->assertNull(OrderStatus::Cancelled->next());
    }

    public function test_only_the_next_step_or_a_cancellation_is_allowed(): void
    {
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::Confirmed));
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::Cancelled));

        // Skipping ahead and walking backwards are both refused.
        $this->assertFalse(OrderStatus::Pending->canTransitionTo(OrderStatus::Ready));
        $this->assertFalse(OrderStatus::Ready->canTransitionTo(OrderStatus::Preparing));
    }

    public function test_finished_orders_cannot_move_again(): void
    {
        foreach ([OrderStatus::Completed, OrderStatus::Cancelled] as $terminal) {
            $this->assertTrue($terminal->isTerminal());

            foreach (OrderStatus::cases() as $target) {
                $this->assertFalse($terminal->canTransitionTo($target));
            }
        }
    }

    public function test_every_case_has_a_customer_facing_label(): void
    {
        $this->assertSame('Pending', OrderStatus::Pending->label());
        $this->assertSame('Ready for pickup', OrderStatus::Ready->label());
        $this->assertSame('Cancelled', OrderStatus::Cancelled->label());
    }
}
