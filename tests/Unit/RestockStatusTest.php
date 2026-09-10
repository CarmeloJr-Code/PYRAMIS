<?php

namespace Tests\Unit;

use App\Enums\RestockStatus;
use PHPUnit\Framework\TestCase;

class RestockStatusTest extends TestCase
{
    public function test_it_covers_the_chain_the_spec_defines(): void
    {
        $this->assertSame(
            ['requested', 'preparing', 'delivered', 'cancelled'],
            array_map(fn (RestockStatus $status): string => $status->value, RestockStatus::cases()),
        );
    }

    public function test_the_workflow_runs_forward_one_step_at_a_time(): void
    {
        $this->assertSame(RestockStatus::Preparing, RestockStatus::Requested->next());
        $this->assertSame(RestockStatus::Delivered, RestockStatus::Preparing->next());
        $this->assertNull(RestockStatus::Delivered->next());
        $this->assertNull(RestockStatus::Cancelled->next());
    }

    public function test_only_the_next_step_or_a_cancellation_is_allowed(): void
    {
        $this->assertTrue(RestockStatus::Requested->canTransitionTo(RestockStatus::Preparing));
        $this->assertTrue(RestockStatus::Requested->canTransitionTo(RestockStatus::Cancelled));
        $this->assertTrue(RestockStatus::Preparing->canTransitionTo(RestockStatus::Cancelled));

        // Skipping preparation and walking backwards are both refused.
        $this->assertFalse(RestockStatus::Requested->canTransitionTo(RestockStatus::Delivered));
        $this->assertFalse(RestockStatus::Preparing->canTransitionTo(RestockStatus::Requested));
    }

    public function test_nothing_reopens_once_the_stock_has_moved(): void
    {
        foreach ([RestockStatus::Delivered, RestockStatus::Cancelled] as $terminal) {
            $this->assertTrue($terminal->isTerminal());

            foreach (RestockStatus::cases() as $target) {
                $this->assertFalse($terminal->canTransitionTo($target));
            }
        }
    }

    public function test_an_open_restock_is_not_finished(): void
    {
        $this->assertFalse(RestockStatus::Requested->isTerminal());
        $this->assertFalse(RestockStatus::Preparing->isTerminal());
    }
}
