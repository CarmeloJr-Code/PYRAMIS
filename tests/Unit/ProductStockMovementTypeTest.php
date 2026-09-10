<?php

namespace Tests\Unit;

use App\Enums\ProductStockMovementType;
use PHPUnit\Framework\TestCase;

class ProductStockMovementTypeTest extends TestCase
{
    public function test_a_bake_only_ever_puts_goods_on_the_shelf(): void
    {
        $this->assertSame(1, ProductStockMovementType::Produced->fixedDirection());
        $this->assertTrue(ProductStockMovementType::Produced->allowsIncrease());
        $this->assertFalse(ProductStockMovementType::Produced->allowsDecrease());
    }

    public function test_a_transfer_a_sale_and_a_correction_all_go_either_way(): void
    {
        // A transfer is both halves of a restock, a sale can be voided back,
        // and a correction is a correction.
        foreach ([
            ProductStockMovementType::Transfer,
            ProductStockMovementType::Sale,
            ProductStockMovementType::Adjustment,
        ] as $type) {
            $this->assertNull($type->fixedDirection());
            $this->assertTrue($type->allowsIncrease());
            $this->assertTrue($type->allowsDecrease());
        }
    }

    public function test_only_a_sale_may_take_a_shelf_below_zero(): void
    {
        // Money changed hands whatever the ledger believed; refusing it would
        // lose the takings rather than fix the count.
        $this->assertTrue(ProductStockMovementType::Sale->mayGoNegative());

        $this->assertFalse(ProductStockMovementType::Produced->mayGoNegative());
        $this->assertFalse(ProductStockMovementType::Transfer->mayGoNegative());
        $this->assertFalse(ProductStockMovementType::Adjustment->mayGoNegative());
    }

    public function test_only_a_hand_correction_has_to_say_why(): void
    {
        $this->assertTrue(ProductStockMovementType::Adjustment->requiresNote());
        $this->assertFalse(ProductStockMovementType::Produced->requiresNote());
        $this->assertFalse(ProductStockMovementType::Transfer->requiresNote());
        $this->assertFalse(ProductStockMovementType::Sale->requiresNote());
    }
}
