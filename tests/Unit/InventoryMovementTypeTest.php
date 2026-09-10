<?php

namespace Tests\Unit;

use App\Enums\InventoryMovementType;
use PHPUnit\Framework\TestCase;

class InventoryMovementTypeTest extends TestCase
{
    public function test_a_receipt_only_ever_adds_stock(): void
    {
        $this->assertSame(1, InventoryMovementType::Received->fixedDirection());
        $this->assertTrue(InventoryMovementType::Received->allowsIncrease());
        $this->assertFalse(InventoryMovementType::Received->allowsDecrease());
    }

    public function test_usage_only_ever_takes_stock_away(): void
    {
        $this->assertSame(-1, InventoryMovementType::Usage->fixedDirection());
        $this->assertFalse(InventoryMovementType::Usage->allowsIncrease());
        $this->assertTrue(InventoryMovementType::Usage->allowsDecrease());
    }

    public function test_an_adjustment_is_a_correction_and_may_go_either_way(): void
    {
        $this->assertNull(InventoryMovementType::Adjustment->fixedDirection());
        $this->assertTrue(InventoryMovementType::Adjustment->allowsIncrease());
        $this->assertTrue(InventoryMovementType::Adjustment->allowsDecrease());
    }

    public function test_only_a_hand_correction_has_to_say_why(): void
    {
        $this->assertTrue(InventoryMovementType::Adjustment->requiresNote());
        $this->assertFalse(InventoryMovementType::Received->requiresNote());
        $this->assertFalse(InventoryMovementType::Usage->requiresNote());
    }

    public function test_usage_is_kept_apart_from_an_adjustment(): void
    {
        // Collapsing the two would make an adjustment look like a bake, which
        // is the figure forecasting reads.
        $this->assertSame(
            ['received', 'usage', 'adjustment'],
            array_map(fn (InventoryMovementType $type): string => $type->value, InventoryMovementType::cases()),
        );
    }
}
