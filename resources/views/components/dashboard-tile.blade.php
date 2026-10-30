{{-- A dashboard figure that opens the screen it was counted from. --}}
@props(['href', 'label'])

<a href="{{ $href }}" wire:navigate {{ $attributes->class('group block rounded-xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-purple-500') }}>
    <flux:card class="h-full transition-colors group-hover:border-zinc-300 group-hover:bg-zinc-50 dark:group-hover:border-zinc-600 dark:group-hover:bg-white/5">
        <span class="text-xs font-medium tracking-wide text-zinc-500 uppercase">{{ $label }}</span>

        {{ $slot }}
    </flux:card>
</a>
