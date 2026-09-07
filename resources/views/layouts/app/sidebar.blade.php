<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('employee.dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            {{-- Nav visibility is cosmetic; the `can:` middleware on each route is the boundary. --}}
            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Workspace')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('employee.dashboard')" :current="request()->routeIs('employee.dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    @can('manage-products')
                        <flux:sidebar.item icon="squares-2x2" :href="route('employee.products.index')" :current="request()->routeIs('employee.products.*')" wire:navigate>
                            {{ __('Products') }}
                        </flux:sidebar.item>
                    @endcan

                    @can('manage-outlets')
                        <flux:sidebar.item icon="building-storefront" :href="route('employee.outlets.index')" :current="request()->routeIs('employee.outlets.*')" wire:navigate>
                            {{ __('Outlets') }}
                        </flux:sidebar.item>
                    @endcan

                    @can('access-sales')
                        <flux:sidebar.item icon="banknotes" :href="route('employee.sales')" :current="request()->routeIs('employee.sales')" wire:navigate>
                            {{ __('Sales') }}
                        </flux:sidebar.item>
                    @endcan

                    @can('access-production')
                        <flux:sidebar.item icon="fire" :href="route('employee.production')" :current="request()->routeIs('employee.production')" wire:navigate>
                            {{ __('Production') }}
                        </flux:sidebar.item>
                    @endcan

                    @can('access-workforce')
                        <flux:sidebar.item icon="users" :href="route('employee.workforce')" :current="request()->routeIs('employee.workforce')" wire:navigate>
                            {{ __('Workforce') }}
                        </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
