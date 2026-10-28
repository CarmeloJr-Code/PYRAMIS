<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>

    <body class="min-h-screen bg-snow-50 text-snow-900 antialiased" data-storefront>
        {{--
            The storefront is drawn light only, but the shared head applies the
            visitor's system appearance, and dark Flux fields on these white
            cards turn to white text on white. Flux puts the class back when
            Alpine starts, on every wire:navigate and when the system theme
            changes, so watch the class and drop it while a storefront page is
            showing, without touching the stored preference the workspace uses.
        --}}
        <script data-storefront-appearance>
            (() => {
                const root = document.documentElement
                {{-- Only when present: remove() rewrites the attribute either way, which would wake the observer forever. --}}
                const keepLight = () => {
                    if (document.body?.hasAttribute('data-storefront') && root.classList.contains('dark')) {
                        root.classList.remove('dark')
                    }
                }

                keepLight()

                if (! window.storefrontAppearance) {
                    window.storefrontAppearance = new MutationObserver(keepLight)
                    window.storefrontAppearance.observe(root, { attributes: true, attributeFilter: ['class'] })
                }
            })()
        </script>

        <header class="sticky top-0 z-10 border-b border-snow-200 bg-snow-50/90 backdrop-blur">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-4">
                <a href="{{ route('home') }}" class="flex items-center gap-3" wire:navigate.hover>
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

                <nav class="flex items-center gap-4 text-sm font-medium sm:gap-6">
                    <a
                        href="{{ route('products.index') }}"
                        class="{{ request()->routeIs('products.*') ? 'text-orchid-700' : 'text-snow-600 hover:text-orchid-700' }}"
                        wire:navigate.hover
                    >
                        {{ __('Products') }}
                    </a>

                    <a
                        href="{{ route('orders.track') }}"
                        class="{{ request()->routeIs('orders.track', 'orders.show') ? 'text-orchid-700' : 'text-snow-600 hover:text-orchid-700' }}"
                        wire:navigate.hover
                    >
                        {{ __('Track order') }}
                    </a>

                    @php($cartCount = array_sum(session('cart', [])))

                    <a
                        href="{{ route('order') }}"
                        class="flex items-center gap-2 {{ request()->routeIs('order') ? 'text-orchid-700' : 'text-snow-600 hover:text-orchid-700' }}"
                        wire:navigate.hover
                    >
                        {{ __('Your order') }}
                        @if ($cartCount > 0)
                            <span class="flex size-5 items-center justify-center rounded-full bg-orchid-600 text-xs font-semibold text-white">{{ $cartCount }}</span>
                        @endif
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
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
