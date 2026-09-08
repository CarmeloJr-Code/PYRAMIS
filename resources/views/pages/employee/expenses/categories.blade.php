<?php

use App\Models\ExpenseCategory;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Expense categories')] class extends Component {
    public string $name = '';

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('manage-expenses');
    }

    /**
     * The headings, with how much has been filed under each.
     *
     * @return Collection<int, ExpenseCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return ExpenseCategory::query()
            ->withCount('expenses')
            ->orderBy('name')
            ->get();
    }

    /**
     * Add a heading.
     */
    public function add(): void
    {
        Gate::authorize('manage-expenses');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('expense_categories', 'name')],
        ]);

        ExpenseCategory::create(['name' => $validated['name'], 'is_active' => true]);

        $this->reset('name');

        unset($this->categories);

        Flux::toast(variant: 'success', text: __('Category added.'));
    }

    /**
     * Retire a heading, or bring it back.
     *
     * Never deleted: the expenses already filed under it would lose what they
     * were for, and the foreign key restricts on delete for that reason.
     */
    public function toggleActive(int $categoryId): void
    {
        Gate::authorize('manage-expenses');

        $category = ExpenseCategory::findOrFail($categoryId);

        $category->update(['is_active' => ! $category->is_active]);

        unset($this->categories);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Expense categories') }}</flux:heading>
            <flux:text class="mt-2">{{ __('The headings expenses are filed under. Rename them to match how the business actually accounts for its spending.') }}</flux:text>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee.expenses.index')" wire:navigate>
            {{ __('Back to expenses') }}
        </flux:button>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="grid gap-8 lg:grid-cols-3">
        <div class="lg:col-span-2 overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Category') }}</flux:table.column>
                    <flux:table.column>{{ __('Expenses') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->categories as $category)
                        <flux:table.row :key="$category->id">
                            <flux:table.cell class="font-medium">{{ $category->name }}</flux:table.cell>
                            <flux:table.cell>{{ $category->expenses_count }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="$category->is_active ? 'green' : 'zinc'">
                                    {{ $category->is_active ? __('In use') : __('Retired') }}
                                </flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="text-end">
                                <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $category->id }})">
                                    {{ $category->is_active ? __('Retire') : __('Bring back') }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        <div>
            <flux:card>
                <flux:heading size="lg">{{ __('Add a category') }}</flux:heading>

                <form wire:submit="add" class="mt-4 flex flex-col gap-4">
                    <flux:input wire:model="name" :label="__('Name')" required />
                    <flux:button variant="primary" type="submit">{{ __('Add') }}</flux:button>
                </form>
            </flux:card>
        </div>
    </div>
</section>
