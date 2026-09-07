<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Workforce')] class extends Component {
    //
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1">{{ __('Workforce') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Employee records, shift scheduling and assignments.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    <flux:callout icon="wrench-screwdriver">
        <flux:callout.heading>{{ __('Not built yet') }}</flux:callout.heading>
        <flux:callout.text>{{ __('This section arrives in Phase 8.') }}</flux:callout.text>
    </flux:callout>
</section>
