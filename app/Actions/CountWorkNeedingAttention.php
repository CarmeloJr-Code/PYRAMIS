<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\RestockStatus;
use App\Models\Ingredient;
use App\Models\Message;
use App\Models\Order;
use App\Models\Restock;
use App\Models\User;

/**
 * What is waiting on the signed-in employee, per sidebar section.
 *
 * Each figure is the work someone has to pick up next, not everything open:
 * a payment waiting to be checked, not an order waiting for its customer; a
 * restock nobody has started on, not one already on its way. Only the sections
 * the employee can open are counted, so a Cashier's page never pays for the
 * stockroom's figure.
 *
 * One query a section whatever the size of the business, because it runs on
 * every page of the workspace. Nothing here writes.
 */
final class CountWorkNeedingAttention
{
    /**
     * Counts keyed by section, present only for the sections the employee can
     * open and only when there is something waiting.
     *
     * @return array{orders?: int, inventory?: int, restocks?: int, messages?: int}
     */
    public function handle(User $employee): array
    {
        $counts = [
            'orders' => $employee->can('manage-orders') ? $this->paymentsToCheck() : 0,
            'inventory' => $employee->can('access-inventory') ? $this->lowStock() : 0,
            'restocks' => $employee->can('access-restocking') ? $this->restocksToPrepare() : 0,
            'messages' => $this->unreadMessages($employee),
        ];

        return array_filter($counts);
    }

    /**
     * Pending orders whose customer has sent proof of payment nobody has
     * checked yet — the orders the counter can move on right now.
     */
    private function paymentsToCheck(): int
    {
        return Order::query()
            ->where('status', OrderStatus::Pending)
            ->whereHas('payment', fn ($query) => $query
                ->whereNotNull('submitted_at')
                ->whereNull('verified_at'))
            ->count();
    }

    /**
     * Ingredients at or below their reorder level, by the same test the
     * dashboard and the stockroom use.
     */
    private function lowStock(): int
    {
        return Ingredient::query()
            ->active()
            ->withStock()
            ->get()
            ->filter(fn (Ingredient $ingredient): bool => $ingredient->isLowStock())
            ->count();
    }

    /**
     * Restocks scheduled but not yet started on at the main branch.
     */
    private function restocksToPrepare(): int
    {
        return Restock::query()->where('status', RestockStatus::Requested)->count();
    }

    /**
     * Messages from others, across every thread the employee is in, past where
     * they last read. One join rather than a count per conversation.
     */
    private function unreadMessages(User $employee): int
    {
        return Message::query()
            ->join('conversation_participants', 'conversation_participants.conversation_id', '=', 'messages.conversation_id')
            ->where('conversation_participants.user_id', $employee->id)
            ->where('messages.user_id', '!=', $employee->id)
            ->where(fn ($query) => $query
                ->whereNull('conversation_participants.last_read_message_id')
                ->orWhereColumn('messages.id', '>', 'conversation_participants.last_read_message_id'))
            ->count();
    }
}
