<?php

namespace Tests\Unit;

use App\Enums\SaleStatus;
use PHPUnit\Framework\TestCase;

class SaleStatusTest extends TestCase
{
    public function test_a_voided_sale_reads_as_called_off_and_a_completed_one_as_done(): void
    {
        $this->assertSame('green', SaleStatus::Completed->color());
        $this->assertSame('red', SaleStatus::Voided->color());
    }
}
