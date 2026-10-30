<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        {{-- Floated, so each page's heading row (a flex box) sits beside it rather than under it. --}}
        <div class="float-end ms-4">
            <livewire:notification-bell />
        </div>

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
