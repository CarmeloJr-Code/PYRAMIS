<?php

use App\Models\Outlet;
use App\Models\Shift;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Manage shift')] class extends Component {
    #[Locked]
    public ?int $shiftId = null;

    public ?int $outlet_id = null;

    public string $name = '';

    public string $date = '';

    public string $starts_at = '09:00';

    public string $ends_at = '17:00';

    public string $notes = '';

    /**
     * Mount the component for either a new or an existing shift.
     */
    public function mount(?Shift $shift = null): void
    {
        Gate::authorize('access-workforce');

        if ($shift?->exists) {
            $this->shiftId = $shift->id;
            $this->outlet_id = $shift->outlet_id;
            $this->name = $shift->name;
            $this->date = $shift->starts_at->toDateString();
            $this->starts_at = $shift->starts_at->format('H:i');
            $this->ends_at = $shift->ends_at->format('H:i');
            $this->notes = $shift->notes ?? '';

            return;
        }

        $this->date = now()->toDateString();
        $this->outlet_id = $this->outlets->first()?->id;
    }

    /**
     * The places a shift can be worked.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()
            ->active()
            ->orderByDesc('is_main_branch')
            ->orderBy('name')
            ->get();
    }

    /**
     * Persist the shift.
     */
    public function save(): void
    {
        Gate::authorize('access-workforce');

        $validated = $this->validate([
            'outlet_id' => [
                'required', 'integer',
                Rule::exists('outlets', 'id')->where('is_active', true),
            ],
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            // A shift that ends when it starts is not a shift, and one that ends
            // before it starts is a typo.
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $shift = $this->shiftId === null
            ? new Shift(['created_by' => Auth::id()])
            : Shift::findOrFail($this->shiftId);

        $shift->fill([
            'outlet_id' => $validated['outlet_id'],
            'name' => $validated['name'],
            'starts_at' => "{$validated['date']} {$validated['starts_at']}:00",
            'ends_at' => "{$validated['date']} {$validated['ends_at']}:00",
            'notes' => $validated['notes'] ?: null,
        ])->save();

        Flux::toast(variant: 'success', text: __('Shift saved.'));

        $this->redirectRoute('employee.workforce.shifts.show', $shift, navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">
        {{ $shiftId === null ? __('New shift') : __('Edit shift') }}
    </flux:heading>
    <flux:text class="mt-2">{{ __('A stretch of work at a place. Who covers it is set on the shift itself.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:input wire:model="name" :label="__('What it is')" placeholder="{{ __('Morning bake') }}" required autofocus />

        <flux:select wire:model="outlet_id" :label="__('Where')" required>
            <flux:select.option value="">{{ __('Choose a location') }}</flux:select.option>
            @foreach ($this->outlets as $outlet)
                <flux:select.option :value="$outlet->id">
                    {{ $outlet->name }}{{ $outlet->is_main_branch ? ' · '.__('Main branch') : '' }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="date" :label="__('Day')" type="date" required />

        <div class="flex flex-col gap-4 md:flex-row">
            <flux:input wire:model="starts_at" :label="__('From')" type="time" class="md:flex-1" required />
            <flux:input wire:model="ends_at" :label="__('To')" type="time" class="md:flex-1" required />
        </div>

        <flux:input wire:model="notes" :label="__('Note (optional)')" />

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Save shift') }}</flux:button>
            <flux:button variant="ghost" :href="route('employee.workforce')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
