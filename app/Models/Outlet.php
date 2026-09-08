<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OutletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $address
 * @property string|null $phone
 * @property bool $is_active
 * @property bool $is_main_branch
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'address', 'phone', 'is_active'])]
class Outlet extends Model
{
    /** @use HasFactory<OutletFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_main_branch' => 'boolean',
        ];
    }

    /**
     * The branch that runs production and supplies every other outlet
     * (BR-003, BR-004).
     *
     * @throws ModelNotFoundException<Outlet> when the business has none
     */
    public static function mainBranch(): self
    {
        return static::query()->where('is_main_branch', true)->firstOrFail();
    }

    /**
     * The orders due for collection at this outlet.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Limit the query to outlets currently accepting pickups.
     *
     * @param  Builder<Outlet>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
