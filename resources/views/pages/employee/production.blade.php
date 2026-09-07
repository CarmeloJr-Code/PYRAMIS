<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Production')] class extends Component {
    //
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">{{ __('Production') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Production planning, ingredient usage and outlet restock preparation.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    <flux:callout icon="wrench-screwdriver">
        <flux:callout.heading>{{ __('Not built yet') }}</flux:callout.heading>
        <flux:callout.text>{{ __('This section arrives in Phase 6.') }}</flux:callout.text>
    </flux:callout>
</section>
