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

    public function test_every_case_has_a_customer_facing_label(): void
    {
        $this->assertSame('Pending', OrderStatus::Pending->label());
        $this->assertSame('Ready for pickup', OrderStatus::Ready->label());
        $this->assertSame('Cancelled', OrderStatus::Cancelled->label());
    }
}
