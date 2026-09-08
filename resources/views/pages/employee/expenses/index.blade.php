<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Expenses')] class extends Component {
    #[Url(as: 'from', except: '')]
    public string $from = '';

    #[Url(as: 'to', except: '')]
    public string $to = '';

    #[Url(as: 'outlet', except: 0)]
    public int $outletId = 0;

    #[Url(as: 'category', except: 0)]
    public int $categoryId = 0;

    /**
     * Authorize every entry into the component, not just the route.
     */
    public function mount(): void
    {
        Gate::authorize('access-expenses');

        // The month so far is what an expense screen is usually opened to see.
        if ($this->from === '') {
            $this->from = now()->startOfMonth()->toDateString();
        }

        if ($this->to === '') {
            $this->to = now()->toDateString();
        }
    }

    /**
     * The locations expenses can belong to.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()
            ->orderByDesc('is_main_branch')
            ->orderBy('name')
            ->get();
    }

    /**
     * Every heading, including retired ones — an old expense still has to be
     * filterable by what it was filed under.
     *
     * @return Collection<int, ExpenseCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return ExpenseCategory::query()->orderBy('name')->get();
    }

    /**
     * The expenses matching the filters, newest first.
     *
     * @return Collection<int, Expense>
     */
    #[Computed]
    public function expenses(): Collection
    {
        return Expense::query()
            ->with(['category', 'outlet', 'recordedBy'])
            ->spentBetween($this->from, $this->to)
            ->when($this->outletId > 0, fn ($query) => $query->where('outlet_id', $this->outletId))
            ->when($this->categoryId > 0, fn ($query) => $query->where('expense_category_id', $this->categoryId))
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * What the filtered expenses come to.
     */
    #[Computed]
    public function total(): string
    {
        $centavos = $this->expenses->sum(fn (Expense $expense): int => $expense->amountInCentavos());

        return number_format($centavos / 100, 2);
    }

    /**
     * The same total broken down by heading — the summary the exit criterion
     * asks for, derived from the rows on screen rather than a second query.
     *
     * @return Collection<int, array{name: string, total: string}>
     */
    #[Computed]
    public function byCategory(): Collection
    {
        return $this->expenses
            ->groupBy('expense_category_id')
            ->map(fn (Collection $expenses): array => [
                'name' => $expenses->first()->category->name,
                'total' => number_format(
                    $expenses->sum(fn (Expense $expense): int => $expense->amountInCentavos()) / 100,
                    2,
                ),
            ])
            ->sortBy('name')
            ->values();
    }

    /**
     * Remove an expense filed in error.
     */
    public function delete(int $expenseId): void
    {
        Gate::authorize('manage-expenses');

        Expense::findOrFail($expenseId)->delete();

        unset($this->expenses, $this->total, $this->byCategory);

        Flux::toast(variant: 'success', text: __('Expense removed.'));
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Expenses') }}</flux:heading>
            <flux:text class="mt-2">{{ __('What the business spent, where, and on what.') }}</flux:text>
        </div>

        <div class="flex items-center gap-3">
            @can('manage-expenses')
                <flux:button :href="route('employee.expenses.categories')" variant="ghost" icon="tag" wire:navigate>
                    {{ __('Categories') }}
                </flux:button>
            @endcan

            <flux:button :href="route('employee.expenses.create')" variant="primary" icon="plus" wire:navigate>
                {{ __('Record expense') }}
            </flux:button>
        </div>
    </div>

    <flux:separator variant="subtle" class="my-6" />

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <flux:input wire:model.live="from" type="date" :label="__('From')" class="max-w-44" />
        <flux:input wire:model.live="to" type="date" :label="__('To')" class="max-w-44" />

        <flux:select wire:model.live="outletId" :label="__('Location')" class="max-w-56">
            <flux:select.option value="0">{{ __('Everywhere') }}</flux:select.option>
            @foreach ($this->outlets as $outlet)
                <flux:select.option :value="$outlet->id">{{ $outlet->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="categoryId" :label="__('Category')" class="max-w-56">
            <flux:select.option value="0">{{ __('All categories') }}</flux:select.option>
            @foreach ($this->categories as $category)
                <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="ms-auto flex flex-col items-end">
            <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ __('Total') }}</span>
            <span class="text-3xl font-semibold">&#8369;{{ $this->total }}</span>
            <span class="text-sm text-zinc-500">
                {{ trans_choice('{1} :count expense|[2,*] :count expenses', $this->expenses->count(), ['count' => $this->expenses->count()]) }}
            </span>
        </div>
    </div>

    @if ($this->byCategory->isNotEmpty())
        <div class="mb-6 flex flex-wrap gap-2">
            @foreach ($this->byCategory as $row)
                <flux:badge color="zinc" wire:key="cat-{{ $loop->index }}">
                    {{ $row['name'] }}: &#8369;{{ $row['total'] }}
                </flux:badge>
            @endforeach
        </div>
    @endif

    @if ($this->expenses->isEmpty())
        <flux:callout icon="banknotes">
            <flux:callout.heading>{{ __('Nothing recorded') }}</flux:callout.heading>
            <flux:callout.text>{{ __('No expenses match these dates and filters.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Date') }}</flux:table.column>
                    <flux:table.column>{{ __('Description') }}</flux:table.column>
                    <flux:table.column>{{ __('Category') }}</flux:table.column>
                    <flux:table.column>{{ __('Location') }}</flux:table.column>
                    <flux:table.column>{{ __('Recorded by') }}</flux:table.column>
                    <flux:table.column>{{ __('Amount') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->expenses as $expense)
                        <flux:table.row :key="$expense->id">
                            <flux:table.cell>{{ $expense->spent_on->format('d M Y') }}</flux:table.cell>
                            <flux:table.cell class="font-medium">{{ $expense->description }}</flux:table.cell>
                            <flux:table.cell>{{ $expense->category->name }}</flux:table.cell>
                            <flux:table.cell>{{ $expense->outlet->name }}</flux:table.cell>
                            <flux:table.cell class="text-zinc-500">{{ $expense->recordedBy->name }}</flux:table.cell>
                            <flux:table.cell>&#8369;{{ number_format((float) $expense->amount, 2) }}</flux:table.cell>
                            <flux:table.cell class="text-end">
                                @can('manage-expenses')
                                    <flux:button size="sm" variant="ghost" :href="route('employee.expenses.edit', $expense)" wire:navigate>
                                        {{ __('Edit') }}
                                    </flux:button>

                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="delete({{ $expense->id }})"
                                        wire:confirm="{{ __('Remove this expense? It is gone for good.') }}"
                                    >
                                        {{ __('Remove') }}
                                    </flux:button>
                                @endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif
</section>
