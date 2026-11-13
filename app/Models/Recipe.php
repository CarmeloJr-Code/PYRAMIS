<?php

namespace App\Models;

use App\Enums\RecipeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * What one sellable size is made of.
 *
 * Quantities are held per batch, because that is how the business writes them
 * down — "good for 7 round pans". What a run of some other number of units
 * takes is scaled from the batch rather than stored a second time.
 *
 * A recipe can only be baked from once an Administrator has approved it
 * (BR-016). Saving it again puts the question back to them.
 *
 * @property int $id
 * @property int $product_variant_id
 * @property int $yield_quantity
 * @property string|null $notes
 * @property RecipeStatus $status
 * @property int|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['product_variant_id', 'yield_quantity', 'notes'])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    /**
     * The column's own default, so a recipe just created reads the same in
     * memory as it does in the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => RecipeStatus::Approved->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'yield_quantity' => 'integer',
            'status' => RecipeStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Whoever last saved it.
     *
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * The Administrator who approved it or sent it back.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Limit the query to recipes the bakery may bake from.
     *
     * @param  Builder<Recipe>  $query
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('status', RecipeStatus::Approved);
    }

    /**
     * Whether the bakery may bake from it.
     */
    public function isApproved(): bool
    {
        return $this->status === RecipeStatus::Approved;
    }

    /**
     * Stamp the recipe as saved by the given employee, without persisting.
     *
     * Whoever may approve recipes approves their own by saving it; anyone
     * else's waits, and whatever was decided about the version before no
     * longer stands. Set directly rather than through fill(): none of this is
     * mass-assignable, because only these methods may write it.
     */
    public function markSubmittedBy(User $employee): void
    {
        $approves = $employee->can('approve-recipes');

        $this->status = $approves ? RecipeStatus::Approved : RecipeStatus::Pending;
        $this->submitted_by = $employee->id;
        $this->submitted_at = now();
        $this->reviewed_by = $approves ? $employee->id : null;
        $this->reviewed_at = $approves ? now() : null;
        $this->review_note = null;
    }

    /**
     * Approve a recipe that is waiting.
     *
     * @throws RuntimeException when it is not waiting for approval
     */
    public function approve(User $reviewer): void
    {
        $this->review($reviewer, RecipeStatus::Approved, null);
    }

    /**
     * Send a waiting recipe back to be corrected, with the reason.
     *
     * @throws RuntimeException when it is not waiting for approval
     */
    public function sendBack(User $reviewer, string $note): void
    {
        $this->review($reviewer, RecipeStatus::Rejected, $note);
    }

    /**
     * Record the decision. Only a waiting recipe can be decided on, so a
     * crafted request cannot reject one in use or approve one sent back
     * without it being saved again.
     */
    private function review(User $reviewer, RecipeStatus $decision, ?string $note): void
    {
        if ($this->status !== RecipeStatus::Pending) {
            throw new RuntimeException('Only a recipe waiting for approval can be reviewed.');
        }

        $this->status = $decision;
        $this->reviewed_by = $reviewer->id;
        $this->reviewed_at = now();
        $this->review_note = $note;

        $this->save();
    }

    /**
     * The size this recipe makes.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * What it calls for.
     *
     * @return HasMany<RecipeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }

    /**
     * What producing the given number of units takes, in thousandths, keyed by
     * ingredient id.
     *
     * Each line is converted to its ingredient's stocked unit — a cup of flour
     * becomes a fraction of a kilogram — then scaled from the batch and rounded
     * once to the thousandth the ledger holds, so a requirement and the
     * movement that satisfies it are the same figure. A part batch is allowed:
     * bakers routinely make half of one.
     *
     * @return array<int, int>
     *
     * @throws RuntimeException when a line's ingredient has no conversion for its measure
     */
    public function requirementsInThousandths(int $units): array
    {
        $this->loadMissing('items.ingredient');

        $requirements = [];

        foreach ($this->items as $item) {
            /** @var RecipeItem $item */
            $requirements[$item->ingredient_id] = (int) round(
                $item->stockThousandthsPerBatch() * $units / $this->yield_quantity,
            );
        }

        return $requirements;
    }
}
