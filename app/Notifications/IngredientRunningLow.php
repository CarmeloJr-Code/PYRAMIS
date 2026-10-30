<?php

namespace App\Notifications;

use App\Models\Ingredient;
use Illuminate\Notifications\Notification;

/**
 * An ingredient has just dropped to or below its reorder level.
 */
class IngredientRunningLow extends Notification
{
    /**
     * @param  int  $remainingInThousandths  What the movement left on the shelf.
     */
    public function __construct(public Ingredient $ingredient, public int $remainingInThousandths) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, body: string, url: string, icon: string}
     */
    public function toArray(object $notifiable): array
    {
        $unit = $this->ingredient->unit->abbreviation();

        return [
            'title' => __(':ingredient is running low', ['ingredient' => $this->ingredient->name]),
            'body' => __(':remaining left, against a reorder level of :level.', [
                'remaining' => Ingredient::formatQuantity($this->remainingInThousandths).' '.$unit,
                'level' => $this->ingredient->reorderLevel().' '.$unit,
            ]),
            'url' => route('employee.inventory.show', $this->ingredient),
            'icon' => 'exclamation-triangle',
        ];
    }
}
