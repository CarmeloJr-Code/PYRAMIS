<?php

use App\Models\Category;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::storefront')] #[Title('Purple Yam Malaybalay')] class extends Component {
    /**
     * Categories that have something to show.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::query()
            ->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get();
    }
}; ?>

<div class="flex flex-col gap-16">
    <section class="flex flex-col items-start gap-6 rounded-2xl bg-orchid-800 px-8 py-14 text-white sm:px-14">
        <p class="text-xs font-medium tracking-[0.2em] text-orchid-200 uppercase">{{ __('Home made since 2013') }}</p>

        <h1 class="max-w-2xl text-4xl leading-tight font-semibold tracking-tight sm:text-5xl">
            {{ __('Ube cakes and pastries, baked in Malaybalay.') }}
        </h1>

        <p class="max-w-xl text-lg text-orchid-100">
            {{ __('Browse the menu, pre-order what you need, and pick it up at the outlet nearest you. No account required.') }}
        </p>

        <a
            href="{{ route('products.index') }}"
            class="rounded-lg bg-white px-5 py-3 text-sm font-semibold text-orchid-800 transition hover:bg-orchid-50"
            wire:navigate
        >
            {{ __('See the menu') }}
        </a>
    </section>

    @if ($this->categories->isNotEmpty())
        <section class="flex flex-col gap-6">
            <h2 class="text-2xl font-semibold tracking-tight">{{ __('What we bake') }}</h2>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->categories as $category)
                    <a
                        href="{{ route('products.index', ['category' => $category->slug]) }}"
                        class="flex flex-col gap-2 rounded-xl border border-snow-200 bg-white p-6 transition hover:border-orchid-300"
                        wire:navigate
                    >
                        <span class="font-semibold text-orchid-700">{{ $category->name }}</span>
                        <span class="text-sm text-snow-500">
                            {{ trans_choice('{1} :count product|[2,*] :count products', $category->products_count, ['count' => $category->products_count]) }}
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <section class="flex flex-col gap-6">
        <h2 class="text-2xl font-semibold tracking-tight">{{ __('How ordering works') }}</h2>

        <ol class="grid gap-4 sm:grid-cols-3">
            @foreach ([
                ['1', __('Choose your cake'), __('Pick a product and the size you need. Prices are per size.')],
                ['2', __('Tell us the details'), __('Fill in a short form when you order — no account to create.')],
                ['3', __('Pick it up'), __('Choose a pickup outlet and collect your order there.')],
            ] as [$step, $heading, $body])
                <li class="flex flex-col gap-2 rounded-xl border border-snow-200 bg-white p-6">
                    <span class="flex size-8 items-center justify-center rounded-full bg-orchid-100 text-sm font-semibold text-orchid-700">{{ $step }}</span>
                    <span class="font-semibold">{{ $heading }}</span>
                    <span class="text-sm text-snow-500">{{ $body }}</span>
                </li>
            @endforeach
        </ol>
    </section>
</div>
