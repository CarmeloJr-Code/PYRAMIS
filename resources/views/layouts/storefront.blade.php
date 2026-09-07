<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>

    <body class="min-h-screen bg-snow-50 text-snow-900 antialiased">
        <header class="sticky top-0 z-10 border-b border-snow-200 bg-snow-50/90 backdrop-blur">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-4">
                <a href="{{ route('home') }}" class="flex items-center gap-3" wire:navigate>
                    <img
                        src="{{ Vite::asset('resources/images/purple-yam-logo.png') }}"
                        alt=""
                        class="size-11 rounded-full"
                    >
                    <span class="flex flex-col leading-tight">
                        <span class="text-lg font-semibold tracking-tight text-orchid-700">{{ __('Purple Yam') }}</span>
                        <span class="text-xs tracking-[0.18em] text-snow-500 uppercase">{{ __('Malaybalay') }}</span>
                    </span>
                </a>

                <nav class="flex items-center gap-6 text-sm font-medium">
                    <a
                        href="{{ route('products.index') }}"
                        class="{{ request()->routeIs('products.*') ? 'text-orchid-700' : 'text-snow-600 hover:text-orchid-700' }}"
                        wire:navigate
                    >
                        {{ __('Products') }}
                    </a>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl px-6 py-10">
            {{ $slot }}
        </main>

        <footer class="mt-16 border-t border-snow-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-col gap-4 px-6 py-8 text-sm text-snow-500 sm:flex-row sm:items-center sm:justify-between">
                <p>&copy; {{ now()->year }} {{ __('Purple Yam Malaybalay. Home made cakes and pastries since 2013.') }}</p>

                {{-- The employee workspace entry point, per the unified portal design. --}}
                <a href="{{ route('login') }}" class="font-medium text-snow-600 hover:text-orchid-700">
                    {{ __('Employee sign in') }}
                </a>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
