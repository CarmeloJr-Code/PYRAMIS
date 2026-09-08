<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Record expense')] class extends Component {
    #[Locked]
    public ?int $expenseId = null;

    public ?int $expense_category_id = null;

    public ?int $outlet_id = null;

    public string $amount = '';

    public string $spent_on = '';

    public string $description = '';

    /**
     * Mount the component for either a new or an existing expense.
     */
    public function mount(?Expense $expense = null): void
    {
        Gate::authorize('access-expenses');

        if ($expense?->exists) {
            // Changing one already filed is oversight, not recording.
            Gate::authorize('manage-expenses');

            $this->expenseId = $expense->id;
            $this->expense_category_id = $expense->expense_category_id;
            $this->outlet_id = $expense->outlet_id;
            $this->amount = (string) $expense->amount;
            $this->spent_on = $expense->spent_on->toDateString();
            $this->description = $expense->description;

            return;
        }

        $this->spent_on = now()->toDateString();
        $this->outlet_id = $this->outlets->first()?->id;
    }

    /**
     * The locations an expense can belong to.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()
            ->active()
            ->orderByDesc('is_main_branch')
            ->orderBy('name')
            ->get();
    }

    /**
     * The headings on offer. A retired one stays selectable while it is the one
     * already on the expense being corrected.
     *
     * @return Collection<int, ExpenseCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return ExpenseCategory::query()
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->orWhere('id', $this->expense_category_id ?? 0))
            ->orderBy('name')
            ->get();
    }

    /**
     * File the expense.
     */
    public function save(): void
    {
        Gate::authorize($this->expenseId === null ? 'access-expenses' : 'manage-expenses');

        $validated = $this->validate([
            'expense_category_id' => [
                'required', 'integer',
                // A retired heading cannot be chosen for something new, but may
                // stay on an expense that already carries it.
                Rule::exists('expense_categories', 'id')->where(
                    fn ($query) => $this->expenseId === null
                        ? $query->where('is_active', true)
                        : $query,
                ),
            ],
            'outlet_id' => [
                'required', 'integer',
                Rule::exists('outlets', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            // Money cannot have been spent in the future.
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        $expense = $this->expenseId === null
            ? new Expense(['recorded_by' => Auth::id()])
            : Expense::findOrFail($this->expenseId);

        // recorded_by is not rewritten on an edit: the record of who filed it
        // is part of the audit trail, not a field the corrector inherits.
        $expense->fill([
            'expense_category_id' => $validated['expense_category_id'],
            'outlet_id' => $validated['outlet_id'],
            'amount' => $validated['amount'],
            'spent_on' => $validated['spent_on'],
            'description' => $validated['description'],
        ])->save();

        Flux::toast(variant: 'success', text: __('Expense saved.'));

        $this->redirectRoute('employee.expenses.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">
        {{ $expenseId === null ? __('Record expense') : __('Edit expense') }}
    </flux:heading>
    <flux:text class="mt-2">{{ __('Money that left the business, filed against the place it belongs to.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->categories->isEmpty())
        <flux:callout icon="tag">
            <flux:callout.heading>{{ __('No categories yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('An expense has to be filed under something. A manager sets the headings up first.') }}</flux:callout.text>
        </flux:callout>
    @else
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:input wire:model="description" :label="__('What it was')" placeholder="{{ __('Flour delivery') }}" required autofocus />

            <flux:input wire:model="amount" :label="__('Amount')" type="number" step="0.01" min="0.01" required />

            <flux:select wire:model="expense_category_id" :label="__('Category')" required>
                <flux:select.option value="">{{ __('Choose a category') }}</flux:select.option>
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id">
                        {{ $category->name }}{{ $category->is_active ? '' : ' · '.__('retired') }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="outlet_id" :label="__('Location')" required>
                <flux:select.option value="">{{ __('Choose a location') }}</flux:select.option>
                @foreach ($this->outlets as $outlet)
                    <flux:select.option :value="$outlet->id">
                        {{ $outlet->name }}{{ $outlet->is_main_branch ? ' · '.__('Main branch') : '' }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="spent_on" :label="__('Date spent')" type="date" required />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save expense') }}</flux:button>
                <flux:button variant="ghost" :href="route('employee.expenses.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif
</section>
